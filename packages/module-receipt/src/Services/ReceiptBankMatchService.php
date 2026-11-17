<?php

namespace Z77\Module\Receipt\Services;

use Z77\Core\DI;
use Z77\Module\Financial\Bank\BankAccountService;
use Z77\Module\Financial\Bank\BankLinkService;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Repositories\ReceiptRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The receipt FINDS ITS BANK MOVEMENT (ADR-061 decisions 9–11, stage C5) — the owner's «bei Bank
 * oder Karte müsste der Bankeintrag gefunden werden und er wird geschäftlich». Reads and judgement
 * live here; the writes are `ReceiptJournalService::takeMovement()` / `releaseMovement()`; the
 * link itself is financial's (`BankLinkService`, owner key `receipt:{id}`). Built after
 * module-creditor's `CreditorBankMatchService` — its pattern, not its classes (the shared block is
 * pending, receipt.md).
 *
 * WHICH BANK ACCOUNT ({@see bankAccountsOf()}): the one whose ledger account the receipt's «Haben»
 * is — that is where the posting lands, whether the payment method put it there or the office
 * typed it; the payment method's own bank account decides between two bank accounts on one ledger
 * account and stands in while «Haben» is still empty. A receipt paid from the cash box or
 * «privat ausgelegt» has none and is never matched.
 *
 * {@see find()} judges ONE receipt against the FREE debit movements of that bank account
 * (unlinked, no reversal, the account active with «Kontoauszug einlesen» on):
 *
 *   2. the booked amount equal and the booking date in the receipt's window: exactly ONE such
 *      movement — or several, of which exactly one names a word of the receipt's text (the
 *      merchant) — and no other unlinked receipt could claim it → PROPOSAL; else CANDIDATES;
 *   3. a captured receipt nothing fits in CHF: a movement whose ORIGINAL amount (one of the
 *      released foreign currencies, `ReceiptFields::foreignCurrencies()`) equals the receipt's
 *      amount → CANDIDATES, never ticked — a receipt names no currency (its FX build is pending),
 *      so the likeness is the number alone. Taken, the receipt's amount becomes the movement's
 *      CHF: what the bank debited, i.e. the effective `XchgRate`.
 *
 * There is NO level 1: a receipt carries no reference, so nothing is linked without the office,
 * and no job runs. The WINDOW (`receiptConfig['bankMatch']`): `daysBefore` before the receipt
 * date to `daysAfter` after it (a card purchase is booked days after the slip).
 */
final class ReceiptBankMatchService
{
    /** Candidates shown per receipt. */
    public const CANDIDATE_LIMIT = 8;
    /** Receipts one «Zahlungen suchen …» looks at (newest first) … */
    public const SCAN_LIMIT = 400;
    /** … and receipts it lists. */
    public const SHOW_LIMIT = 60;
    /** Free movements read per question — they are narrowed to the receipt's bank account afterwards. */
    private const FETCH_LIMIT = 40;

    private const DEFAULT_WINDOW = ['daysBefore' => 2, 'daysAfter' => 10];

    /** @var array{daysBefore: int, daysAfter: int}|null */
    private ?array $days = null;
    /** @var array<string, list<string>>|null ledger account → the codes of the bank accounts on it */
    private ?array $byLedger = null;
    /** @var array<string, string>|null payment method code → its bank account code (only the bound ones) */
    private ?array $methodBank = null;

    /**
     * @param ?array $window harness seam: the two day counts instead of `receiptConfig['bankMatch']`
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

    public function linkOf(Receipt $receipt): ?BankLink
    {
        return $receipt->getId() === null ? null : $this->links()->ofOwner($receipt->bankOwnerKey());
    }

    /**
     * @param list<Receipt> $receipts
     * @return array<int, BankLink> receipt id → its link, only the linked ones
     */
    public function linksOf(array $receipts): array
    {
        $out = [];
        foreach ($this->links()->ofOwners(array_map(static fn(Receipt $r) => $r->bankOwnerKey(), $receipts)) as $key => $link) {
            $out[(int) substr($key, strlen(Receipt::SOURCE_TYPE) + 1)] = $link;
        }

        return $out;
    }

    /**
     * The codes of the bank accounts $receipt was paid through — see the class comment; empty =
     * not paid through a bank account.
     *
     * @return list<string>
     */
    public function bankAccountsOf(Receipt $receipt): array
    {
        $this->loadAccounts();
        $credit   = $receipt->getCreditAccount();
        $byLedger = $credit === null ? [] : ($this->byLedger[$credit] ?? []);
        $bound    = $this->methodBank[(string) $receipt->getPaymentMethodCode()] ?? '';
        if ($bound !== '' && ($credit === null || in_array($bound, $byLedger, true))) {
            return [$bound];
        }

        return $byLedger;
    }

