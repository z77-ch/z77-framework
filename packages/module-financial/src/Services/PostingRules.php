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
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The rules a posting must pass that only the database can answer (plan
 * §5.4, ADR-042 decision 10) — shared by `LedgerService::post()` and the
 * manual edit in `ManualEntryService`, so both ask the same questions:
 *
 *   - which fiscal year and period the date falls into (none → refused);
 *   - whether that period accepts the posting: `closed` never,
 *     `vat-settled` only without a tax line, `open` always;
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
     * nothing; `vat-settled` takes an entry WITHOUT a tax line (the return is
     * filed — the lines it summed cannot change).
     *
     * @throws PostingRefusedException PERIOD_CLOSED | PERIOD_VAT_SETTLED
     */
    public function assertPeriodAccepts(Period $period, bool $withTaxLine): void
    {
        $span = $period->getStartDate()->format('d.m.Y') . '–' . $period->getEndDate()->format('d.m.Y');
        if ($period->getState() === PeriodState::Closed->value) {
            throw new PostingRefusedException(PostingRefusedException::PERIOD_CLOSED, "Period {$span} is closed — nothing posts into it; correct by a reversal in an open period");
        }
        if ($period->getState() === PeriodState::VatSettled->value && $withTaxLine) {
            throw new PostingRefusedException(PostingRefusedException::PERIOD_VAT_SETTLED, "Period {$span} is VAT-settled — a line with a tax code cannot be posted into it any more");
        }
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
