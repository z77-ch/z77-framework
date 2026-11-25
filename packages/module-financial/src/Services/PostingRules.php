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
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Services\UnknownTaxCodeException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

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
 *     form does);
 *   - that the mandator's VAT accounts carry exactly the tax the coded lines
 *     compute — the lock of the VAT accounts ({@see assertVatLinesComputed()},
 *     ADR-042 addendum 2 of 2026-11-25, point 7).
 *
 * @internal financial's own collaborator; modules see `LedgerService` only
 */
final class PostingRules
{
    /** The lock's table — built on first use and kept: one mandator read per instance ({@see assertVatLinesComputed()}). */
    private ?VatAccountMap $vatAccountMap;

    /**
     * @param VatAccountMap|null $vatAccountMap the table the lock of the VAT accounts reads (a harness passes its own); null = one on $em, built on first use
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?VatAccountMap $vatAccountMap = null)
    {
        $this->vatAccountMap = $vatAccountMap;
    }

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

    /** (a) The period a correction entry names is not settled — it corrects only a posted period. */
    public const CORRECTION_NOT_SETTLED = 'correction-not-settled';
    /** (b) The corrected period does not lie wholly before the entry's date. */
    public const CORRECTION_NOT_BEFORE = 'correction-not-before';
    /** (c) A correction entry without a line with a tax code. */
    public const CORRECTION_NO_TAX_LINE = 'correction-no-tax-line';
    /** (e) A correction entry dated after «Abrechnung bis» — no return would carry it. */
    public const CORRECTION_AFTER_END = 'correction-after-vat-end';
    /** The reasons of {@see assertCorrectionAccepted()} besides `vat-period-settled` (d). */
    public const CORRECTION_REASONS = [self::CORRECTION_NOT_SETTLED, self::CORRECTION_NOT_BEFORE, self::CORRECTION_NO_TAX_LINE, self::CORRECTION_AFTER_END];

    /**
     * The rule of a CORRECTION ENTRY — «Korrekturbuchung zu Periode X» (ADR-042 addendum 2 of
     * 2026-11-25, points 1 and 3; addendum 3, point 2): a request that names a corrected VAT period
     * ({@see PostingRequest::$corrects} — a MANUAL request; a generated one cannot carry it) must
     * satisfy, in this order (board S3-1: the date before the period before the lines):
     *
     *   (d) its date lies in NO settled period — the freeze has no exemption; refused
     *       `vat-period-settled` with that period also when the entry has no tax line;
     *   (e) its date lies on or before «Abrechnung bis» — after it no return carries a block;
     *   (a) the corrected period is SETTLED: a live return covers it with exactly these days;
     *   (b) the corrected period ends BEFORE the entry's date;
     *   (c) at least one line carries a tax code.
     *
     * $locked = false: lock-free (the screen's common refusal before any lock). $locked = true:
     * after the period locks of the caller, on a LOCKING read of `vat_return`
     * (`VatSettlement::lockedPeriodCovering()`, shared until commit) — the corrected return's row
     * itself: a lift of that return (which takes it exclusively) and this entry serialise in the
     * existing lock order (… period (S) → `vat_return` (S)); a lift that committed first leaves the
     * period unsettled and refuses here (a), a lift that comes later waits and then sees the entry
     * (`VatReturnPostingService::lift()` refuses while an entry corrects the period).
     *
     * Nothing for a request without a marker. The German sentence is
     * `LedgerService::correctionRefusalText()`.
     *
     * @throws PostingRefusedException VAT_PERIOD_SETTLED | one of {@see CORRECTION_REASONS}
     * @throws \LogicException         $locked outside an open unit of work
     */
    public function assertCorrectionAccepted(PostingRequest $request, bool $locked): void
    {
        $corrects = $request->corrects;
        if ($corrects === null) {
            return;
        }
        $settlement = new VatSettlement($this->em);
        $settled    = $locked ? $settlement->lockedPeriodCovering($request->date) : $settlement->periodCovering($request->date);
        if ($settled !== null) {
            throw new PostingRefusedException(
                PostingRefusedException::VAT_PERIOD_SETTLED,
                'VAT period ' . self::vatSpan($settled) . ' is settled — a correction entry is dated in the running period, never in a settled one',
                null,
                $settled
            );
        }
        $until = $this->vatUntil();
        if ($until !== null && self::isBefore($until, $request->date)) {
            throw new PostingRefusedException(
                self::CORRECTION_AFTER_END,
                'VAT is settled in z77 until ' . $until->format('d.m.Y') . ' («Abrechnung bis») — a correction entry dated ' . $request->date->format('d.m.Y') . ' would be carried by no return',
                null,
                $corrects
            );
        }
        $live = $locked ? $settlement->lockedPeriodCovering($corrects->from) : $settlement->periodCovering($corrects->from);
        if ($live === null || !$live->equals($corrects)) {
            throw new PostingRefusedException(
                self::CORRECTION_NOT_SETTLED,
                'VAT period ' . self::vatSpan($corrects) . ' is not settled — a correction entry corrects a posted period only',
                null,
                $corrects
            );
        }
        if (!self::isBefore($corrects->to, $request->date)) {
            throw new PostingRefusedException(
                self::CORRECTION_NOT_BEFORE,
                'The corrected VAT period ' . self::vatSpan($corrects) . ' does not lie before the entry date ' . $request->date->format('d.m.Y'),
                null,
                $corrects
            );
        }
        if (array_filter($request->lines, static fn(PostingLine $line): bool => $line->hasTax()) === []) {
            throw new PostingRefusedException(
                self::CORRECTION_NO_TAX_LINE,
                'A correction entry carries at least one line with a tax code',
                null,
                $corrects
            );
        }
    }

