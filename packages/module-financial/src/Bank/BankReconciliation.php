<?php

namespace Z77\Module\Financial\Bank;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Entities\BankAnchor;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Financial\Entities\BankPosting;
use Z77\Module\Financial\Entities\BankStatement;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The RECONCILIATION of a bank-driven bank account with its ledger account (ADR-061 decisions
 * 22–26, stage C4) — the anchor, the check, and the two figures the close asks for. Everything
 * here is COMPUTED from the stored facts (movements, links, marks, patterns, journal lines); the
 * only thing written is the anchor the office confirms.
 *
 * A bank account is BANK-DRIVEN while «Kontoauszug einlesen» is on and «Abgleich ab» is set
 * (`BankAccount::isBankDriven()`), from that day on. Nothing here looks at a movement before it,
 * or at an account that is switched off — switching off stops the check, links and postings stay.
 *
 * THE ANCHOR ({@see anchor()}, {@see confirm()}): private movements are never booked, so the
 * bank's balance and the ledger account's balance differ by the private history. At «Abgleich ab»
 * the office confirms «Bank X · Fibu Y · Differenz Z» once; the difference is stored
 * (`BankAnchor`). From then on it moves only by the private movements, so at any later day the
 * difference is COMPUTED: anchor + private net since. At a year change that computed figure is
 * compared with the real one (the bank's balance and the new year's opening on the ledger
 * account): equal → nothing to do; a mismatch → the office confirms again (decision 24). A purely
 * business account (not «gemischt geführt», owner 2026-11-19) has the anchor 0.00 WITHOUT a row and
 * the same rule — but nothing is ever confirmed on it: a difference is an error to correct by a
 * booking ({@see confirm()} refuses `not-mixed`). Rows confirmed before that decision stay and count.
 *
 * THE CHECK ({@see check()} → {@see BankCheck}) compares changes over a period: the change of the
 * ledger account (the year opening left out) = Σ of the «geschäftlich» movements. Which posting
 * belongs to which movement is joined through the link's owner key — financial's own direct
 * postings by their idempotency key, every other module's through {@see BankEntryOwners}.
 *
 * {@see transferBalance()} — the transfer clearing account must stand at zero (a missing side of
 * a transfer between two own business accounts); {@see taxWithoutVoucher()} — the control line of
 * the VAT return. Both accounts come from the mandator record, nothing is assumed.
 */
final class BankReconciliation
{
    /** The anchor of the day is confirmed and still true. */
    public const ANCHOR_CONFIRMED = 'confirmed';
    /** No anchor confirmed for the day, but the computed one (the last confirmed + private net) agrees — nothing to do. */
    public const ANCHOR_COMPUTED = 'computed';
    /** Nothing confirmed yet: «Bank X · Fibu Y · Differenz Z — bestätigen?». */
    public const ANCHOR_OPEN = 'open';
    /** The computed anchor and the real difference disagree — confirm again. */
    public const ANCHOR_MISMATCH = 'mismatch';
    /** The statement does not reach the day — the bank's balance is not known. */
    public const ANCHOR_UNREAD = 'unread';

    /** How many postings without a movement a check carries for the window. */
    public const LISTED = 50;

    private ?string $actor;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null, private readonly ?BankEntryOwners $owners = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    /** @return list<BankAccount> the ACTIVE bank-driven bank accounts, by label */
    public function drivenAccounts(): array
    {
        return array_values(array_filter(
            $this->em->getRepository(BankAccount::class)->allInOrder(),
            static fn(BankAccount $a): bool => $a->isActive() && $a->isBankDriven()
        ));
    }

    // ── the anchor ───────────────────────────────────────────────────────