    /** What the bank holds for $receipt — see the class comment. Reads only; the receipt is assumed UNLINKED. */
    public function find(Receipt $receipt): ReceiptBankMatch
    {
        $codes = $receipt->getId() === null ? [] : $this->bankAccountsOf($receipt);
        if ($codes === []) {
            return ReceiptBankMatch::none();
        }
        $links     = $this->links();
        [$from, $to] = $this->window($receipt);
        $onAccount = static fn(BankMovement $m): bool => in_array($m->getBankAccount()->getCode(), $codes, true);
        $hits      = array_values(array_filter($links->freeMovements(BankMovement::DEBIT, amount: $receipt->getAmount(), from: $from, to: $to, limit: self::FETCH_LIMIT), $onAccount));
        if ($hits !== []) {
            $needles = self::needles($receipt->getText());
            $named   = array_values(array_filter($hits, static fn(BankMovement $m) => self::names($m, $needles)));
            $one     = count($hits) === 1 ? $hits[0] : (count($named) === 1 ? $named[0] : null);
            if ($one !== null && !$this->hasRival($receipt, $one)) {
                return new ReceiptBankMatch(ReceiptBankMatch::PROPOSAL, 2, [$one]);
            }
            $others = array_values(array_filter($hits, static fn(BankMovement $m) => !in_array($m, $named, true)));

            return new ReceiptBankMatch(ReceiptBankMatch::CANDIDATES, 2, array_slice([...self::nearest($named, $receipt), ...self::nearest($others, $receipt)], 0, self::CANDIDATE_LIMIT));
        }
        if (!$receipt->isCaptured()) {
            return ReceiptBankMatch::none();   // a posted amount is CHF for good
        }
        $foreign = [];
        foreach (ReceiptFields::foreignCurrencies() as $currency) {
            array_push($foreign, ...array_filter(
                $links->freeMovements(BankMovement::DEBIT, originalAmount: $receipt->getAmount()->toDecimal(), originalCurrency: $currency, from: $from, to: $to, limit: self::FETCH_LIMIT),
                $onAccount
            ));
        }

        return $foreign === [] ? ReceiptBankMatch::none() : new ReceiptBankMatch(ReceiptBankMatch::CANDIDATES, 3, array_slice(self::nearest($foreign, $receipt), 0, self::CANDIDATE_LIMIT));
    }

    /**
     * Every unlinked receipt the bank holds something for — the window «Zahlungen suchen …»: the
     * captured and the posted ones (ADR-061 decision 2: a posted receipt is linked afterwards,
     * without a posting) dated since the oldest free movement. Proposals first.
     *
     * @return array{rows: list<array{receipt: Receipt, match: ReceiptBankMatch}>, more: bool}
     */
    public function pending(): array
    {
        $links = $this->links();
        $first = $links->anyAccountReads() ? $links->firstFreeBooking(BankMovement::DEBIT) : null;
        $this->loadAccounts();
        if ($first === null || $this->byLedger === []) {
            return ['rows' => [], 'more' => false];
        }
        $since    = $first->modify('-' . $this->days()['daysAfter'] . ' days');
        $receipts = $this->receipts()->bankMatchable(array_map('strval', array_keys($this->byLedger)), array_map('strval', array_keys($this->methodBank)), $since, self::SCAN_LIMIT + 1);
        $more     = count($receipts) > self::SCAN_LIMIT;
        $receipts = array_slice($receipts, 0, self::SCAN_LIMIT);
        $linked   = $links->linkedOwners(array_map(static fn(Receipt $r) => $r->bankOwnerKey(), $receipts));
        $sure     = [];
        $open     = [];
        foreach ($receipts as $receipt) {
            if (isset($linked[$receipt->bankOwnerKey()])) {
                continue;
            }
            $match = $this->find($receipt);
            if ($match->kind === ReceiptBankMatch::NONE) {
                continue;
            }
            if (count($sure) + count($open) >= self::SHOW_LIMIT) {
                $more = true;
                break;
            }
            $match->proposed() !== null ? $sure[] = ['receipt' => $receipt, 'match' => $match] : $open[] = ['receipt' => $receipt, 'match' => $match];
        }

        return ['rows' => [...$sure, ...$open], 'more' => $more];
    }