    /**
     * The mandator's «Abrechnung bis», or null (not set, no record, unreadable) — what rule (e) of
     * {@see assertCorrectionAccepted()} reads. Lock-free, a setting.
     */
    private function vatUntil(): ?\DateTimeImmutable
    {
        try {
            return (new CurrentMandator($this->em))->vatSettings()->until;
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    /**
     * The mandator's «Abrechnung ab» when the rule «no tax line before it» applies, else null
     * (ADR-041 addendum 2026-11-24, point 3; financial.md «no tax line before «Abrechnung ab»»): null while the mandator is NOT liable, while the day is not set (a liable
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
     * «no tax line before Abrechnung ab» covers (ADR-041 addendum 2026-11-24, point 3), for a posting ($stored null), a change
     * (both) and a removal ($request null):
     *
     *   - a removal never crosses it (retract / delete of an existing tax entry before the day —
     *     the return never reads there);
     *   - a version WITHOUT a tax line never crosses it;
     *   - a version WITH a tax line dated on or after the day passes;
     *   - before the day: a NEW entry (`post()`, `reverse()`) is refused; a CHANGE passes only when
     *     the stored entry already carried a tax line and the date does not move earlier — an
     *     existing entry may still be corrected in text or amount. *This change rule is the build
     *     plan's recommendation for an OPEN owner question* (`financial.md` «no tax line before
     *     «Abrechnung ab»») — the owner's answer may change it here and nowhere else.
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
     * line; otherwise the stored entry's date and the new date. The freeze has
     * NO exemption (ADR-042 addendum 2 of 2026-11-25, point 3): a correction
     * entry is dated in an unsettled period like every other entry — the
     * period it corrects is its marker, never its date
     * ({@see assertCorrectionAccepted()}).
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
     * The mandator's VAT accounts are not asked the flag while the lock of the VAT accounts
     * guards them on $date ({@see assertVatLinesComputed()}, ADR-042 addendum 2 of 2026-11-25,
     * point 7): the Sammelbuchung writes its computed tax line there, and the lock is the
     * stricter rule. Outside the window (or without $date) the flag holds as for any account.
     * The mandator is read only when a flagged account is on a line.
     *
     * @param list<PostingLine> $lines
     * @param array<string, Account> $accounts what {@see resolveAccounts()} returned
     * @param bool|list<bool> $applies
     * @param \DateTimeImmutable|null $date the entry's date — the day the lock of the VAT accounts is asked for; null = not asked
     * @throws PostingRefusedException ACCOUNT_MODULE_ONLY
     */
    public function assertManualAllowed(array $lines, array $accounts, bool|array $applies = true, ?\DateTimeImmutable $date = null): void
    {
        $vatLocked = null;
        foreach ($lines as $i => $line) {
            $account = $accounts[$line->account] ?? null;
            if ($account === null || !$account->isModulePostingOnly() || !self::mustBeActive($applies, $i)) {
                continue;
            }
            // Numeric array keys come back as int — compared as strings.
            $vatLocked ??= $date === null ? [] : array_map('strval', array_keys($this->vatAccountMap()->lockedAccounts($date)));
            if (!in_array($account->getNumber(), $vatLocked, true)) {
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
     * The LOCK OF THE VAT ACCOUNTS (ADR-042 addendum 2 of 2026-11-25, point 7): from «Abrechnung
     * ab» to «Abrechnung bis», for a liable mandator, every entry — manual and generated — moves on
     * each of the mandator's VAT accounts (owed, input material, input other) exactly the tax its
     * coded lines compute for that account: Σ (debit − credit) of the lines on the account =
     * Σ sign × tax of the coded lines whose code maps to it ({@see VatAccountMap::taxLines()}, the
     * table the return's reconciliation reads as well — return and lock cannot disagree). The tax
     * is the line's STORED tax (`taxAmount`, signed as the poster means it); the ledger never
     * recomputes a rate. A coded line may itself stand on a VAT account only when that is the
     * input-side account its code maps to — a tax-only line, an input-tax correction, the
     * reverse-charge pair line ({@see VatAccountMap::mayCarryCode()}); for such a line its own
     * amount enters the movement and its tax the expectation, no special case. The settlement
     * account and the transit account «Vorsteuer, noch nicht abgerechnet» are not VAT accounts here.
     *
     * Passes without judging ({@see vatLockExempt()}): the entries of the return (source
     * `vat-return` — the settlement entry and its reversal), the year opening (source `opening`), a
     * reversal made by the ledger ($reversalOf — it mirrors an entry already judged; a difference it
     * leaves shows in the reconciliation and blocks «MWST buchen»), and a CHANGE of an entry whose
     * lines stay as stored ($stored, S4-6: an old entry stays correctable in text and date). The
     * window ({@see VatAccountMap::lockedAccounts()} on the entry's date): nothing is locked while
     * the mandator is not liable, without «Abrechnung ab», before it, after «Abrechnung bis», or when
     * the record cannot be read.
     *
     * The table is read ONCE per instance (one mandator read), and only when no exemption applies.
     * The rate date of a reverse-charge code's input field is the entry's date — for a correction
     * entry the last day of the period it corrects. Call it after the
     * accounts are resolved and the tax codes exist ({@see resolveAccounts()},
     * {@see assertTaxCodesExist()}) — a code the country pack cannot place on a VAT account is refused
     * here as not computable.
     *
     * @param JournalEntry|null $reversalOf the entry a ledger reversal reverses (`LedgerService::reverse()`)
     * @param JournalEntry|null $stored     the entry as stored, for a change (a manual edit, a source's amend); null = a new entry
     * @throws PostingRefusedException TAX_CODE_ON_VAT_ACCOUNT | VAT_LINE_NOT_COMPUTED
     */
    public function assertVatLinesComputed(PostingRequest $request, ?JournalEntry $reversalOf, ?JournalEntry $stored = null): void
    {
        if (self::vatLockExempt($request, $reversalOf, $stored)) {
            return;
        }
        $map    = $this->vatAccountMap();
        $locked = $map->lockedAccounts($request->date);
        if ($locked === []) {
            return;
        }
        $refusal = self::vatLinesRefusal($request, $locked, $map->taxLines(...), $map->mayCarryCode(...), $this->accountLabel(...));
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * Whether the lock of the VAT accounts lets $request pass WITHOUT judging it — before anything is
     * read (see {@see assertVatLinesComputed()}): a ledger reversal, the return's own entries, the year
     * opening, and a change whose lines equal the stored ones. «Equal lines» compares account,
     * debit, credit and the tax data of every line, regardless of order; the line text and the
     * entry's date and text may change.
     */
    public static function vatLockExempt(PostingRequest $request, ?JournalEntry $reversalOf, ?JournalEntry $stored = null): bool
    {
        if ($reversalOf !== null) {
            return true;
        }
        if (in_array($request->sourceType, [VatReturnService::SOURCE_TYPE, YearOpeningService::SOURCE_TYPE], true)) {
            return true;
        }

        return $stored !== null && self::vatLineKeys(array_map(static fn(JournalLine $l) => $l->snapshot(), $stored->getLines()))
            === self::vatLineKeys(array_map(static fn(PostingLine $l) => $l->fingerprint(), $request->lines));
    }

    /**
     * The judgement of the lock, PURE — what {@see assertVatLinesComputed()} decides once the window
     * is open, by the answers of its table. First every coded line on a locked account is asked
     * the predicate (TAX_CODE_ON_VAT_ACCOUNT, the first such line); then per locked account, in the
     * order the entry first touches it, movement against expectation (VAT_LINE_NOT_COMPUTED, the
     * first that differs). Null = the entry passes. A tax line whose account is not locked (an empty
     * mandator field) is not judged.
     *
     * @param array<int|string, string> $locked account number → mandator key ({@see VatAccountMap::lockedAccounts()}; a numeric key may come back as int)
     * @param \Closure(string, \DateTimeImmutable): list<array{key: string, account: ?string, sign: int}> $taxLines     {@see VatAccountMap::taxLines()}
     * @param \Closure(string, string, \DateTimeImmutable): bool                                          $mayCarryCode {@see VatAccountMap::mayCarryCode()}
     * @param (\Closure(string): string)|null                                                             $labelOf      account number → «1170 Vorsteuer …» for the message; null = the number
     */
    public static function vatLinesRefusal(PostingRequest $request, array $locked, \Closure $taxLines, \Closure $mayCarryCode, ?\Closure $labelOf = null): ?PostingRefusedException
    {
        $isLocked = [];
        foreach (array_keys($locked) as $number) {
            $isLocked[(string) $number] = true;
        }
        $labelOf ??= static fn(string $number): string => $number;
        // The rate date: a correction entry's codes are those of the corrected period (its last day,
        // ADR-042 addendum 2 of 2026-11-25, point 4) — the form edition a reverse-charge code's input
        // field is read from. The window of the lock stays the entry's own date.
        $date     = $request->corrects?->to ?? $request->date;
        $zero     = Money::zero($request->currency());

        /** @var array<string, Money> $booked number → Σ (debit − credit) */
        $booked = [];
        /** @var array<string, Money> $computed number → Σ sign × tax */
        $computed = [];
        foreach ($request->lines as $line) {
            if (!isset($isLocked[$line->account])) {
                continue;
            }
            if ($line->hasTax() && !self::mayCarry($mayCarryCode, (string) $line->taxCode, $line->account, $date)) {
                return new PostingRefusedException(
                    PostingRefusedException::TAX_CODE_ON_VAT_ACCOUNT,
                    "A line coded {$line->taxCode} stands on the VAT account {$line->account} — a VAT account carries the computed tax only; "
                    . 'a coded line may stand there only when it is the input-side account its code maps to (lock of the VAT accounts)',
                    $labelOf($line->account),
                    taxCode: (string) $line->taxCode,
                );
            }
        }
        foreach ($request->lines as $line) {
            if (isset($isLocked[$line->account])) {
                $booked[$line->account] = ($booked[$line->account] ?? $zero)->add($line->debit)->subtract($line->credit);
            }
            if (!$line->hasTax() || $line->taxAmount === null || $line->taxAmount->isZero()) {
                continue;
            }
            try {
                $targets = $taxLines((string) $line->taxCode, $date);
            } catch (UnknownTaxCodeException | FormMappingException $e) {
                return new PostingRefusedException(
                    PostingRefusedException::VAT_LINE_NOT_COMPUTED,
                    "The tax of code {$line->taxCode} cannot be placed on a VAT account on " . $date->format('Y-m-d') . ' (' . $e->getMessage() . ') — the lock of the VAT accounts cannot judge the entry',
                    taxCode: (string) $line->taxCode,
                );
            }
            foreach ($targets as $target) {
                $number = $target['account'];
                if ($number === null || !isset($isLocked[$number])) {
                    continue;
                }
                $computed[$number] = ($computed[$number] ?? $zero)->add($line->taxAmount->multiply($target['sign']));
            }
        }
        foreach (array_keys($booked + $computed) as $number) {
            $number = (string) $number;
            $moved  = $booked[$number] ?? $zero;
            $owed   = $computed[$number] ?? $zero;
            if (!$moved->equals($owed)) {
                return new PostingRefusedException(
                    PostingRefusedException::VAT_LINE_NOT_COMPUTED,
                    "VAT account {$number}: the entry moves {$moved->toDecimal()} (debit − credit), its coded lines compute {$owed->toDecimal()} — "
                    . 'a VAT account carries the computed tax only (lock of the VAT accounts)',
                    $labelOf($number),
                    booked: $moved,
                    computed: $owed,
                );
            }
        }

        return null;
    }

    /**
     * financial's German sentence for a refusal of the lock of the VAT accounts — the ONE text
     * source, so the journal, the bank, the receipts, the creditor invoices and debtor say the same.
     * A whole sentence WITHOUT its final full stop ({@see LedgerService::periodRefusalText()}'s
     * convention): the caller names its document in front of it or adds its way out behind it.
     *
     *   - TAX_CODE_ON_VAT_ACCOUNT: «Auf dem MWST-Konto «2200 Geschuldete MWST …» steht eine Zeile mit
     *     MWST-Code UN — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet;
     *     der MWST-Code gehört auf das Gegenkonto»
     *   - VAT_LINE_NOT_COMPUTED: «Auf dem MWST-Konto «1170 Vorsteuer …» stehen 10.00 im Soll, aus den
     *     MWST-Codes der Buchung ergeben sich 8.10 im Soll — ein MWST-Konto nimmt nur die Steuer,
     *     die z77 aus den MWST-Codes berechnet»
     *
     * @throws \InvalidArgumentException not a refusal of the lock
     */
    public static function vatLineRefusalText(PostingRefusedException $e): string
    {
        $account = $e->account !== null ? 'Auf dem MWST-Konto «' . $e->account . '»' : 'Auf einem MWST-Konto';
        $tail    = 'ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes berechnet';

        return match ($e->reason) {
            PostingRefusedException::TAX_CODE_ON_VAT_ACCOUNT => $account . ' steht eine Zeile mit MWST-Code ' . $e->taxCode . ' — ' . $tail . '; der MWST-Code gehört auf das Gegenkonto',
            PostingRefusedException::VAT_LINE_NOT_COMPUTED   => $e->booked === null || $e->computed === null
                ? 'Die Steuer zum MWST-Code ' . $e->taxCode . ' lässt sich keinem MWST-Konto zuordnen — ' . $tail
                : $account . ' ' . ($e->booked->isZero() ? 'steht nichts' : 'stehen ' . self::sided($e->booked))
                    . ', aus den MWST-Codes der Buchung ergeben sich ' . ($e->computed->isZero() ? '0.00' : self::sided($e->computed)) . ' — ' . $tail,
            default => throw new \InvalidArgumentException("Not a refusal of the VAT-account lock: '{$e->reason}'"),
        };
    }

    /** «8.10 im Soll» / «8.10 im Haben» — a debit − credit amount by its side. */
    private static function sided(Money $amount): string
    {
        return AmountFormat::of($amount->isNegative() ? $amount->negate() : $amount) . ($amount->isNegative() ? ' im Haben' : ' im Soll');
    }

    /** The predicate, an unknown or unplaceable code counted as «may not». */
    private static function mayCarry(\Closure $mayCarryCode, string $taxCode, string $account, \DateTimeImmutable $date): bool
    {
        try {
            return $mayCarryCode($taxCode, $account, $date);
        } catch (UnknownTaxCodeException | FormMappingException) {
            return false;
        }
    }

    /**
     * The lines as the lock compares them — account, amounts and tax data, no position, no text,
     * sorted: the same multiset is «unchanged».
     *
     * @param list<array<string, mixed>> $rows `JournalLine::snapshot()` or `PostingLine::fingerprint()`
     * @return list<string>
     */
    private static function vatLineKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = [];
            foreach (['account', 'debit', 'credit', 'tax_code', 'tax_rate', 'tax_base', 'tax_amount'] as $field) {
                $key[$field] = $row[$field] ?? null;
            }
            $keys[] = json_encode($key, JSON_THROW_ON_ERROR);
        }
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** The lock's table, built on first use and kept for this instance (one mandator read). */
    private function vatAccountMap(): VatAccountMap
    {
        return $this->vatAccountMap ??= new VatAccountMap($this->em);
    }

    /** «1170 Vorsteuer …» for a refusal — the number when the account is gone. */
    private function accountLabel(string $number): string
    {
        return $this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->label() ?? $number;
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