    /**
     * The anchor of $account at the opening of $day — «Abgleich ab» when $day is null or earlier.
     *
     * @return array{state: string, asOf: \DateTimeImmutable, bank: ?Money, ledger: Money, difference: ?Money, expected: ?Money, anchor: ?BankAnchor, reason: string}|null
     *         null = the account is not bank-driven. `asOf` is the day the figures describe (and {@see confirm()} takes);
     *         `expected` the computed difference (null while nothing is confirmed on a mixed account; 0.00 on an
     *         account that is not «gemischt geführt» and has no confirmed row — it is never `open`); `anchor` the confirmed row the
     *         computation starts from; `reason` a German sentence for {@see ANCHOR_UNREAD}.
     */
    public function anchor(BankAccount $account, ?\DateTimeImmutable $day = null): ?array
    {
        $base = $account->getReconcileFrom();
        if (!$account->isBankDriven() || $base === null) {
            return null;
        }
        $day    = $day !== null && $day->format('Y-m-d') > $base->format('Y-m-d') ? $day->setTime(0, 0) : $base;
        $anchor = null;
        foreach ($this->em->getRepository(BankAnchor::class)->findBy(['bankAccount' => $account->getId()], ['asOf' => 'ASC']) as $row) {
            if ($row->getAsOf()->format('Y-m-d') >= $base->format('Y-m-d') && $row->getAsOf()->format('Y-m-d') <= $day->format('Y-m-d')) {
                $anchor = $row;   // the newest confirmed one up to the day
            }
        }
        if ($anchor === null) {
            $day = $base;         // the first anchor is the one of «Abgleich ab»
        }
        $ledger = $this->ledgerAtOpeningOf($account, $day);
        $bank   = $this->bankAtOpeningOf($account, $day, $reason);
        $result = ['state' => self::ANCHOR_UNREAD, 'asOf' => $day, 'bank' => $bank, 'ledger' => $ledger, 'difference' => null, 'expected' => null, 'anchor' => $anchor, 'reason' => (string) $reason];
        if ($bank === null) {
            return $result;
        }
        $difference = $bank->subtract($ledger);
        $result     = ['difference' => $difference, 'reason' => ''] + $result;
        if ($anchor === null && !$account->isMixedUse()) {
            // A purely business account (owner 2026-11-19): nothing private was ever left out of the books, so the
            // difference must be 0.00 — it agrees, or it is an error to correct; there is nothing to confirm.
            $expected = Money::zero($account->getCurrency());

            return ['state' => $expected->equals($difference) ? self::ANCHOR_COMPUTED : self::ANCHOR_MISMATCH, 'expected' => $expected] + $result;
        }
        if ($anchor === null) {
            return ['state' => self::ANCHOR_OPEN] + $result;
        }
        $currency = $account->getCurrency();
        $expected = $anchor->getDifference()->add(Money::fromDecimal(
            $this->movements()->privateNet((int) $account->getId(), $anchor->getAsOf()->format('Y-m-d'), $day->format('Y-m-d')),
            $currency
        ));
        $same = $anchor->getAsOf()->format('Y-m-d') === $day->format('Y-m-d');

        return ['state' => !$expected->equals($difference) ? self::ANCHOR_MISMATCH : ($same ? self::ANCHOR_CONFIRMED : self::ANCHOR_COMPUTED), 'expected' => $expected] + $result;
    }

