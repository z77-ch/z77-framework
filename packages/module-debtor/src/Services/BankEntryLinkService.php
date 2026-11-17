<?php

namespace Z77\Module\Debtor\Services;

use Doctrine\DBAL\Exception as DBALException;
use Z77\Core\DI;
use Z77\Module\Debtor\Entities\BankMessage;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Repositories\BankMessageRepository;
use Z77\Module\Debtor\Repositories\BankTransactionRepository;
use Z77\Module\Financial\Bank\BankLinkService;
use Z77\Module\Financial\Bank\BankRefusedException;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The customer payments of one camt.054 ENTRY find their camt.053 COLLECTIVE CREDIT (ADR-061
 * decision 12, stage C3b). Financial owns the link (`BankLinkService`, the port of the owning
 * modules); this class is debtor's judgement and its unit of work. Nothing here books: the
 * payments are booked by `BankImportService::book()` as before (debtor stays the only path that
 * books a customer payment) — the link only says which bank movement brought them.
 *
 * The OWNER is the bank's entry, not a payment: one link per camt.053 movement over its full
 * amount, owner key `debtor:entry:{Ntry/AcctSvcrRef}` — every transaction with that
 * `bank_transaction.entry_ref`, the label «Zahlungseingang · n Zahlungen».
 *
 *   - LEVEL 1, automatic — the camt.054 entry's `AcctSvcrRef` equals the movement's entry
 *     reference AND the sum of the entry's transactions equals the movement's amount (one
 *     currency, the account's) AND their count equals the movement's `NbOfTxs`. Any
 *     disagreement is NEVER linked: the window «Bankabgleich …» lists it as «Abweichung».
 *     `Ntry/NtryRef` is never compared (a constant per QR-IBAN and participant number).
 *   - LEVEL 2, a proposal — transactions WITHOUT an entry reference (imported before
 *     2026-11-17, or an entry the bank sent without one), grouped per message and booking day,
 *     owner key `debtor:day:{message id}:{Ymd}`: a free credit booked THAT day with the same
 *     amount and count, carrying an entry reference no debtor transaction holds and no payer
 *     account (a transfer, salary or pension names one — those stay for stage C2's patterns).
 *     Exactly one such movement and no other group of the same day, amount and count → ticked
 *     in advance; else the candidates, nothing ticked. Never automatic; the office confirms.
 *     The transactions keep their NULL entry reference — the file's data is never rewritten.
 *   - RELEASE — only a level-2 link («Zuordnung lösen»): an identity cannot be wrong, and the
 *     next pass would set it again.
 *
 * WHERE IT RUNS (both orders occur in a year-end catch-up): {@see linkEntries()} right after a
 * camt.054 import commits (the camt.053 was read before) and in the job `debtor-bank-link`
 * (`Jobs\DebtorBankLinkJob` — the camt.053 arrives later; financial never calls debtor, its import
 * knows no owner, BANK-LINK-002); {@see overview()} / {@see takeDay()} / {@see release()} for the
 * window. Every write is its own unit of work: the message row(s) locked first (debtor's own
 * rows), then `BankLinkService::link()` locks the movement.
 *
 * Without module-financial (only `suggest`ed) nothing here runs: {@see available()} is false and
 * no financial class is touched.
 */
final class BankEntryLinkService
{
    public const OWNER_TYPE = 'debtor';
    private const ENTRY_PREFIX = 'debtor:entry:';
    private const DAY_PREFIX   = 'debtor:day:';

    /** Entry references one pass compares with the free movements. */
    private const REF_LIMIT = 2000;
    /** Day groups the window judges, and lists at most. */
    public const SCAN_LIMIT = 400;
    public const SHOW_LIMIT = 60;
    /** Candidates shown per day group. */
    private const CANDIDATE_LIMIT = 8;
    /** A camt.054 entry may be booked a few days before the oldest free movement's day — the scan starts that much earlier. */
    private const SINCE_MARGIN_DAYS = 7;

    public const MATCH_IDENTITY   = 'identity';
    public const MATCH_PROPOSAL   = 'proposal';
    public const MATCH_CANDIDATES = 'candidates';
    public const MATCH_PROBLEM    = 'problem';

    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?string $actor = null) {}

    /** Whether module-financial is installed AND registered — `class_exists` alone would lie (it autoloads unregistered). */
    public static function installed(): bool
    {
        return class_exists(BankLinkService::class) && DI::getModuleManager()->getModuleConfig('financial') !== null;
    }

    /** Whether there is anything to link to: financial present and a bank account reading camt.053. */
    public function available(): bool
    {
        return self::installed() && $this->links()->anyAccountReads();
    }

    public static function entryKey(string $entryRef): string
    {
        return self::ENTRY_PREFIX . $entryRef;
    }

    public static function dayKey(int $messageId, string $day): string
    {
        return self::DAY_PREFIX . $messageId . ':' . str_replace('-', '', $day);
    }

    /** What the list «Bewegungen» shows in «Zuordnung» — short (owner 2026-11-17). */
    public static function label(int $count): string
    {
        return 'Zahlungseingang · ' . ($count === 1 ? '1 Zahlung' : $count . ' Zahlungen');
    }

    // ── level 1 ──────────────────────────────────────────────────────────

    /**
     * Links every entry whose reference stands on exactly one free credit and AGREES with it
     * (sum, currency, count) — $entryRefs (a message just imported), or every entry booked since
     * shortly before the oldest free credit (the job). Judged first, written after: a refused take
     * rolls back and replaces the EntityManager. Idle cost: three reads. Quiet — a refusal is a
     * note, never an exception.
     *
     * @param list<string>|null $entryRefs
     * @param ?callable(): bool $hasTime asked between takes
     * @return array{linked: int, more: bool, notes: list<string>}
     */
    public function linkEntries(?array $entryRefs = null, ?callable $hasTime = null): array
    {
        $out = ['linked' => 0, 'more' => false, 'notes' => []];
        if ($entryRefs === [] || !$this->available()) {
            return $out;
        }
        if ($entryRefs === null) {
            $first = $this->links()->firstFreeBooking(BankMovement::CREDIT);
            if ($first === null) {
                return $out;
            }
            $entryRefs = $this->transactions()->entryRefsSince($first->modify('-' . self::SINCE_MARGIN_DAYS . ' days'), self::REF_LIMIT);
        }
        $takes = [];
        foreach ($this->entryMatches($entryRefs) as $row) {
            if ($row['match'] === self::MATCH_IDENTITY) {
                $takes[] = ['ref' => $row['ref'], 'movement' => $row['proposed'], 'date' => $row['movements'][0]['date']->format('d.m.Y'), 'count' => $row['count']];
            }
        }
        foreach ($takes as $take) {
            if ($hasTime !== null && !$hasTime()) {
                $out['more'] = true;
                break;
            }
            try {
                $this->takeEntry($take['ref'], $take['movement']);
                $out['linked']++;
                $out['notes'][] = 'Eintrag ' . $take['ref'] . ' (' . self::label($take['count']) . '): Bankbewegung vom ' . $take['date'] . ' zugeordnet (Referenz)';
            } catch (BankImportRefusedException $e) {
                $out['notes'][] = 'Eintrag ' . $take['ref'] . ': nicht zugeordnet — ' . $e->getMessage();
            } catch (DBALException $e) {
                if (!LockConflict::is($e)) {
                    throw $e;
                }
                $out['notes'][] = 'Eintrag ' . $take['ref'] . ': gleichzeitig bearbeitet — der nächste Lauf ordnet zu';
            }
        }

        return $out;
    }

    /**
     * Links the transactions of entry $entryRef to movement $movementId — an IDENTITY, judged
     * again here under the locks: the movement free, a credit, its entry reference $entryRef, its
     * amount the entry's sum in one currency, its `NbOfTxs` the entry's count.
     *
     * @throws BankImportRefusedException BANK_LINK | NOT_FOUND
     */
    public function takeEntry(string $entryRef, int $movementId): void
    {
        $messages = $this->transactions()->entryTotals([$entryRef])[$entryRef]['messages'] ?? [];
        if ($messages === []) {
            throw new BankImportRefusedException(BankImportRefusedException::NOT_FOUND, 'Keine Zahlung trägt den Eintrag ' . $entryRef . '.');
        }
        sort($messages);
        $this->unitOfWork(function () use ($entryRef, $movementId, $messages): void {
            foreach ($messages as $messageId) {
                $this->messages()->lockRow($messageId);   // debtor's own rows first, by id
            }
            $totals   = $this->transactions()->entryTotals([$entryRef])[$entryRef] ?? null;
            $movement = $this->links()->freeMovement($movementId);
            if ($totals === null || $movement === null || !$movement->isCredit() || $movement->getEntryRef() !== $entryRef) {
                throw new BankImportRefusedException(BankImportRefusedException::BANK_LINK, 'Die Bankbewegung ist nicht mehr frei oder gehört nicht zu diesem Eintrag — neu laden.');
            }
            $problem = self::disagreement($totals, $movement);
            if ($problem !== null) {
                throw new BankImportRefusedException(BankImportRefusedException::BANK_LINK, $problem . ' — nicht zugeordnet.');
            }
            $this->link($movementId, self::entryKey($entryRef), $totals['count'], BankLink::BASIS_REFERENCE);
        });
    }

    // ── level 2 ──────────────────────────────────────────────────────────

    /**
     * The office takes movement $movementId for the transactions WITHOUT an entry reference of
     * message $messageId on $day (`Y-m-d`). Judged again under the lock: a free credit of that day,
     * the group's sum (one currency) and count, an entry reference no debtor transaction holds, no
     * payer account. The basis is judged HERE: the unique proposal → `proposal`, a pick among
     * candidates → `manual`.
     *
     * @throws BankImportRefusedException BANK_LINK | NOT_FOUND
     */
    public function takeDay(int $messageId, string $day, int $movementId): void
    {
        $this->unitOfWork(function () use ($messageId, $day, $movementId): void {
            if (!$this->messages()->lockRow($messageId)) {
                throw new BankImportRefusedException(BankImportRefusedException::NOT_FOUND, "Meldung {$messageId} gibt es nicht.");
            }
            $group = $this->transactions()->dayGroups(null, 1, $messageId, $day)[0] ?? null;
            if ($group === null) {
                throw new BankImportRefusedException(BankImportRefusedException::NOT_FOUND, 'Diese Zahlungen gibt es nicht mehr — neu laden.');
            }
            $candidates = $this->dayCandidates($group);
            $ids        = array_map(static fn(BankMovement $m) => (int) $m->getId(), $candidates);
            if (!in_array($movementId, $ids, true)) {
                throw new BankImportRefusedException(BankImportRefusedException::BANK_LINK, 'Die Bankbewegung passt nicht (mehr) zu den Zahlungen vom ' . self::dayLabel($day) . ' — Tag, Betrag und Anzahl müssen stimmen.');
            }
            $basis = count($candidates) === 1 && !$this->dayRival($group) ? BankLink::BASIS_PROPOSAL : BankLink::BASIS_MANUAL;
            $this->link($movementId, self::dayKey($messageId, $day), $group['count'], $basis);
        });
    }

    /**
     * «Zuordnung lösen» — only a level-2 link (`debtor:day:…`). In its own unit of work, the
     * message row locked first.
     *
     * @throws BankImportRefusedException BANK_LINK
     */
    public function release(string $ownerKey): void
    {
        if (!preg_match('/^' . preg_quote(self::DAY_PREFIX, '/') . '(\d{1,10}):(\d{8})$/', $ownerKey, $m)) {
            throw new BankImportRefusedException(BankImportRefusedException::BANK_LINK, 'Eine Zuordnung über die Referenz der Bank wird nicht gelöst — sie stimmt.');
        }
        $this->unitOfWork(function () use ($ownerKey, $m): void {
            $this->messages()->lockRow((int) $m[1]);
            if (!$this->links()->release($ownerKey)) {
                throw new BankImportRefusedException(BankImportRefusedException::BANK_LINK, 'Diese Zahlungen sind keiner Bankbewegung (mehr) zugeordnet.');
            }
        });
    }

    // ── the window ───────────────────────────────────────────────────────

    /**
     * What the window «Bankabgleich …» shows: the entries whose reference stands on a free credit
     * (identities ticked, disagreements as «Abweichung» — never linkable), the groups without an
     * entry reference with their proposal or candidates, and the confirmed level-2 links (to
     * release). Reads only.
     *
     * @return array{rows: list<array<string, mixed>>, confirmed: list<array<string, mixed>>, more: bool}
     */
    public function overview(): array
    {
        $out = ['rows' => [], 'confirmed' => [], 'more' => false];
        if (!$this->available()) {
            return $out;
        }
        $out['confirmed'] = $this->confirmed();
        $first = $this->links()->firstFreeBooking(BankMovement::CREDIT);
        if ($first === null) {
            return $out;
        }
        $since = $first->modify('-' . self::SINCE_MARGIN_DAYS . ' days');
        $rows  = $this->entryMatches($this->transactions()->entryRefsSince($since, self::REF_LIMIT));

        $groups = $this->transactions()->dayGroups($since, self::SCAN_LIMIT + 1);
        $out['more'] = count($groups) > self::SCAN_LIMIT;
        $groups = array_slice($groups, 0, self::SCAN_LIMIT);
        $linked = $this->links()->linkedOwners(array_map(static fn(array $g) => self::dayKey($g['message'], $g['day']), $groups));
        $sure   = [];
        $open   = [];
        foreach ($groups as $group) {
            if (isset($linked[self::dayKey($group['message'], $group['day'])])) {
                continue;
            }
            $candidates = $this->dayCandidates($group);
            if ($candidates === []) {
                continue;
            }
            $unique = count($candidates) === 1 && !$this->dayRival($group);
            $row    = [
                'kind'      => 'day',
                'key'       => self::dayKey($group['message'], $group['day']),
                'message'   => $group['message'],
                'day'       => $group['day'],
                'title'     => 'Meldung ' . ($this->messages()->find($group['message'])?->getMessageId() ?? $group['message']) . ' · ' . self::dayLabel($group['day']),
                'count'     => $group['count'],
                'amount'    => Money::fromDecimal($group['amount'], $group['currency']),
                'names'     => $group['names'],
                'match'     => $unique ? self::MATCH_PROPOSAL : self::MATCH_CANDIDATES,
                'movements' => array_map(self::movementRow(...), array_slice($candidates, 0, self::CANDIDATE_LIMIT)),
                'proposed'  => $unique ? (int) $candidates[0]->getId() : null,
                'note'      => '',
            ];
            $unique ? $sure[] = $row : $open[] = $row;
        }
        $all = [...array_values(array_filter($rows, static fn(array $r) => $r['match'] === self::MATCH_IDENTITY)), ...$sure,
            ...array_values(array_filter($rows, static fn(array $r) => $r['match'] === self::MATCH_PROBLEM)), ...$open];
        if (count($all) > self::SHOW_LIMIT) {
            $out['more'] = true;
            $all         = array_slice($all, 0, self::SHOW_LIMIT);
        }
        $out['rows'] = $all;

        return $out;
    }

    /**
     * The confirmed level-2 links — what «Zuordnung lösen» applies to.
     *
     * @return list<array{key: string, title: string, label: string, date: \DateTimeImmutable, amount: Money, basis: string}>
     */
    private function confirmed(): array
    {
        $groups = $this->transactions()->dayGroups(null, self::SCAN_LIMIT);
        $byKey  = [];
        foreach ($groups as $group) {
            $byKey[self::dayKey($group['message'], $group['day'])] = $group;
        }
        $out = [];
        foreach ($this->links()->ofOwners(array_keys($byKey)) as $key => $link) {
            $group = $byKey[$key];
            $out[] = [
                'key'    => $key,
                'title'  => 'Meldung ' . ($this->messages()->find($group['message'])?->getMessageId() ?? $group['message']) . ' · ' . self::dayLabel($group['day']),
                'label'  => $link->getOwnerLabel(),
                'date'   => $link->getMovement()->getBookingDate(),
                'amount' => $link->getAmount(),
                'basis'  => $link->getBasis(),
            ];
        }

        return $out;
    }

    // ── judgement ────────────────────────────────────────────────────────

    /**
     * Every entry of $entryRefs that has a free credit with its reference — an IDENTITY when they
     * agree, a PROBLEM otherwise (also two movements with one reference).
     *
     * @param list<string> $entryRefs
     * @return list<array<string, mixed>>
     */
    private function entryMatches(array $entryRefs): array
    {
        $byRef = [];
        foreach ($this->links()->freeMovementsByEntryRefs(BankMovement::CREDIT, $entryRefs) as $movement) {
            $byRef[(string) $movement->getEntryRef()][] = $movement;
        }
        if ($byRef === []) {
            return [];
        }
        $totals = $this->transactions()->entryTotals(array_map('strval', array_keys($byRef)));
        $rows   = [];
        foreach ($byRef as $ref => $movements) {
            $ref = (string) $ref;
            if (!isset($totals[$ref])) {
                continue;
            }
            $total   = $totals[$ref];
            $problem = count($movements) > 1 ? 'Die Bank führt ' . count($movements) . ' Bewegungen mit dieser Referenz' : self::disagreement($total, $movements[0]);
            $rows[]  = [
                'kind'      => 'entry',
                'key'       => self::entryKey($ref),
                'ref'       => $ref,
                'title'     => 'Eintrag ' . $ref . ' · ' . self::dayLabel($total['day']),
                'count'     => $total['count'],
                'amount'    => $total['mixed'] ? null : Money::fromDecimal($total['amount'], $total['currency']),
                'names'     => $total['names'],
                'match'     => $problem === null ? self::MATCH_IDENTITY : self::MATCH_PROBLEM,
                'movements' => array_map(self::movementRow(...), $movements),
                'proposed'  => $problem === null ? (int) $movements[0]->getId() : null,
                'note'      => (string) $problem,
            ];
        }

        return $rows;
    }

    /**
     * Why an entry's transactions and the movement do NOT agree — null when they do (one currency,
     * the account's; the sum equal; the count equal to the bank's `NbOfTxs`).
     *
     * @param array{count: int, amount: string, currency: string, mixed: bool} $totals
     */
    private static function disagreement(array $totals, BankMovement $movement): ?string
    {
        if ($totals['mixed'] || $totals['currency'] !== $movement->getCurrency()) {
            return 'Die Zahlungen lauten nicht alle auf ' . $movement->getCurrency();
        }
        $sum = Money::fromDecimal($totals['amount'], $totals['currency']);
        if (!$sum->equals($movement->getAmount()) || $totals['count'] !== $movement->getTxCount()) {
            return 'Bank ' . $movement->getAmount()->toDecimal() . ' · ' . self::payments($movement->getTxCount())
                . ', eingelesen ' . $sum->toDecimal() . ' · ' . self::payments($totals['count']);
        }

        return null;
    }

    /**
     * The free credits a day group may be: booked that day, the group's sum and count, an entry
     * reference no debtor transaction holds, no payer account.
     *
     * @param array{count: int, amount: string, currency: string, mixed: bool, day: string} $group
     * @return list<BankMovement>
     */
    private function dayCandidates(array $group): array
    {
        if ($group['mixed'] || $group['count'] < 1) {
            return [];
        }
        $amount = Money::fromDecimal($group['amount'], $group['currency']);
        if (!$amount->isPositive()) {
            return [];
        }
        $day   = new \DateTimeImmutable($group['day']);
        $found = array_values(array_filter(
            $this->links()->freeMovements(BankMovement::CREDIT, amount: $amount, from: $day, to: $day, limit: 50),
            static fn(BankMovement $m) => $m->getCurrency() === $group['currency'] && $m->getTxCount() === $group['count']
                && $m->getEntryRef() !== null && $m->getEntryRef() !== '' && $m->getCounterpartyIban() === ''
        ));
        if ($found === []) {
            return [];
        }
        $held = $this->transactions()->entryTotals(array_map(static fn(BankMovement $m) => (string) $m->getEntryRef(), $found));

        return array_values(array_filter($found, static fn(BankMovement $m) => !isset($held[(string) $m->getEntryRef()])));
    }

    /** Whether another group without an entry reference has the same day, sum and count — then nothing is proposed. */
    private function dayRival(array $group): bool
    {
        foreach ($this->transactions()->dayGroups(new \DateTimeImmutable($group['day']), self::SCAN_LIMIT, null, $group['day']) as $other) {
            if ($other['message'] !== $group['message'] && $other['count'] === $group['count'] && !$other['mixed']
                && $other['currency'] === $group['currency'] && Money::fromDecimal($other['amount'], $other['currency'])->equals(Money::fromDecimal($group['amount'], $group['currency']))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{id: int, date: \DateTimeImmutable, amount: Money, count: int, text: string, ref: string} plain data for the screen */
    private static function movementRow(BankMovement $movement): array
    {
        $party = $movement->getCounterpartyName();
        $text  = trim((string) preg_replace('/\s+/u', ' ', $movement->getText()));

        return [
            'id'     => (int) $movement->getId(),
            'date'   => $movement->getBookingDate(),
            'amount' => $movement->getAmount(),
            'count'  => $movement->getTxCount(),
            'text'   => $party !== '' ? $party . ($text !== '' ? ' · ' . $text : '') : $text,
            'ref'    => (string) $movement->getEntryRef(),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @throws BankImportRefusedException BANK_LINK — financial's refusal (taken, gone, amount) in its own German words */
    private function link(int $movementId, string $ownerKey, int $count, string $basis): void
    {
        try {
            $this->links()->link($movementId, self::OWNER_TYPE, $ownerKey, self::label($count), $basis);
        } catch (BankRefusedException $e) {
            throw new BankImportRefusedException(BankImportRefusedException::BANK_LINK, $e->getMessage(), $e);
        }
    }

    private function unitOfWork(callable $work): void
    {
        $this->em->getTransaction(BankMessage::class)->run($work);
    }

    private static function payments(int $count): string
    {
        return $count === 1 ? '1 Zahlung' : $count . ' Zahlungen';
    }

    private static function dayLabel(string $day): string
    {
        return (new \DateTimeImmutable($day))->format('d.m.Y');
    }

    private function links(): BankLinkService
    {
        return new BankLinkService($this->em, $this->actor);
    }

    private function transactions(): BankTransactionRepository
    {
        return $this->em->getRepository(BankTransaction::class);
    }

    private function messages(): BankMessageRepository
    {
        return $this->em->getRepository(BankMessage::class);
    }
}
