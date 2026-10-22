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
 * The YEAR OPENING (Eröffnungsbuchungen, plan §5.7, P5 — owner decisions
 * 2026-10-22): the closing balances of the year before carried into a
 * fiscal year as generated journal entries dated the year's first day —
 * ONE TWO-LINE ENTRY PER ACCOUNT against the contra account (owner
 * 2026-10-22, second round: «wie die EB:-Buchungen von wdv» — so the contra
 * account's statement names every account instead of «div.»).
 *
 *   - Every balance-sheet account (asset, liability, equity) with a
 *     non-zero closing balance in the predecessor gets ONE entry: the
 *     account with that balance on its side, the contra account with the
 *     same amount on the other — in chart order (the number as a string,
 *     as the reports sort), so the numbers follow the chart.
 *   - The predecessor's RESULT (Σ of its expense and revenue accounts) is
 *     carried onto the mandator's `annual-result` account (KMU 2979
 *     «Jahresgewinn oder Jahresverlust») — ADDED to that account's own
 *     balance: one entry for 2979 with the sum; its appropriation (2970,
 *     reserves) is booked by hand afterwards.
 *   - The contra account is the mandator's `opening-balance` account (KMU
 *     9100 «Eröffnungsbilanz»): every account is opened against it, so it
 *     nets to zero (it carries a balance only when it had one at the
 *     predecessor's end: an incomplete imported opening, shown, not hidden).
 *   - The predecessor's closing balance is its OWN entries, its opening
 *     included (imported or generated) — never earlier years added up
 *     (FIN-REPORT-001): `JournalLineRepository::balancesByAccount()` over
 *     the predecessor's days.
 *
 * Each entry: text «Eröffnungsbilanz {code}» (the statement's counter
 * column names the account), origin `opening` / the target code, key
 * `opening:{code}:{account}` ({@see idempotencyKey()}). A generated
 * posting, so a collective account flagged «nur durch Module bebuchbar»
 * (1100, FIN-MODULE-ONLY-001) is opened like any other.
 *
 * **Repeatable while the target year is open** (owner: «Sperren und neu
 * eröffnen sind 2 unterschiedliche Massnahmen … die Eröffnungsbilanz sollte
 * wiederholt werden können, solange das neue Geschäftsjahr nicht
 * abgeschlossen ist»): a re-run REPLACES the whole set, entry by entry, in
 * ONE unit of work — an account whose balance changed is AMENDED in place
 * through `LedgerService::amend()` (ADR-042 addendum 2026-10-06: same
 * number, one `EntryChange` before / after), an account that no longer
 * carries a balance is RETRACTED (`retract()`, a documented gap), a new
 * account is POSTED; an unchanged entry is not touched, and an unchanged
 * set writes nothing. Never a storno pair, never a second entry per account
 * (the key is unique). The ONE compound entry of the first build (key
 * `opening:{code}`, same origin) is part of the set it finds and is
 * retracted by a re-run. A closed TARGET year refuses; an OPEN predecessor
 * is allowed — the opening is then PROVISIONAL (`YearOpeningPlan::$provisional`),
 * to be re-created after a change there.
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
    /** The origin type of every opening entry (`journal_entry.source_type`); the origin ref is the target year's code. */
    public const SOURCE_TYPE = 'opening';

    /** How many foreign entry numbers a refusal names at most. */
    private const FOREIGN_NUMBERS = 12;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) */
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?string $actor = null) {}

    /** The idempotency key of $account's opening entry in $year — found and replaced by it. */
    public static function idempotencyKey(FiscalYear $year, string $account): string
    {
        return self::SOURCE_TYPE . ':' . $year->getCode() . ':' . $account;
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
     * The generated opening entries standing in $year now, in number order — [] when none (an
     * imported or manual opening is not one: it carries another origin). One SQL, no entity.
     *
     * @return list<EntryRef>
     */
    public function existing(FiscalYear $year): array
    {
        return array_map(
            static fn(int $n) => new EntryRef($year->getCode(), $n),
            $this->entries()->generatedNumbersFrom($year, self::SOURCE_TYPE, $year->getCode())
        );
    }

    /**
     * Whether $year has an opening at all — the generated one, or entries on the contra account
     * the generator did not make (an imported «EB:», a manual opening). What the dashboard asks
     * (cheap: no balances); an unresolvable contra account counts as «not opened».
     */
    public function isOpened(FiscalYear $year): bool
    {
        if ($this->existing($year) !== []) {
            return true;
        }
        try {
            $contra = $this->accountOrRefuse(LedgerService::OPENING_BALANCE_ACCOUNT);
        } catch (YearOpeningRefusedException) {
            return false;
        }

        return $this->lines()->entriesOnAccountNotFrom((int) $contra->getId(), (int) $year->getId(), self::SOURCE_TYPE, $year->getCode(), 1)['count'] > 0;
    }

    /**
     * What the opening of $year would be NOW — every rule, the entries, the
     * provisional flag, and per entry what a re-run does (post / amend /
     * retract / leave). A READ without locks and without writes;
     * {@see create()} computes again inside its unit of work.
     *
     * @throws YearOpeningRefusedException
     */
    public function plan(FiscalYear $year): YearOpeningPlan
    {
        $predecessor = $this->predecessorOrRefuse($year);
        $contra      = $this->accountOrRefuse(LedgerService::OPENING_BALANCE_ACCOUNT);
        $result      = $this->accountOrRefuse(LedgerService::ANNUAL_RESULT_ACCOUNT);
        $this->refuseForeignOpening($year, $contra);
        $requests = $this->requests($year, $predecessor, $contra, $result);

        /** @var array<string, JournalEntry> $standing key → entry */
        $standing = [];
        foreach ($this->entries()->generatedFrom($year, self::SOURCE_TYPE, $year->getCode()) as $entry) {
            $standing[(string) $entry->getIdempotencyKey()] = $entry;
        }
        $toPost  = [];
        $toAmend = [];
        foreach ($requests as $number => $request) {
            $entry = $standing[$request->idempotencyKey] ?? null;
            if ($entry === null) {
                $toPost[] = (string) $number;
            } elseif (PostingRequest::fingerprintOf($entry->snapshot()) !== $request->fingerprint()) {
                $toAmend[] = (string) $number;
            }
        }
        $wanted    = array_flip(array_map(static fn(PostingRequest $r) => $r->idempotencyKey, $requests));
        $toRetract = [];
        foreach ($standing as $key => $entry) {
            if (!isset($wanted[$key])) {
                $toRetract[$key] = array_values(array_filter($entry->snapshot()['lines'], static fn(array $l) => $l['account'] !== $contra->getNumber()));
            }
        }

        return new YearOpeningPlan(
            $year->getCode(),
            $predecessor->getCode(),
            !$predecessor->isClosed(),
            $requests,
            array_map(static fn($e) => new EntryRef($year->getCode(), $e->getNumber()), $standing),
            $toPost,
            $toAmend,
            $toRetract,
        );
    }

    /**
     * Create — or re-create — the opening of fiscal year $yearId. OWNS its
     * unit of work and refuses to run inside an open one (the
     * `ManualEntryService` model): the plain reads that compute the balances
     * are the first statements, so they read one consistent snapshot; then
     * the retractions, the amendments and the new postings, each through the
     * ledger, which takes its locks in its own order (`post()`: `NumberRange`
     * → accounts → year → period; `amend()` / `retract()`: entry → accounts
     * → year → period) and re-checks the target period share-locked — a
     * close of the target committed meanwhile refuses there
     * (`period-closed`), and the rollback undoes the whole set and gives the
     * numbers back.
     *
     * @throws YearOpeningRefusedException
     * @throws PostingRefusedException    the ledger refused under its lock (a race with a close or a chart change)
     * @throws EntryNotEditableException  a standing opening entry cannot be changed any more (its period closed in a race, it was reversed)
     * @throws \LogicException            a unit of work is open, or nobody is named as the actor
     */
    public function create(int $yearId): YearOpeningResult
    {
        $transaction = $this->em->getTransaction(JournalEntry::class);
        if ($transaction->isOpen()) {
            throw new \LogicException('YearOpeningService::create() owns its unit of work — call it outside getTransaction()->run(), so the balances it reads and the entries it writes commit together');
        }
        $ledger = new LedgerService($this->em, $this->actor);

        return $transaction->run(function () use ($yearId, $ledger): YearOpeningResult {
            $year = $this->years()->find($yearId);
            if ($year === null) {
                throw new YearOpeningRefusedException(YearOpeningRefusedException::NOT_FOUND, "Fiscal year #{$yearId} does not exist");
            }
            $plan = $this->plan($year);
            if ($plan->requests === [] && !$plan->hasOpening()) {
                throw new YearOpeningRefusedException(YearOpeningRefusedException::NOTHING_TO_CARRY,
                    "Fiscal year {$plan->predecessorCode} has no closing balance — there is nothing to open {$year->getCode()} with");
            }

            $refs = $plan->existing;
            foreach (array_keys($plan->toRetract) as $key) {
                $ledger->retract((string) $key, self::SOURCE_TYPE);
                unset($refs[$key]);
            }
            foreach ($plan->toAmend as $number) {
                $ledger->amend($plan->requests[$number]->idempotencyKey, $plan->requests[$number]);
            }
            foreach ($plan->toPost as $number) {
                $refs[$plan->requests[$number]->idempotencyKey] = $ledger->post($plan->requests[$number]);
            }
            $refs = array_values($refs);
            usort($refs, static fn(EntryRef $a, EntryRef $b) => $a->number <=> $b->number);

            $action = match (true) {
                !$plan->hasOpening()   => YearOpeningResult::CREATED,
                $plan->requests === [] => YearOpeningResult::REMOVED,
                $plan->isUpToDate()    => YearOpeningResult::UNCHANGED,
                default                => YearOpeningResult::UPDATED,
            };

            return new YearOpeningResult(
                $action,
                $action === YearOpeningResult::REMOVED ? array_values($plan->existing) : $refs,
                $plan->provisional,
                $plan->predecessorCode,
                count($plan->toPost),
                count($plan->toAmend),
                count($plan->toRetract),
            );
        });
    }

    /**
     * The opening entries from the predecessor's closing balances — account number → its
     * two-line entry against the contra account, in chart order; [] when it has none (see the
     * class docblock for the shape).
     *
     * @return array<string, PostingRequest>
     * @throws YearOpeningRefusedException ACCOUNT_INACTIVE
     */
    private function requests(FiscalYear $year, FiscalYear $predecessor, Account $contra, Account $result): array
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
                continue;   // the contra account is the other side of every entry
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
            return [];
        }
        ksort($balances, SORT_STRING);
        $this->refuseInactive(array_map('strval', array_keys($balances)));

        $requests = [];
        foreach ($balances as $number => $net) {
            $number = (string) $number;
            // The account on its side, the contra account with the same amount on the other.
            $lines = $net->isPositive()
                ? [PostingLine::debit($number, $net), PostingLine::credit($contra->getNumber(), $net)]
                : [PostingLine::credit($number, $net->negate()), PostingLine::debit($contra->getNumber(), $net->negate())];
            $requests[$number] = PostingRequest::generated(
                $year->getStartDate(),
                'Eröffnungsbilanz ' . $year->getCode(),
                self::SOURCE_TYPE,
                $year->getCode(),
                self::idempotencyKey($year, $number),
                $lines
            );
        }

        return $requests;
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
        $foreign = $this->lines()->entriesOnAccountNotFrom((int) $contra->getId(), (int) $year->getId(), self::SOURCE_TYPE, $year->getCode(), self::FOREIGN_NUMBERS);
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
