<?php

namespace Z77\Module\Creditor\Services;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Repositories\CreditorIbanRepository;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Financial\Bank\BankLinkService;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Iban;

/**
 * The creditor invoice FINDS ITS BANK MOVEMENT (ADR-061 decisions 9–11, 13 — stage C1). Reads and
 * judgement live here; the writes are `CreditorInvoiceService::takeMovement()` /
 * `releaseMovement()`; the link itself is financial's (`BankLinkService`, owner key
 * `creditor:{id}`). No AI, nothing learned but the creditor's «Banktext».
 *
 * {@see find()} judges ONE invoice against the FREE debit movements (unlinked, on an active bank
 * account with «Kontoauszug einlesen» on, no reversal):
 *
 *   1. the invoice's QR / RF reference identical, the amount agreeing (CHF = the booked amount; a
 *      foreign-currency invoice = the bank's instructed amount and currency), exactly ONE such
 *      movement and no other unlinked invoice with the same reference and amount → IDENTITY;
 *   2. a CHF invoice: the booked amount equal, the booking date in the invoice's window, and the
 *      creditor in the entry — the counterparty IBAN is the invoice's or one of the creditor's,
 *      or the creditor's name or «Banktext» stands in the counterparty / text — exactly ONE →
 *      PROPOSAL;
 *   3. a foreign-currency invoice: the instructed amount and currency equal, the date in the
 *      window, the name or «Banktext» in the text (a card entry names no counterparty) — exactly
 *      ONE → PROPOSAL. The CHF amount then comes from the movement — what the bank debited, i.e.
 *      the effective `XchgRate`; the «Wechselkurs» in the entry text is never read;
 *   - anything else that fits by amount → CANDIDATES (nearest date first); a unique movement that
 *     another unlinked invoice of the same creditor could claim as well is a candidate, not a
 *     proposal. Never a guess.
 *
 * The WINDOW (`creditorConfig['bankMatch']`): unpaid — `daysBefore` before the invoice date to
 * `daysAfter` after the due date (each falling back on the other); paid by hand or posted —
 * `daysAroundPaid` around «Bezahlt am». An invoice without any date has no window: it gets
 * candidates only.
 *
 * WHERE IT RUNS: {@see linkIdentities()} in the job `creditor-intake` (every pass — one read when
 * nothing waits) and {@see linkIdentity()} after an invoice was saved; {@see pending()} on demand
 * for the window «Zahlungen suchen …». A web request never scans more than `SCAN_LIMIT` invoices.
 */
final class CreditorBankMatchService
{
    /** Candidates shown per invoice. */
    public const CANDIDATE_LIMIT = 8;
    /** Invoices one «Zahlungen suchen …» looks at (newest first) … */
    public const SCAN_LIMIT = 400;
    /** … and invoices it lists. */
    public const SHOW_LIMIT = 60;
    /** Free references one job pass compares. */
    private const REFERENCE_LIMIT = 2000;

    private const DEFAULT_WINDOW = ['daysBefore' => 3, 'daysAfter' => 45, 'daysAroundPaid' => 5];

    /** @var array{daysBefore: int, daysAfter: int, daysAroundPaid: int}|null */
    private ?array $days = null;

