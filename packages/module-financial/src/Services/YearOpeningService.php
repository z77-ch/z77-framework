<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\JournalEntryRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The YEAR OPENING (Eröffnungsbuchung, plan §5.7, P5 — owner decisions
 * 2026-10-22): the closing balances of the year before carried into a
 * fiscal year as ONE generated journal entry, dated the year's first day.
 *
 *   - Every balance-sheet account (asset, liability, equity) with a
 *     non-zero closing balance in the predecessor gets ONE line with that
 *     balance, in chart order (the number as a string, as the reports sort).
 *   - The predecessor's RESULT (Σ of its expense and revenue accounts) is
 *     carried onto the mandator's `annual-result` account (KMU 2979
 *     «Jahresgewinn oder Jahresverlust») — added to that account's own
 *     balance, one line; its appropriation (2970, reserves) is booked by
 *     hand afterwards.
 *   - The contra account is the mandator's `opening-balance` account (KMU
 *     9100 «Eröffnungsbilanz»): two lines at the end, Haben = Σ of the debit
 *     lines, Soll = Σ of the credit lines — every account is opened against
 *     it and it nets to zero (it carries a balance only when it had one at
 *     the predecessor's end: an incomplete imported opening, shown, not hidden).
 *   - The predecessor's closing balance is its OWN entries, its opening
 *     included (imported or generated) — never earlier years added up
 *     (FIN-REPORT-001): `JournalLineRepository::balancesByAccount()` over
 *     the predecessor's days.
 *
 * Origin `opening` / the target code, idempotency key `opening:{code}`
 * ({@see idempotencyKey()}). A generated posting, so a collective account
 * flagged «nur durch Module bebuchbar» (1100, FIN-MODULE-ONLY-001) is
 * opened like any other.
 *
 * **Repeatable while the target year is open** (owner: «Sperren und neu
 * eröffnen sind 2 unterschiedliche Massnahmen … die Eröffnungsbilanz sollte
 * wiederholt werden können, solange das neue Geschäftsjahr nicht
 * abgeschlossen ist»): a re-run REPLACES the opening IN PLACE through
 * `LedgerService::amend()` — the source correcting its own generated entry
 * (ADR-042 addendum 2026-10-06): same number, one `EntryChange` (before /
 * after) in the change log, a no-op when nothing changed. When the
 * predecessor has no balance any more, `retract()` removes it (a documented
 * gap). Never a storno pair, never a second opening. A closed TARGET year
 * refuses; an OPEN predecessor is allowed — the opening is then PROVISIONAL
 * (`YearOpeningPlan::$provisional`), to be re-created after a change there.
 *
 * Refused as well: the earliest year (nothing to carry), a year-opening
 * account missing on the mandator or not postable and active, the contra
 * account already carrying entries in the target year the generator did not
 * make (an imported «EB:YYYY», a manual opening — the year is opened
 * already), an inactive account with a balance (reactivate or transfer it
 * first — `post()` would refuse it anyway).
 *
 * Base currency only (ADR-042 decision 4): the ledger has no foreign
 * amounts, so a foreign-currency bank account carries its CHF balance; a
 * revaluation is an ordinary entry in the predecessor before the opening.
 */
final class YearOpeningService
{
    /** The origin type of every opening entry (`journal_entry.source_type`). */
    public const SOURCE_TYPE = 'opening';

    /** How many foreign entry numbers a refusal names at most. */
    private const FOREIGN_NUMBERS = 12;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) */
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?string $actor = null) {}

    /** The idempotency key of $year's opening — the one opening of the year, found and replaced by it. */
    public static function idempotencyKey(FiscalYear $year): string
    {
        return self::SOURCE_TYPE . ':' . $year->getCode();
    }

    /**
     * Why «Eröffnung erstellen» cannot be offered for $year at all, or null —
     * what the fiscal-year screen asks per year (lock-free, cheap: no
     * balances). The finer refusals come from {@see plan()} in the dialog.
     *
     * @return ?string a {@see YearOpeningRefusedException} reason
     */
    public function refusal(FiscalYear $year): ?string
    {
        if ($year->isClosed()) {
            return YearOpeningRefusedException::TARGET_CLOSED;
        }

        return $this->years()->predecessorOf($year) === null ? YearOpeningRefusedException::NO_PREDECESSOR : null;
    }

    /**
     * The generated opening standing in $year now, or null (an imported or
     * manual opening is not one — it carries no key of this service).
     */
    public function existing(FiscalYear $year): ?EntryRef
    {
        $entry = $this->entries()->findOneBy(['idempotencyKey' => self::idempotencyKey($year)]);

        return $entry === null ? null : new EntryRef($entry->getFiscalYear()->getCode(), $entry->getNumber());
    }

    /**
     * Whether $year has an opening at all — the generated one, or entries on the contra account
     * the generator did not make (an imported «EB:», a manual opening). What the dashboard asks
     * (cheap: no balances); an unresolvable contra account counts as «not opened».
     */
    public function isOpened(FiscalYear $year): bool
    {
        if ($this->existing($year) !== null) {
            return true;
        }
        try {
            $contra = $this->accountOrRefuse(LedgerService::OPENING_BALANCE_ACCOUNT);
        } catch (YearOpeningRefusedException) {
            return false;
        }

        return $this->lines()->entriesOnAccountExcept((int) $contra->getId(), (int) $year->getId(), self::idempotencyKey($year), 1)['count'] > 0;
    }

    /**
     * What the opening of $year would be NOW — every rule, the lines, the
     * provisional flag, whether the existing opening is up to date. A READ
     * without locks and without writes; {@see create()} computes again inside
     * its unit of work.
     *
     * @throws YearOpeningRefusedException
     */
    public function plan(FiscalYear $year): YearOpeningPlan
    {
        $predecessor = $this->predecessorOrRefuse($year);
        $contra      = $this->accountOrRefuse(LedgerService::OPENING_BALANCE_ACCOUNT);
        $result      = $this->accountOrRefuse(LedgerService::ANNUAL_RESULT_ACCOUNT);
        $this->refuseForeignOpening($year, $contra);
        $request = $this->request($year, $predecessor, $contra, $result);

        $entry    = $this->entries()->findOneBy(['idempotencyKey' => self::idempotencyKey($year)]);
        $existing = $entry === null ? null : new EntryRef($year->getCode(), $entry->getNumber());
        $upToDate = $entry !== null && $request !== null
            && PostingRequest::fingerprintOf($entry->snapshot()) === $request->fingerprint();

        return new YearOpeningPlan($year->getCode(), $predecessor->getCode(), !$predecessor->isClosed(), $request, $existing, $upToDate);
    }

    /**
     * Create — or re-create — the opening of fiscal year $yearId. OWNS its
     * unit of work and refuses to run inside an open one (the
     * `ManualEntryService` model): the plain reads that compute the balances
     * are the first statements, so they read one consistent snapshot; the
     * ledger then takes its locks in its own order (`post()`: `NumberRange`
     * → accounts → year → period; `amend()` / `retract()`: entry →
     * accounts → year → period) and re-checks the target period
     * share-locked — a close of the target committed meanwhile refuses
     * there (`period-closed`), and the rollback gives the number back.
     *
     * @throws YearOpeningRefusedException
     * @throws PostingRefusedException    the ledger refused under its lock (a race with a close or a chart change)
     * @throws EntryNotEditableException  the existing opening cannot be changed any more (its period closed in a race)
     * @throws \LogicException            a unit of work is open, or nobody is named as the actor
     */
    public function create(int $yearId): YearOpeningResult
    {
        $transaction = $this->em->getTransaction(JournalEntry::class);
        if ($transaction->isOpen()) {
            throw new \LogicException('YearOpeningService::create() owns its unit of work — call it outside getTransaction()->run(), so the balances it reads and the entry it writes commit together');
        }
        $ledger = new LedgerService($this->em, $this->actor);

        return $transaction->run(function () use ($yearId, $ledger): YearOpeningResult {
            $year = $this->years()->find($yearId);
            if ($year === null) {
                throw new YearOpeningRefusedException(YearOpeningRefusedException::NOT_FOUND, "Fiscal year #{$yearId} does not exist");
            }
            $plan = $this->plan($year);
            $key  = self::idempotencyKey($year);

            if ($plan->request === null) {
                if ($plan->existing === null) {
                    throw new YearOpeningRefusedException(YearOpeningRefusedException::NOTHING_TO_CARRY,
                        "Fiscal year {$plan->predecessorCode} has no closing balance — there is nothing to open {$year->getCode()} with");
                }
                $ledger->retract($key, self::SOURCE_TYPE);

                return new YearOpeningResult(YearOpeningResult::REMOVED, $plan->existing, $plan->provisional, $plan->predecessorCode);
            }
            if ($plan->existing === null) {
                return new YearOpeningResult(YearOpeningResult::CREATED, $ledger->post($plan->request), $plan->provisional, $plan->predecessorCode);
            }
            $ref = $ledger->amend($key, $plan->request);

            return new YearOpeningResult($plan->upToDate ? YearOpeningResult::UNCHANGED : YearOpeningResult::UPDATED, $ref, $plan->provisional, $plan->predecessorCode);
        });
    }

    /**
     * The opening entry from the predecessor's closing balances, or null when
     * it has none (see the class docblock for the shape).
     *
     * @throws YearOpeningRefusedException ACCOUNT_INACTIVE
     */
    private function request(FiscalYear $year, FiscalYear $predecessor, Account $contra, Account $result): ?PostingRequest
    {
        $currency = LedgerService::baseCurrency();
        $zero     = Money::zero($currency);
        /** @var array<string, Money> $balances number → debit − credit */
        $balances = [];
        $profit   = $zero;   // Σ (debit − credit) of the income statement: negative = a profit
        $rows     = $this->lines()->balancesByAccount(
            (int) $predecessor->getId(),
            $predecessor->getStartDate()->format('Y-m-d'),
            $predecessor->getEndDate()->format('Y-m-d')
        );
        foreach ($rows as $row) {
            $number = (string) $row['number'];
            if ($number === $contra->getNumber()) {
                continue;   // the contra account balances itself (two lines below)
            }
            $net = Money::fromDecimal((string) $row['debit'], $currency)->subtract(Money::fromDecimal((string) $row['credit'], $currency));
            $type = AccountType::tryFrom((string) $row['type']);
            if ($number !== $result->getNumber() && ($type === AccountType::Expense || $type === AccountType::Revenue)) {
                $profit = $profit->add($net);
                continue;
            }
            $balances[$number] = ($balances[$number] ?? $zero)->add($net);
        }
        $balances[$result->getNumber()] = ($balances[$result->getNumber()] ?? $zero)->add($profit);
        $balances = array_filter($balances, static fn(Money $m) => !$m->isZero());
        if ($balances === []) {
            return null;
        }
        ksort($balances, SORT_STRING);
        $this->refuseInactive(array_map('strval', array_keys($balances)));

        $lines  = [];
        $debit  = $zero;
        $credit = $zero;
        foreach ($balances as $number => $net) {
            if ($net->isPositive()) {
                $lines[] = PostingLine::debit((string) $number, $net);
                $debit   = $debit->add($net);
            } else {
                $lines[] = PostingLine::credit((string) $number, $net->negate());
                $credit  = $credit->add($net->negate());
            }
        }
        // Every account is opened AGAINST the contra account: its Haben takes the debit side, its Soll the credit side.
        if ($debit->isPositive()) {
            $lines[] = PostingLine::credit($contra->getNumber(), $debit);
        }
        if ($credit->isPositive()) {
            $lines[] = PostingLine::debit($contra->getNumber(), $credit);
        }

        return PostingRequest::generated(
            $year->getStartDate(),
            'Eröffnungsbilanz ' . $year->getCode(),
            self::SOURCE_TYPE,
            $year->getCode(),
            self::idempotencyKey($year),
            $lines
        );
    }

    /** @throws YearOpeningRefusedException TARGET_CLOSED | NO_PREDECESSOR */
    private function predecessorOrRefuse(FiscalYear $year): FiscalYear
    {
        if ($year->isClosed()) {
            throw new YearOpeningRefusedException(YearOpeningRefusedException::TARGET_CLOSED, "Fiscal year {$year->getCode()} is closed — its opening is frozen with it");
        }
        $predecessor = $this->years()->predecessorOf($year);
        if ($predecessor === null) {
            throw new YearOpeningRefusedException(YearOpeningRefusedException::NO_PREDECESSOR, "Fiscal year {$year->getCode()} is the earliest — there is no year to carry forward");
        }

        return $predecessor;
    }

    /**
     * The year-opening account the mandator names under $key, postable and active.
     *
     * @throws YearOpeningRefusedException ACCOUNT_MISSING — `$items`: [$key, the number or '']
     */
    private function accountOrRefuse(string $key): Account
    {
        try {
            $number = (new LedgerService($this->em, $this->actor))->yearOpeningAccount($key);
        } catch (MandatorUnavailableException $e) {
            throw new YearOpeningRefusedException(YearOpeningRefusedException::ACCOUNT_MISSING, "The mandator cannot be read: {$e->getMessage()}", [$key, '']);
        }
        $account = $number === null ? null : $this->em->getRepository(Account::class)->findOneBy(['number' => $number]);
        if ($account === null || !$account->isPostable() || !$account->isActive()) {
            throw new YearOpeningRefusedException(YearOpeningRefusedException::ACCOUNT_MISSING,
                "The year-opening account '{$key}' " . ($number === null ? 'is not set on the mandator' : "{$number} is not a postable active account"), [$key, (string) $number]);
        }

        return $account;
    }

    /** @throws YearOpeningRefusedException FOREIGN_OPENING — `$items`: the contra account's label, then the entry numbers */
    private function refuseForeignOpening(FiscalYear $year, Account $contra): void
    {
        $foreign = $this->lines()->entriesOnAccountExcept((int) $contra->getId(), (int) $year->getId(), self::idempotencyKey($year), self::FOREIGN_NUMBERS);
        if ($foreign['count'] === 0) {
            return;
        }
        $numbers = array_map('strval', $foreign['numbers']);
        if ($foreign['count'] > count($numbers)) {
            $numbers[] = '…';
        }
        throw new YearOpeningRefusedException(YearOpeningRefusedException::FOREIGN_OPENING,
            "Fiscal year {$year->getCode()}: account {$contra->getNumber()} carries {$foreign['count']} entr(y/ies) the opening did not make — the year is opened already",
            [$contra->label(), ...$numbers]);
    }

    /**
     * @param list<string> $numbers the accounts that carry a balance
     * @throws YearOpeningRefusedException ACCOUNT_INACTIVE — `$items`: the inactive accounts' labels
     */
    private function refuseInactive(array $numbers): void
    {
        $inactive = [];
        foreach ($this->em->getRepository(Account::class)->findBy(['number' => $numbers]) as $account) {
            if (!$account->isActive()) {
                $inactive[$account->getNumber()] = $account->label();
            }
        }
        if ($inactive !== []) {
            ksort($inactive, SORT_STRING);
            throw new YearOpeningRefusedException(YearOpeningRefusedException::ACCOUNT_INACTIVE,
                'Inactive accounts carry a closing balance: ' . implode(', ', array_keys($inactive)), array_values($inactive));
        }
    }

    private function years(): FiscalYearRepository
    {
        return $this->em->getRepository(FiscalYear::class);
    }

    private function entries(): JournalEntryRepository
    {
        return $this->em->getRepository(JournalEntry::class);
    }

    private function lines(): JournalLineRepository
    {
        return $this->em->getRepository(JournalLine::class);
    }
}
