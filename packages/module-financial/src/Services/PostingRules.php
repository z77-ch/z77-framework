<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The rules a posting must pass that only the database can answer (plan
 * §5.4, ADR-042 decision 10) — shared by `LedgerService::post()` and the
 * manual edit in `ManualEntryService`, so both ask the same questions:
 *
 *   - which fiscal year and period the date falls into (none → refused);
 *   - whether that period accepts the posting: `closed` never, `open`
 *     always;
 *   - whether the VAT of the date is settled — a posted return covers it:
 *     then no entry with a tax line is posted, changed or removed there
 *     (ADR-042 addendum 2026-11-24). Read from the returns
 *     (`VatSettlement`), lock-free first and on a LOCKING read after the
 *     period's share lock;
 *   - whether a tax line is dated before the mandator's «Abrechnung ab»
 *     (ADR-041 addendum 2026-11-24, point 3): before that day z77 settles
 *     no VAT, so no tax line is posted there — a setting, read lock-free
 *     ({@see vatStart()});
 *   - that every account exists, is POSTABLE (a group carries no line) and
 *     ACTIVE (deactivated = no new postings; existing lines keep resolving)
 *     — lock-free first, then again under a shared row lock after the
 *     number ({@see lockAccounts()}, FIN-TYPE-001);
 *   - that every tax code EXISTS in module-vat (ADR-043 decision 19: by
 *     code, no foreign key). Active or deactivated is NOT asked here: a
 *     posting carries the code its document snapshotted, and that document
 *     may be finalised or reversed after the code was deactivated. Offering
 *     only active codes for a NEW document is the screen's job (the manual
 *     form does).
 *
 * @internal financial's own collaborator; modules see `LedgerService` only
 */