    /**
     * @param ?array $window harness seam: the three day counts instead of `creditorConfig['bankMatch']`
     */
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?string $actor = null, ?array $window = null)
    {
        if ($window !== null) {
            $this->days = self::cleanDays($window);
        }
    }

    /** Whether any bank account takes statements — without one the screens offer nothing. */
    public function available(): bool
    {
        return $this->links()->anyAccountReads();
    }

    public function linkOf(CreditorInvoice $invoice): ?BankLink
    {
        return $invoice->getId() === null ? null : $this->links()->ofOwner($invoice->bankOwnerKey());
    }

    /**
     * @param list<CreditorInvoice> $invoices
     * @return array<int, BankLink> invoice id → its link, only the linked ones
     */
    public function linksOf(array $invoices): array
    {
        $out = [];
        foreach ($this->links()->ofOwners(array_map(static fn(CreditorInvoice $i) => $i->bankOwnerKey(), $invoices)) as $key => $link) {
            $out[(int) substr($key, strlen(CreditorInvoice::SOURCE_TYPE) + 1)] = $link;
        }

        return $out;
    }

    /** What the bank holds for $invoice — see the class comment. Reads only; the invoice is assumed UNLINKED. */
    public function find(CreditorInvoice $invoice): CreditorBankMatch
    {
        if ($invoice->getId() === null || $invoice->isBlocked()) {
            return CreditorBankMatch::none();
        }
        $links     = $this->links();
        $reference = $invoice->getReference();
        if ($reference !== null) {
            $hits  = $links->freeMovements(BankMovement::DEBIT, references: [$reference], limit: self::CANDIDATE_LIMIT);
            $exact = array_values(array_filter($hits, fn(BankMovement $m) => self::amountFits($invoice, $m)));
            if (count($exact) === 1 && !$this->hasRival($invoice, $exact[0], true)) {
                return new CreditorBankMatch(CreditorBankMatch::IDENTITY, 1, $exact);
            }
            if ($hits !== []) {
                return new CreditorBankMatch(CreditorBankMatch::CANDIDATES, 1, $exact !== [] ? $exact : $hits);
            }
        }

        $foreign  = $invoice->isForeign();
        $original = $invoice->getOriginal();
        if ($foreign ? $original === null : $invoice->getAmount() === null) {
            return CreditorBankMatch::none();
        }
        $window = $this->window($invoice);
        $hits   = $foreign
            ? $links->freeMovements(BankMovement::DEBIT, originalAmount: $original->toDecimal(), originalCurrency: $original->currency, from: $window[0] ?? null, to: $window[1] ?? null, limit: self::CANDIDATE_LIMIT)
            : $links->freeMovements(BankMovement::DEBIT, amount: $invoice->getAmount(), from: $window[0] ?? null, to: $window[1] ?? null, limit: self::CANDIDATE_LIMIT);
        if ($hits === []) {
            return CreditorBankMatch::none();
        }
        $evidence = $this->evidence($invoice);
        $named    = array_values(array_filter($hits, static fn(BankMovement $m) => self::names($m, $evidence, !$foreign)));
        $level    = $foreign ? 3 : 2;
        if ($window !== null && count($named) === 1 && !$this->hasRival($invoice, $named[0], false)) {
            return new CreditorBankMatch(CreditorBankMatch::PROPOSAL, $level, $named);
        }

        return new CreditorBankMatch(CreditorBankMatch::CANDIDATES, $level, self::nearest($named !== [] ? $named : $hits, $invoice));
    }

    /**
     * Every unlinked invoice the bank holds something for — the window «Zahlungen suchen …»:
     * the open ones and the posted ones paid since the oldest free movement (ADR-061 decision 2:
     * a posted invoice is linked afterwards, without a posting). Identities and proposals first.
     *
     * @return array{rows: list<array{invoice: CreditorInvoice, match: CreditorBankMatch}>, more: bool}
     */
    public function pending(): array
    {
        $links = $this->links();
        $first = $links->anyAccountReads() ? $links->firstFreeBooking(BankMovement::DEBIT) : null;
        if ($first === null) {
            return ['rows' => [], 'more' => false];
        }
        $since    = $first->modify('-' . $this->days()['daysAroundPaid'] . ' days');
        $invoices = $this->invoices()->bankMatchable($since, self::SCAN_LIMIT + 1);
        $more     = count($invoices) > self::SCAN_LIMIT;
        $invoices = array_slice($invoices, 0, self::SCAN_LIMIT);
        $linked   = $links->linkedOwners(array_map(static fn(CreditorInvoice $i) => $i->bankOwnerKey(), $invoices));
        $sure     = [];
        $open     = [];
        foreach ($invoices as $invoice) {
            if (isset($linked[$invoice->bankOwnerKey()])) {
                continue;
            }
            $match = $this->find($invoice);
            if ($match->kind === CreditorBankMatch::NONE) {
                continue;
            }
            if (count($sure) + count($open) >= self::SHOW_LIMIT) {
                $more = true;
                break;
            }
            $match->proposed() !== null ? $sure[] = ['invoice' => $invoice, 'match' => $match] : $open[] = ['invoice' => $invoice, 'match' => $match];
        }

        return ['rows' => [...$sure, ...$open], 'more' => $more];
    }

    /**
     * The office takes movement $movementId for the invoice (the confirmed proposal, or a pick
     * among candidates). The basis is judged HERE, not taken from the form: the movement the
     * match stands for → `reference` / `proposal`; any other free movement → `manual` (and the
     * creditor learns its «Banktext» from it). The pair itself is re-checked by
     * `CreditorInvoiceService::takeMovement()`.
     *
     * @throws CreditorRefusedException CHANGED · BANK
     */
    public function take(int $id, int $version, int $movementId): CreditorInvoice
    {
        $invoice = $this->invoices()->find($id);
        $match   = $invoice instanceof CreditorInvoice && $this->linkOf($invoice) === null ? $this->find($invoice) : CreditorBankMatch::none();
        $basis   = BankLink::BASIS_MANUAL;
        if ((int) $match->proposed()?->getId() === $movementId) {
            $basis = $match->kind === CreditorBankMatch::IDENTITY ? BankLink::BASIS_REFERENCE : BankLink::BASIS_PROPOSAL;
        }

        return (new CreditorInvoiceService($this->em, $this->actor))->takeMovement($id, $version, $movementId, $basis, $basis === BankLink::BASIS_MANUAL);
    }

    /**
     * Level 1 for ONE invoice, right after it was saved: its reference is on a free movement →
     * linked. Quiet: null when nothing is certain or the take was refused.
     */
    public function linkIdentity(int $id): ?BankMovement
    {
        $invoice = $this->invoices()->find($id);
        if (!$invoice instanceof CreditorInvoice || $invoice->getReference() === null || !$this->links()->anyAccountReads() || $this->linkOf($invoice) !== null) {
            return null;
        }
        $match = $this->find($invoice);
        if ($match->kind !== CreditorBankMatch::IDENTITY) {
            return null;
        }
        $movement = $match->movements[0];
        try {
            (new CreditorInvoiceService($this->em, $this->actor))->takeMovement($id, $invoice->getVersion(), (int) $movement->getId(), BankLink::BASIS_REFERENCE);
        } catch (CreditorRefusedException | \Doctrine\DBAL\Exception) {
            return null;   // refused, or a lock held by someone else — the job's next pass takes it
        }

        return $movement;
    }

    /**
     * Level 1 for EVERY invoice (the job): the references of the free debit movements are
     * compared with the open and posted invoices; each identity is taken in its own unit of work.
     * Idle cost: one read. A refused take is noted and skipped.
     *
     * @param ?callable(): bool $hasTime asked between takes
     * @return array{linked: int, more: bool, notes: list<string>}
     */
    public function linkIdentities(?callable $hasTime = null): array
    {
        $out   = ['linked' => 0, 'more' => false, 'notes' => []];
        $links = $this->links();
        $refs  = $links->anyAccountReads() ? $links->freeReferences(BankMovement::DEBIT, self::REFERENCE_LIMIT) : [];
        if ($refs === []) {
            return $out;
        }
        $invoices = $this->invoices()->byReferences($refs);
        $linked   = $links->linkedOwners(array_map(static fn(CreditorInvoice $i) => $i->bankOwnerKey(), $invoices));
        // Judged first, written after: a refused take rolls back and replaces the EntityManager.
        $takes = [];
        foreach ($invoices as $invoice) {
            if (isset($linked[$invoice->bankOwnerKey()])) {
                continue;
            }
            $match = $this->find($invoice);
            if ($match->kind === CreditorBankMatch::IDENTITY) {
                $takes[] = ['id' => (int) $invoice->getId(), 'version' => $invoice->getVersion(), 'movement' => (int) $match->movements[0]->getId(), 'label' => $invoice->label(), 'date' => $match->movements[0]->getBookingDate()->format('d.m.Y')];
            }
        }
        $service = new CreditorInvoiceService($this->em, $this->actor);
        foreach ($takes as $take) {
            if ($hasTime !== null && !$hasTime()) {
                $out['more'] = true;
                break;
            }
            try {
                $service->takeMovement($take['id'], $take['version'], $take['movement'], BankLink::BASIS_REFERENCE);
                $out['linked']++;
                $out['notes'][] = $take['label'] . ': Bankzahlung vom ' . $take['date'] . ' zugeordnet (Referenz)';
            } catch (CreditorRefusedException $e) {
                $out['notes'][] = $take['label'] . ': Bankzahlung nicht zugeordnet — ' . $e->getMessage();
            }
        }

        return $out;
    }

    // ── judgement ────────────────────────────────────────────────────────

    /** The movement pays exactly the invoice's amount: CHF = booked; foreign = the bank's instructed amount and currency. */
    private static function amountFits(CreditorInvoice $invoice, BankMovement $movement): bool
    {
        $original = $invoice->getOriginal();
        if ($original !== null) {
            return $movement->getOriginalAmount() !== null && $movement->getOriginalCurrency() === $original->currency
                && Money::fromDecimal($movement->getOriginalAmount(), $original->currency)->equals($original);
        }

        return $invoice->getAmount() !== null && $invoice->getAmount()->equals($movement->getAmount());
    }

    /**
     * Whether another unlinked, unblocked invoice could claim $movement as well: by reference
     * (level 1) or of the same creditor / IBAN with the movement in its window (levels 2, 3) —
     * each with an agreeing amount.
     */
    private function hasRival(CreditorInvoice $invoice, BankMovement $movement, bool $byReference): bool
    {
        $rivals = array_values(array_filter(
            $this->invoices()->bankRivals((int) $invoice->getId(), $byReference ? $invoice->getReference() : null, $invoice->getCreditor()?->getId(), $invoice->getIban()),
            fn(CreditorInvoice $rival) => !$rival->isBlocked() && self::amountFits($rival, $movement) && ($byReference || $this->inWindow($rival, $movement))
        ));
        if ($rivals === []) {
            return false;
        }
        $linked = $this->links()->linkedOwners(array_map(static fn(CreditorInvoice $i) => $i->bankOwnerKey(), $rivals));

        return count($linked) < count($rivals);
    }

    private function inWindow(CreditorInvoice $invoice, BankMovement $movement): bool
    {
        $window = $this->window($invoice);

        return $window !== null && $movement->getBookingDate() >= $window[0] && $movement->getBookingDate() <= $window[1];
    }

    /**
     * The days a movement of $invoice is looked for in — [from, to], or null when the invoice
     * carries no date at all.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function window(CreditorInvoice $invoice): ?array
    {
        $days = $this->days();
        $paid = $invoice->getPaidOn();
        if ($paid !== null) {
            return [$paid->modify('-' . $days['daysAroundPaid'] . ' days')->setTime(0, 0), $paid->modify('+' . $days['daysAroundPaid'] . ' days')->setTime(0, 0)];
        }
        $from = $invoice->getInvoiceDate() ?? $invoice->getDueDate();
        $to   = $invoice->getDueDate() ?? $invoice->getInvoiceDate();
        if ($from === null || $to === null) {
            return null;
        }

        return [$from->modify('-' . $days['daysBefore'] . ' days')->setTime(0, 0), $to->modify('+' . $days['daysAfter'] . ' days')->setTime(0, 0)];
    }

    /** @return array{daysBefore: int, daysAfter: int, daysAroundPaid: int} `creditorConfig['bankMatch']`, read once */
    private function days(): array
    {
        return $this->days ??= self::cleanDays((array) (DI::getModuleManager()->getModuleConfig('creditor')?->get('bankMatch', []) ?? []));
    }

    /** @return array{daysBefore: int, daysAfter: int, daysAroundPaid: int} */
    private static function cleanDays(array $config): array
    {
        $days = [];
        foreach (self::DEFAULT_WINDOW as $key => $default) {
            $days[$key] = is_numeric($config[$key] ?? null) ? max(0, min(366, (int) $config[$key])) : $default;
        }

        return $days;
    }

    /**
     * What names the creditor in a bank entry: the IBANs (the invoice's, the creditor's active
     * ones) and the text needles (the «Banktext», the contact's name with and without its legal
     * form) — normalised.
     *
     * @return array{ibans: array<string, true>, needles: list<string>}
     */
    private function evidence(CreditorInvoice $invoice): array
    {
        $ibans   = [];
        $needles = [];
        if ($invoice->getIban() !== null) {
            $ibans[Iban::normalize($invoice->getIban())] = true;
        }
        $creditor = $invoice->getCreditor();
        if ($creditor !== null) {
            /** @var CreditorIbanRepository $rows */
            $rows = $this->em->getRepository(CreditorIban::class);
            foreach ($rows->forProfile($creditor) as $row) {
                if ($row->isActive()) {
                    $ibans[Iban::normalize($row->getIban())] = true;
                }
            }
            $bankText = self::normal((string) $creditor->getBankText());
            if (mb_strlen($bankText) >= 3) {
                $needles[] = $bankText;
            }
            $name = self::normal((string) $creditor->getContact()?->displayName());
            $bare = trim((string) preg_replace('/ (ag|gmbh|sa|sarl|sàrl|sagl|inc|ltd|llc|kg|ohg|co|corp|bv|ug|se|plc)$/u', '', $name));
            foreach (array_unique([$name, $bare]) as $needle) {
                if (mb_strlen($needle) >= 4) {
                    $needles[] = $needle;
                }
            }
        }

        return ['ibans' => $ibans, 'needles' => array_values(array_unique($needles))];
    }

    /**
     * Whether the entry names the creditor: its counterparty IBAN ($withIban — a CHF transfer) or
     * a needle in counterparty + text.
     *
     * @param array{ibans: array<string, true>, needles: list<string>} $evidence
     */
    private static function names(BankMovement $movement, array $evidence, bool $withIban): bool
    {
        if ($withIban && $movement->getCounterpartyIban() !== '' && isset($evidence['ibans'][Iban::normalize($movement->getCounterpartyIban())])) {
            return true;
        }
        $hay = self::normal($movement->getCounterpartyName() . ' ' . $movement->getText());
        foreach ($evidence['needles'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Lower case, everything but letters and digits to ONE space. */
    private static function normal(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /**
     * Candidates, the booking date nearest to the invoice's day first.
     *
     * @param list<BankMovement> $movements
     * @return list<BankMovement>
     */
    private static function nearest(array $movements, CreditorInvoice $invoice): array
    {
        $day = $invoice->getPaidOn() ?? $invoice->getDueDate() ?? $invoice->getInvoiceDate();
        if ($day !== null) {
            $at = $day->getTimestamp();
            usort($movements, static fn(BankMovement $a, BankMovement $b) => abs($a->getBookingDate()->getTimestamp() - $at) <=> abs($b->getBookingDate()->getTimestamp() - $at));
        }

        return $movements;
    }

    private function links(): BankLinkService
    {
        return new BankLinkService($this->em, $this->actor);
    }

    private function invoices(): CreditorInvoiceRepository
    {
        return $this->em->getRepository(CreditorInvoice::class);
    }
}