    /**
     * The office takes movement $movementId for the receipt (the confirmed proposal, or a pick
     * among candidates). The basis is judged HERE, not taken from the form: the movement the
     * proposal stands for → `proposal`; any other free movement → `manual`. The pair itself is
     * re-checked by `ReceiptJournalService::takeMovement()`.
     *
     * @throws ReceiptRefusedException CHANGED · BANK
     */
    public function take(int $id, int $version, int $movementId): Receipt
    {
        $receipt = $this->receipts()->find($id);
        $match   = $receipt instanceof Receipt && $this->linkOf($receipt) === null ? $this->find($receipt) : ReceiptBankMatch::none();
        $basis   = (int) $match->proposed()?->getId() === $movementId ? BankLink::BASIS_PROPOSAL : BankLink::BASIS_MANUAL;

        return (new ReceiptJournalService($this->em, $this->actor))->takeMovement($id, $version, $movementId, $basis);
    }

    // ── judgement ────────────────────────────────────────────────────────

    /**
     * Whether another unlinked receipt could claim $movement as well: the same amount, paid
     * through the movement's bank account, the movement in its window.
     */
    private function hasRival(Receipt $receipt, BankMovement $movement): bool
    {
        $days   = $this->days();
        $booked = $movement->getBookingDate();
        $code   = $movement->getBankAccount()->getCode();
        $rivals = array_values(array_filter(
            $this->receipts()->bankRivals((int) $receipt->getId(), $movement->getAmount()->toDecimal(), $booked->modify('-' . $days['daysAfter'] . ' days'), $booked->modify('+' . $days['daysBefore'] . ' days')),
            fn(Receipt $rival) => in_array($code, $this->bankAccountsOf($rival), true)
        ));
        if ($rivals === []) {
            return false;
        }
        $linked = $this->links()->linkedOwners(array_map(static fn(Receipt $r) => $r->bankOwnerKey(), $rivals));

        return count($linked) < count($rivals);
    }

    /**
     * The days a movement of $receipt is looked for in — [from, to].
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function window(Receipt $receipt): array
    {
        $days = $this->days();
        $date = $receipt->getReceiptDate()->setTime(0, 0);

        return [$date->modify('-' . $days['daysBefore'] . ' days'), $date->modify('+' . $days['daysAfter'] . ' days')];
    }

    /** @return array{daysBefore: int, daysAfter: int} `receiptConfig['bankMatch']`, read once */
    private function days(): array
    {
        return $this->days ??= self::cleanDays((array) (DI::getModuleManager()->getModuleConfig('receipt')?->get('bankMatch', []) ?? []));
    }

    /** @return array{daysBefore: int, daysAfter: int} */
    private static function cleanDays(array $config): array
    {
        $days = [];
        foreach (self::DEFAULT_WINDOW as $key => $default) {
            $days[$key] = is_numeric($config[$key] ?? null) ? max(0, min(366, (int) $config[$key])) : $default;
        }

        return $days;
    }

    /** The bank accounts by ledger account and the bank-bound payment methods — one read each per instance. */
    private function loadAccounts(): void
    {
        if ($this->byLedger !== null) {
            return;
        }
        $this->byLedger = [];
        foreach ((new BankAccountService($this->em))->all() as $account) {
            $this->byLedger[$account->getLedgerAccount()][] = $account->getCode();
        }
        $this->methodBank = [];
        foreach ((new PaymentMethodService($this->em))->all() as $method) {
            if ($method->getBankAccountCode() !== '') {
                $this->methodBank[$method->getCode()] = $method->getBankAccountCode();
            }
        }
    }

    /**
     * What names the receipt in a bank entry: the words of its text (the merchant the office or
     * the phone typed), four characters and more, normalised.
     *
     * @return list<string>
     */
    private static function needles(string $text): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', self::normal($text)),
            static fn(string $word) => mb_strlen($word) >= 4 && !ctype_digit($word)
        )));
    }

    /** @param list<string> $needles */
    private static function names(BankMovement $movement, array $needles): bool
    {
        $hay = ' ' . self::normal($movement->getCounterpartyName() . ' ' . $movement->getText()) . ' ';
        foreach ($needles as $needle) {
            if (str_contains($hay, ' ' . $needle . ' ')) {
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
     * The booking date nearest to the receipt date first.
     *
     * @param list<BankMovement> $movements
     * @return list<BankMovement>
     */
    private static function nearest(array $movements, Receipt $receipt): array
    {
        $at = $receipt->getReceiptDate()->getTimestamp();
        usort($movements, static fn(BankMovement $a, BankMovement $b) => abs($a->getBookingDate()->getTimestamp() - $at) <=> abs($b->getBookingDate()->getTimestamp() - $at));

        return $movements;
    }

    private function links(): BankLinkService
    {
        return new BankLinkService($this->em, $this->actor);
    }

    private function receipts(): ReceiptRepository
    {
        return $this->em->getRepository(Receipt::class);
    }
}