    /**
     * Confirms the anchor of $account as it reads NOW at the opening of $asOf — the day
     * {@see anchor()} named ({@see ANCHOR_OPEN} or {@see ANCHOR_MISMATCH}). $difference is what the
     * office saw (a decimal string): when the figures moved in between, nothing is stored.
     *
     * @throws BankRefusedException NOT_MIXED — the account is not «gemischt geführt»: a difference is corrected by a booking, never confirmed (owner 2026-11-19);
     *                              ANCHOR — nothing to confirm for that day, or the figures changed
     */
    public function confirm(BankAccount $account, \DateTimeImmutable $asOf, string $difference): BankAnchor
    {
        $actor = $this->actor ??= Actor::current();

        try {
            return $this->em->getTransaction(BankAnchor::class)->run(function () use ($account, $asOf, $difference, $actor): BankAnchor {
                $this->em->getRepository(BankAccount::class)->lockRow((int) $account->getId());
                // The row as it reads under the lock, managed by THIS unit of work (the caller's object may come from another request).
                $account = $this->em->getRepository(BankAccount::class)->find((int) $account->getId()) ?? $account;
                if (!$account->isMixedUse()) {
                    throw BankRefusedException::anchorNotMixed($account);
                }
                $now     = $this->anchor($account, $asOf);
                if ($now === null || !in_array($now['state'], [self::ANCHOR_OPEN, self::ANCHOR_MISMATCH], true) || $now['bank'] === null || $now['difference'] === null
                    || $now['asOf']->format('Y-m-d') !== $asOf->format('Y-m-d')) {
                    throw new BankRefusedException(BankRefusedException::ANCHOR, 'Für das Bankkonto «' . $account->getLabel() . '» gibt es per ' . $asOf->format('d.m.Y') . ' nichts zu bestätigen — das Fenster neu öffnen.');
                }
                if ($now['difference']->toDecimal() !== trim($difference)) {
                    throw new BankRefusedException(BankRefusedException::ANCHOR, 'Die Zahlen haben sich inzwischen geändert (Differenz jetzt ' . AmountFormat::of($now['difference']) . ') — das Fenster neu öffnen und nochmals bestätigen.');
                }
                $anchor = new BankAnchor($account, $now['asOf'], $now['bank'], $now['ledger'], $actor, new \DateTimeImmutable());
                // A day confirmed before and no longer true (its ledger opening changed) is confirmed anew: the old row goes.
                $old = $this->em->getRepository(BankAnchor::class)->findOneBy(['bankAccount' => $account->getId(), 'asOf' => $now['asOf']]);
                if ($old !== null) {
                    $this->em->remove($old);
                    $this->em->flush();
                }
                $this->em->persist($anchor);

                return $anchor;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BankRefusedException(BankRefusedException::ANCHOR, 'Die Differenz wurde soeben bereits bestätigt — das Fenster neu öffnen.', $e);
        }
    }

    // ── the check ────────────────────────────────────────────────────────

    /**
     * The check of $account over $from … $to (inclusive); the part before «Abgleich ab» is left
     * out. Null when the account is not bank-driven or the whole period lies before «Abgleich ab».
     */
    public function check(BankAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): ?BankCheck
    {
        $base = $account->getReconcileFrom();
        if (!$account->isBankDriven() || $base === null || $to->format('Y-m-d') < $base->format('Y-m-d')) {
            return null;
        }
        $from     = $from->format('Y-m-d') < $base->format('Y-m-d') ? $base : $from->setTime(0, 0);
        $to       = $to->setTime(0, 0);
        $id       = (int) $account->getId();
        $currency = $account->getCurrency();
        $money    = static fn(string $decimal): Money => Money::fromDecimal($decimal, $currency);
        $fromDay  = $from->format('Y-m-d');
        $toDay    = $to->format('Y-m-d');
        $first    = $this->em->getRepository(BankStatement::class)->firstOf($id);
        $latest   = $this->em->getRepository(BankStatement::class)->latestOf($id);
        $sums     = $this->movements()->stateSums($id, $fromDay, $toDay);

        $ledgerAccount = $this->em->getRepository(Account::class)->findOneBy(['number' => $account->getLedgerAccount()]);
        $changes       = $ledgerAccount === null ? [] : $this->lines()->changesOnAccount((int) $ledgerAccount->getId(), $fromDay, $toDay, $this->openingAccountId());
        $ledger        = Money::zero($currency);
        foreach ($changes as $change) {
            $ledger = $ledger->add(Money::fromDecimal($change['amount'], $currency));
        }

        // Which posting belongs to which link: financial's own by the idempotency key, the others through the owning modules.
        $ownerOf = [];
        $foreign = [];
        foreach ($changes as $change) {
            if ($change['sourceType'] === BankPosting::SOURCE_TYPE && str_starts_with($change['key'], 'bank-posting:')) {
                $ownerOf[$change['id']] = BankPosting::ownerKeyOf((int) substr($change['key'], strlen('bank-posting:')));
            } elseif ($change['sourceType'] !== '') {
                $foreign[] = ['id' => $change['id'], 'sourceType' => $change['sourceType'], 'sourceRef' => $change['sourceRef']];
            }
        }
        $ownerOf += ($this->owners ?? BankEntryOwners::fromModules(DI::getModuleManager()))->ownerKeys($foreign);

        $linked  = $this->movements()->linkedOf($id);
        $inRange = static fn(string $day): bool => $day >= $fromDay && $day <= $toDay;
        $booked  = [];   // owner keys with a posting in the period
        $without = [];   // entry id → its row and note
        foreach ($changes as $change) {
            $key  = $ownerOf[$change['id']] ?? null;
            $link = $key !== null ? ($linked[$key] ?? null) : null;
            if ($link !== null && $inRange($link['date'])) {
                $booked[$key] = true;
                continue;
            }
            $without[$change['id']] = $change + ['note' => $link !== null ? 'Bewegung am ' . (new \DateTimeImmutable($link['date']))->format('d.m.Y') : ''];
        }
        // An entry and its reversal, both in the period and both without a movement, net to zero: a take-back, not a finding.
        foreach ($without as $entryId => $change) {
            $original = $change['reversalOf'];
            if ($original > 0 && isset($without[$original])) {
                unset($without[$entryId], $without[$original]);
            }
        }
        $unbooked = ['count' => 0, 'sum' => Money::zero($currency)];
        foreach ($linked as $key => $link) {
            if ($inRange($link['date']) && !isset($booked[$key])) {
                $unbooked['count']++;
                $unbooked['sum'] = $unbooked['sum']->add(Money::fromDecimal($link['signed'], $currency));
            }
        }
        $entries = array_map(static fn(array $c): array => [
            'ref'    => $c['year'] . '/' . $c['number'],
            'date'   => $c['date'],
            'text'   => $c['text'],
            'amount' => $c['amount'],
            'note'   => $c['note'],
        ], array_slice(array_values($without), 0, self::LISTED));

        return new BankCheck(
            $account,
            $from,
            $to,
            $first?->getFromDate(),
            $latest?->getToDate(),
            $sums[BankMovementRepository::STATE_REVIEW]['count'],
            $money($sums[BankMovementRepository::STATE_BUSINESS]['sum']),
            $money($sums[BankMovementRepository::STATE_PRIVATE]['sum']),
            $ledger,
            $entries,
            count($without),
            $unbooked,
        );
    }

    // ── the two figures of the close ─────────────────────────────────────

    /**
     * The balance of the TRANSFER clearing account (the mandator's `bank-transfer`) at the end of
     * $to, in the fiscal year of that day — it must be 0.00 (ADR-061 decisions 17, 25). Null when
     * no such account is set, it is not in the chart, or no fiscal year covers the day: nothing to
     * check then, and nothing is assumed.
     *
     * @return array{account: Account, balance: Money}|null
     */
    public function transferBalance(\DateTimeImmutable $to): ?array
    {
        try {
            $number = (new LedgerService($this->em))->bankPostingAccount(LedgerService::BANK_TRANSFER_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return null;
        }
        $account = $number === null ? null : $this->em->getRepository(Account::class)->findOneBy(['number' => $number]);
        $year    = $this->em->getRepository(FiscalYear::class)->findByDate($to);
        if (!$account instanceof Account || $year === null) {
            return null;
        }
        $totals   = $this->lines()->accountTotals((int) $account->getId(), (int) $year->getId(), $year->getStartDate()->format('Y-m-d'), $to->format('Y-m-d'));
        $currency = LedgerService::baseCurrency();

        return ['account' => $account, 'balance' => Money::fromDecimal($totals['debit'], $currency)->subtract(Money::fromDecimal($totals['credit'], $currency))];
    }

    /**
     * «n Buchungen mit Vorsteuer ohne Beleg · CHF x Vorsteuer» (decision 26): the direct postings
     * with a tax code and no archived voucher dated in $from … $to.
     *
     * @return array{count: int, tax: Money}
     */
    public function taxWithoutVoucher(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $found = $this->em->getRepository(BankPosting::class)->taxWithoutVoucher($from->format('Y-m-d'), $to->format('Y-m-d'));

        return ['count' => $found['count'], 'tax' => Money::fromDecimal($found['tax'], LedgerService::baseCurrency())];
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * The bank's booked balance at the opening of $day: the opening balance of the first statement
     * plus every movement booked before the day. Null (with a German $reason) when the stored
     * statements do not reach the day.
     */
    private function bankAtOpeningOf(BankAccount $account, \DateTimeImmutable $day, ?string &$reason = null): ?Money
    {
        $id     = (int) $account->getId();
        $first  = $this->em->getRepository(BankStatement::class)->firstOf($id);
        $latest = $this->em->getRepository(BankStatement::class)->latestOf($id);
        if ($first === null || $latest === null) {
            $reason = 'Noch kein Kontoauszug eingelesen.';

            return null;
        }
        if ($day->format('Y-m-d') < $first->getFromDate()->format('Y-m-d')) {
            $reason = 'Der Kontoauszug beginnt erst am ' . $first->getFromDate()->format('d.m.Y') . ' — der Banksaldo per ' . $day->format('d.m.Y') . ' ist nicht bekannt.';

            return null;
        }
        if ($day->modify('-1 day')->format('Y-m-d') > $latest->getToDate()->format('Y-m-d')) {
            $reason = 'Der Kontoauszug ist nur bis ' . $latest->getToDate()->format('d.m.Y') . ' eingelesen — der Banksaldo per ' . $day->format('d.m.Y') . ' ist nicht bekannt.';

            return null;
        }

        return $first->getOpeningBalance()->add(Money::fromDecimal($this->movements()->signedBefore($id, $day->format('Y-m-d')), $account->getCurrency()));
    }

    /** The ledger account's balance at the opening of $day, in the fiscal year of that day (0.00 without one). */
    private function ledgerAtOpeningOf(BankAccount $account, \DateTimeImmutable $day): Money
    {
        $currency = $account->getCurrency();
        $ledger   = $this->em->getRepository(Account::class)->findOneBy(['number' => $account->getLedgerAccount()]);
        $year     = $this->em->getRepository(FiscalYear::class)->findByDate($day);
        if ($ledger === null || $year === null) {
            return Money::zero($currency);
        }

        return Money::fromDecimal($this->lines()->balanceAtOpeningOf((int) $ledger->getId(), (int) $year->getId(), $day->format('Y-m-d'), $this->openingAccountId()), $currency);
    }

    /** The id of the mandator's opening contra account, 0 when none is set or it is not in the chart. */
    private function openingAccountId(): int
    {
        try {
            $number = (new LedgerService($this->em))->yearOpeningAccount(LedgerService::OPENING_BALANCE_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return 0;
        }

        return $number === null ? 0 : (int) $this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getId();
    }

    private function movements(): BankMovementRepository
    {
        return $this->em->getRepository(BankMovement::class);
    }

    private function lines(): JournalLineRepository
    {
        return $this->em->getRepository(JournalLine::class);
    }
}