final class PostingRules
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** @throws PostingRefusedException NO_FISCAL_YEAR */
    public function yearFor(\DateTimeImmutable $date): FiscalYear
    {
        /** @var FiscalYearRepository $years */
        $years = $this->em->getRepository(FiscalYear::class);
        $year  = $years->findByDate($date);
        if ($year === null) {
            throw new PostingRefusedException(
                PostingRefusedException::NO_FISCAL_YEAR,
                'No fiscal year covers ' . $date->format('Y-m-d') . ' — open the year first'
            );
        }

        return $year;
    }

    /** @throws PostingRefusedException NO_PERIOD (cannot happen for an opened year; guarded anyway) */
    public function periodFor(FiscalYear $year, \DateTimeImmutable $date): Period
    {
        $period = $year->periodOn($date);
        if ($period === null) {
            throw new PostingRefusedException(
                PostingRefusedException::NO_PERIOD,
                'Fiscal year ' . $year->getCode() . ' has no period covering ' . $date->format('Y-m-d')
            );
        }

        return $period;
    }

    /**
     * Whether a period takes a posting (ADR-042 decision 10): `closed` takes
     * nothing, `open` everything. Whether the VAT of the date is settled is a
     * separate question, asked of the posted returns ({@see assertVatAccepts()}).
     * $state = the state to judge by — the committed one from
     * {@see lockedPeriodState()} on the re-check under lock; null = the loaded
     * entity's (the lock-free first check).
     *
     * @throws PostingRefusedException PERIOD_CLOSED
     */
    public function assertPeriodAccepts(Period $period, ?string $state = null): void
    {
        $span  = $period->getStartDate()->format('d.m.Y') . '–' . $period->getEndDate()->format('d.m.Y');
        $state ??= $period->getState();
        if ($state === PeriodState::Closed->value) {
            throw new PostingRefusedException(PostingRefusedException::PERIOD_CLOSED, "Period {$span} is closed — nothing posts into it; correct by a reversal in an open period");
        }
    }

    /**
     * The same rule for a CHANGE of an existing entry (a manual edit or
     * delete, a source's amend or retract of its generated entry): `closed`
     * refuses. The exception is the editing one, naming the entry. The VAT
     * freeze is {@see assertVatAllowsChange()}.
     *
     * @param string $state the state to judge by — the loaded one (lock-free first check) or the committed one ({@see lockedPeriodState()})
     * @throws EntryNotEditableException PERIOD_CLOSED
     */
    public function assertPeriodAllowsChange(Period $period, JournalEntry $entry, string $state): void
    {
        if ($state === PeriodState::Closed->value) {
            $span = $period->getStartDate()->format('d.m.Y') . '–' . $period->getEndDate()->format('d.m.Y');
            $ref  = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
            throw new EntryNotEditableException(EntryNotEditableException::PERIOD_CLOSED, "Journal entry {$ref}: period {$span} is closed — nothing changes after the close");
        }
    }

    /**
     * The VAT freeze for a NEW entry (ADR-042 addendum 2026-11-24, points 2
     * and 3): a posting with a tax line dated in a SETTLED VAT period — one a
     * posted return covers — is refused; a posting without a tax line passes
     * and asks nothing (no read, no lock). «Settled» is read from the returns
     * (`VatSettlement`), never from the period.
     *
     * $locked = false is the lock-free first check (a plain read — inside a
     * unit of work it answers from the snapshot). $locked = true is the check
     * that DECIDES: a locking read of `vat_return` (shared, held until the
     * caller's commit), to be called after {@see lockedPeriodState()} — under
     * REPEATABLE READ only a locking read sees a return that committed after
     * the unit of work's first read. Lock order of a posting: `NumberRange` →
     * accounts (S) → year (S) → period (S) → `vat_return` (S, tax lines only).
     *
     * First, with either $locked: a tax line dated before «Abrechnung ab» is
     * refused `before-vat-start` ({@see vatStartCrossed()}) — a setting, read
     * without a lock, the same answer on both passes.
     *
     * @throws PostingRefusedException BEFORE_VAT_START | VAT_PERIOD_SETTLED
     * @throws \LogicException         $locked outside an open unit of work
     */
    public function assertVatAccepts(PostingRequest $request, bool $locked): void
    {
        $start = $this->vatStartCrossed(null, $request);
        if ($start !== null) {
            throw new PostingRefusedException(
                PostingRefusedException::BEFORE_VAT_START,
                'VAT is settled in z77 from ' . $start->format('d.m.Y') . ' on («Abrechnung ab») — a line with a tax code cannot be dated '
                . $request->date->format('d.m.Y')
            );
        }
        $settled = $this->settledAmong(self::vatFrozenDates(null, $request), $locked);
        if ($settled !== null) {
            throw new PostingRefusedException(
                PostingRefusedException::VAT_PERIOD_SETTLED,
                'VAT period ' . self::vatSpan($settled) . ' is settled — a line with a tax code cannot be posted into it any more',
                null,
                $settled
            );
        }
    }

    /**
     * The VAT freeze for a CHANGE of an existing entry — a manual edit or
     * delete, a source's amend or retract ($new = null: the entry goes):
     * refused when the old OR the new version carries a tax line and the old
     * OR the new date lies in a settled VAT period. $locked as in
     * {@see assertVatAccepts()}; lock order entry (X) → accounts (S) → year
     * (S) → period (S) → `vat_return` (S, tax lines only).
     *
     * First, with either $locked: the new version may not put a tax line
     * before «Abrechnung ab» — an existing tax entry there may be corrected
     * on its date or later ({@see vatStartCrossed()}); a removal is never
     * refused for it.
     *
     * @throws EntryNotEditableException BEFORE_VAT_START | VAT_PERIOD_SETTLED
     * @throws \LogicException           $locked outside an open unit of work
     */
    public function assertVatAllowsChange(JournalEntry $entry, ?PostingRequest $new, bool $locked): void
    {
        $start = $this->vatStartCrossed($entry, $new);
        if ($start !== null) {
            $ref = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
            throw new EntryNotEditableException(
                EntryNotEditableException::BEFORE_VAT_START,
                "Journal entry {$ref}: VAT is settled in z77 from " . $start->format('d.m.Y') . ' on («Abrechnung ab») — the new version cannot carry a tax line dated '
                . $new?->date->format('d.m.Y')
            );
        }
        $settled = $this->settledAmong(self::vatFrozenDates($entry, $new), $locked);
        if ($settled !== null) {
            $ref = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
            throw new EntryNotEditableException(
                EntryNotEditableException::VAT_PERIOD_SETTLED,
                "Journal entry {$ref}: VAT period " . self::vatSpan($settled) . ' is settled — an entry with a tax line is frozen there',
                $settled
            );
        }
    }

    /**
     * The settled VAT period covering $date, or null — the LOCK-FREE answer
     * behind `LedgerService::periodRefusal()` (a preview, a screen). Read
     * fresh on every call: nothing about «settled» is kept here.
     */
    public function settledVatPeriod(\DateTimeImmutable $date): ?VatPeriod
    {
        return $this->settledAmong([$date], false);
    }

    /**
     * The mandator's «Abrechnung ab» when the rule «no tax line before it» applies, else null
     * (plan §5.1): null while the mandator is NOT liable, while the day is not set (a liable
     * mandator that settles its VAT outside z77 — ADR-042 addendum 2026-11-24, point 6: nothing
     * blocks), with no record saved, and when the record cannot be read
     * (`MandatorUnavailableException`: an upgrade half done must not stop every tax posting —
     * the VAT return names that state itself). Lock-free, read with every call: a setting, not a
     * race (Doctrine's identity map — one query per request). Behind `LedgerService::periodRefusal()`
     * and `periodRefusalText()` as well.
     */
    public function vatStart(): ?\DateTimeImmutable
    {
        try {
            $settings = (new CurrentMandator($this->em))->vatSettings();
        } catch (MandatorUnavailableException) {
            return null;
        }

        return $settings->liable ? $settings->from : null;
    }

    /**
     * Whether $date lies before $start, by calendar day.
     */
    public static function isBefore(\DateTimeImmutable $date, \DateTimeImmutable $start): bool
    {
        return $date->format('Y-m-d') < $start->format('Y-m-d');
    }

    /**
     * The «Abrechnung ab» a write would cross, or null — the ONE place that says what the rule
     * «no tax line before Abrechnung ab» covers (plan §5.1), for a posting ($stored null), a change
     * (both) and a removal ($request null):
     *
     *   - a removal never crosses it (retract / delete of an existing tax entry before the day —
     *     the return never reads there);
     *   - a version WITHOUT a tax line never crosses it;
     *   - a version WITH a tax line dated on or after the day passes;
     *   - before the day: a NEW entry (`post()`, `reverse()`) is refused; a CHANGE passes only when
     *     the stored entry already carried a tax line and the date does not move earlier — an
     *     existing entry may still be corrected in text or amount. *This change rule is the plan's
     *     recommendation for an OPEN owner question* (board question 9, `financial.md` «no tax line
     *     before «Abrechnung ab»») — the owner's answer may change it here and nowhere else.
     *
     * Asks the mandator record only when a tax line is in the request.
     */
    private function vatStartCrossed(?JournalEntry $stored, ?PostingRequest $request): ?\DateTimeImmutable
    {
        if ($request === null || !$request->hasTaxLine()) {
            return null;
        }
        $start = $this->vatStart();
        if ($start === null || !self::isBefore($request->date, $start)) {
            return null;
        }
        if ($stored !== null && $stored->hasTaxLine() && !self::isBefore($request->date, $stored->getDate())) {
            return null;
        }

        return $start;
    }

    /**
     * The dates a write must NOT find settled — the ONE place that says what
     * the VAT freeze covers, for a posting ($stored null), a change (both) and
     * a removal ($request null): none when neither version carries a tax
     * line; otherwise the stored entry's date and the new date. An exemption
     * belongs HERE and nowhere else — stage S3's correction entry, marked by
     * its own columns, will contribute no date (it may be dated in a settled
     * period, ADR-042 addendum 2026-11-24, point 4).
     *
     * @return list<\DateTimeImmutable>
     */
    private static function vatFrozenDates(?JournalEntry $stored, ?PostingRequest $request): array
    {
        if (!($stored?->hasTaxLine() ?? false) && !($request?->hasTaxLine() ?? false)) {
            return [];
        }
        $dates = [];
        if ($stored !== null) {
            $dates[] = $stored->getDate();
        }
        if ($request !== null && ($stored === null || $request->date->format('Y-m-d') !== $stored->getDate()->format('Y-m-d'))) {
            $dates[] = $request->date;
        }

        return $dates;
    }

    /**
     * The first settled VAT period among $dates, or null.
     *
     * @param list<\DateTimeImmutable> $dates
     */
    private function settledAmong(array $dates, bool $locked): ?VatPeriod
    {
        if ($dates === []) {
            return null;
        }
        $settlement = new VatSettlement($this->em);
        foreach ($dates as $date) {
            $period = $locked ? $settlement->lockedPeriodCovering($date) : $settlement->periodCovering($date);
            if ($period !== null) {
                return $period;
            }
        }

        return null;
    }

    private static function vatSpan(VatPeriod $period): string
    {
        return $period->from->format('d.m.Y') . '–' . $period->to->format('d.m.Y');
    }

    /**
     * The period's state as COMMITTED, read under a SHARED row lock
     * (`SELECT state … LOCK IN SHARE MODE`, held until the caller's commit) —
     * the re-check a write into the journal makes after its first lock
     * (`post()` after the number, a manual edit / delete after the entry
     * lock). Why (P5 part 1, the year close): the lock-free check reads the
     * state before any lock, and a close committing in between would let the
     * posting land in a closed period. With this lock a close
     * (`FiscalYearRepository::lockPeriodsOf()`, exclusive) waits for every
     * posting in flight in the year, and a posting that waited for a close
     * reads `closed` and is refused — its rollback gives the number back.
     * The period's fiscal-year row is share-locked FIRST (see
     * `FiscalYearRepository::lockedPeriodState()`): the `journal_entry` insert
     * share-locks that row at the flush anyway (foreign key), and taking it
     * late would deadlock with a close. Lock order: `NumberRange` → accounts
     * (S) → year (S) → period (S) in a posting; entry (X) → accounts (S) →
     * year (S) → period (S) in a manual edit / delete — each followed by
     * `vat_return` (S) when a tax line is involved ({@see assertVatAccepts()}); account (X) → years
     * (S) → periods (S) in a type change; fiscal-year rows (X) → periods (X)
     * in a close / reopen, which takes no account and no range. Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open unit of work, or the period row is gone
     */
    public function lockedPeriodState(Period $period): string
    {
        /** @var FiscalYearRepository $years */
        $years = $this->em->getRepository(FiscalYear::class);

        return $years->lockedPeriodState((int) $period->getFiscalYear()->getId(), (int) $period->getId());
    }

    /**
     * The accounts of the lines, by number — each must exist and be POSTABLE;
     * ACTIVE is required where $requireActive says so (ADR-043 decision 19,
     * review 2026-09-22 M5: «inactive» means not offered for NEW postings,
     * existing references keep working):
     *
     *   - `true` (the default, `post()`): every line needs an active account;
     *   - `false` (`reverse()`): a counterpart of an existing entry must
     *     always be possible, also on an account deactivated since;
     *   - a `list<bool>` by line index (a manual edit): an UNCHANGED line may
     *     keep its now-inactive account, a new or changed line needs an
     *     active one — the `$requireActiveType` model of `ContactAddressValidator`.
     *
     * One lookup per distinct number.
     *
     * @param list<PostingLine> $lines
     * @param bool|list<bool> $requireActive
     * @return array<string, Account> number → account
     * @throws PostingRefusedException ACCOUNT_UNKNOWN | ACCOUNT_NOT_POSTABLE | ACCOUNT_INACTIVE
     */
    public function resolveAccounts(array $lines, bool|array $requireActive = true): array
    {
        /** @var AccountRepository $accounts */
        $accounts = $this->em->getRepository(Account::class);
        $resolved = [];
        foreach ($lines as $i => $line) {
            $account = $resolved[$line->account] ?? $accounts->findOneBy(['number' => $line->account]);
            if ($account === null) {
                throw new PostingRefusedException(PostingRefusedException::ACCOUNT_UNKNOWN, "Account {$line->account} does not exist");
            }
            self::assertUsable($account, $account->isPostable(), $account->isActive(), self::mustBeActive($requireActive, $i));
            $resolved[$line->account] = $account;
        }

        return $resolved;
    }

    /**
     * The same account checks AGAIN, on a LOCKING read
     * ({@see AccountRepository::lockForPosting()}) — FIN-TYPE-001. The rows
     * of the accounts stay share-locked until the caller's commit, and
     * postable / active are the latest committed values, not the
     * transaction's snapshot or a stale object in the Identity Map: an
     * account turned into a group (or deactivated) while this posting was
     * being validated is seen here and refused, and a change that comes
     * later waits for this commit and then finds the line.
     *
     * Call it AFTER the number is drawn — lock order `NumberRange` first
     * (ADR-039 decision 10) — and let a refusal propagate: it comes after
     * the draw, so a caller that caught it and committed would consume the
     * number without an entry. The manual edit draws no number and calls it
     * right after {@see resolveAccounts()}.
     *
     * @param list<PostingLine> $lines
     * @param array<string, Account> $accounts what {@see resolveAccounts()} returned
     * @param bool|list<bool> $requireActive as in {@see resolveAccounts()}
     * @throws PostingRefusedException ACCOUNT_UNKNOWN | ACCOUNT_NOT_POSTABLE | ACCOUNT_INACTIVE
     */
    public function lockAccounts(array $lines, array $accounts, bool|array $requireActive = true): void
    {
        /** @var AccountRepository $repository */
        $repository = $this->em->getRepository(Account::class);
        $flags      = $repository->lockForPosting(array_map(static fn(PostingLine $l) => $l->account, $lines));
        foreach ($lines as $i => $line) {
            $flag = $flags[$line->account] ?? null;
            if ($flag === null) {
                throw new PostingRefusedException(PostingRefusedException::ACCOUNT_UNKNOWN, "Account {$line->account} does not exist");
            }
            self::assertUsable($accounts[$line->account], $flag['postable'], $flag['active'], self::mustBeActive($requireActive, $i));
        }
    }

    /** @param bool|list<bool> $requireActive */
    private static function mustBeActive(bool|array $requireActive, int $line): bool
    {
        return is_bool($requireActive) ? $requireActive : ($requireActive[$line] ?? true);
    }

    /** @throws PostingRefusedException ACCOUNT_NOT_POSTABLE | ACCOUNT_INACTIVE */
    private static function assertUsable(Account $account, bool $postable, bool $active, bool $mustBeActive): void
    {
        if (!$postable) {
            throw new PostingRefusedException(PostingRefusedException::ACCOUNT_NOT_POSTABLE, 'Account «' . $account->label() . '» is a group — it carries no journal line');
        }
        if ($mustBeActive && !$active) {
            throw new PostingRefusedException(PostingRefusedException::ACCOUNT_INACTIVE, 'Account «' . $account->label() . '» is deactivated — no new postings');
        }
    }

    /**
     * A MANUAL entry writes no line onto an account «nur durch Module bebuchbar»
     * (`Account::$modulePostingOnly`, owner 2026-10-13): such an account is a collective
     * account a module keeps in step with its own records (debtor's 1100), and a manual
     * line there is exactly the difference the year-close reconciliation would report.
     * Called for `EntryKind::Manual` only — a generated posting of a module passes.
     *
     * $applies by line, as `$requireActive` in {@see resolveAccounts()}: `true` = every
     * line (a new entry); a `list<bool>` = only the NEW or CHANGED lines of a manual edit,
     * so an entry that already had such a line (posted before the flag) can still be
     * corrected around it — and deleted, which touches no account. Lock-free: the flag is
     * configuration, read with the account; a flag switched on while an entry is being
     * saved is caught by the reconciliation, not by a lock.
     *
     * @param list<PostingLine> $lines
     * @param array<string, Account> $accounts what {@see resolveAccounts()} returned
     * @param bool|list<bool> $applies
     * @throws PostingRefusedException ACCOUNT_MODULE_ONLY
     */
    public function assertManualAllowed(array $lines, array $accounts, bool|array $applies = true): void
    {
        foreach ($lines as $i => $line) {
            $account = $accounts[$line->account] ?? null;
            if ($account !== null && $account->isModulePostingOnly() && self::mustBeActive($applies, $i)) {
                throw new PostingRefusedException(
                    PostingRefusedException::ACCOUNT_MODULE_ONLY,
                    'Account «' . $account->label() . '» takes module postings only — a manual entry cannot write a line onto it',
                    $account->label()
                );
            }
        }
    }

    /**
     * Every tax code on the lines exists in module-vat. Existence only — see
     * the class docblock for why «active» is not asked here.
     *
     * @param list<PostingLine> $lines
     * @throws PostingRefusedException TAX_CODE_UNKNOWN
     */
    public function assertTaxCodesExist(array $lines): void
    {
        /** @var TaxCodeRepository $codes */
        $codes = $this->em->getRepository(TaxCode::class);
        $seen  = [];
        foreach ($lines as $line) {
            if (!$line->hasTax() || isset($seen[$line->taxCode])) {
                continue;
            }
            if ($codes->findByCode($line->taxCode) === null) {
                throw new PostingRefusedException(PostingRefusedException::TAX_CODE_UNKNOWN, "Tax code '{$line->taxCode}' does not exist in module-vat");
            }
            $seen[$line->taxCode] = true;
        }
    }

    /**
     * The entity lines for an entry from the request's lines, positions from
     * 1, accounts resolved by {@see resolveAccounts()}.
     *
     * @param array<string, Account> $accounts
     * @return list<JournalLine>
     */
    public function buildLines(JournalEntry $entry, PostingRequest $request, array $accounts): array
    {
        $lines = [];
        foreach ($request->lines as $i => $line) {
            $lines[] = new JournalLine(
                $entry,
                $i + 1,
                $accounts[$line->account],
                $line->debit,
                $line->credit,
                $line->taxCode,
                $line->taxRate,
                $line->taxBase,
                $line->taxAmount,
                $line->text,
            );
        }

        return $lines;
    }
}
