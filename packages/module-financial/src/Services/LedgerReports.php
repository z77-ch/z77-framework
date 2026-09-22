<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Reports\AccountBalance;
use Z77\Module\Financial\Reports\AccountStatement;
use Z77\Module\Financial\Reports\AccountStatementLine;
use Z77\Module\Financial\Reports\BalanceSheet;
use Z77\Module\Financial\Reports\IncomeStatement;
use Z77\Module\Financial\Reports\JournalReport;
use Z77\Module\Financial\Reports\Paging;
use Z77\Module\Financial\Reports\ReportRange;
use Z77\Module\Financial\Reports\StatementLine;
use Z77\Module\Financial\Reports\StatementSection;
use Z77\Module\Financial\Reports\TrialBalance;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\JournalEntryRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The ledger reports of P2 part 3 (plan §5.5): trial balance, balance sheet,
 * income statement, account statement and journal. Read-only — no unit of
 * work, nothing written.
 *
 * The numbers come from SQL aggregates over `journal_line`
 * ({@see JournalLineRepository}, Doctrine-only), never from hydrated lines;
 * what is hydrated is the chart (a few hundred accounts, for the group tree)
 * and one bounded page of the journal report (the part-2 fetch-join). Every
 * SQL amount is a DECIMAL string turned into `Money` by `Money::fromDecimal()`
 * in the base currency — no float anywhere on the path (money.md). Base
 * currency only (ADR-042 decision 4): there is no other.
 *
 * Sign convention: every balance a report shows is POSITIVE on the account's
 * natural side ({@see AccountType::isDebitNormal()}); the trial balance splits
 * the balance into «Saldo Soll» / «Saldo Haben» instead.
 *
 * One fiscal year per report ({@see ReportRange}). Before P5 there is no
 * opening entry, so a later year starts at zero (FIN-REPORT-001).
 */
final class LedgerReports
{
    /** Account statement lines per page — a year of a busy bank account is thousands of lines; 500 print as ~12 pages. */
    public const ACCOUNT_STATEMENT_PAGE_SIZE = 500;

    /** Journal report entries per page — each entry is 2–n lines, so a page stays a readable print of ~15 pages. */
    public const JOURNAL_PAGE_SIZE = 200;

    private readonly string $currency;

    public function __construct(private readonly UnifiedEntityManager $em)
    {
        $this->currency = LedgerService::baseCurrency();
    }

    public function trialBalance(ReportRange $range): TrialBalance
    {
        $rows = $this->balances($range);
        [$debit, $credit, $debitBalance, $creditBalance] = [$this->zero(), $this->zero(), $this->zero(), $this->zero()];
        foreach ($rows as $row) {
            $debit         = $debit->add($row->debit);
            $credit        = $credit->add($row->credit);
            $debitBalance  = $debitBalance->add($row->debitBalance());
            $creditBalance = $creditBalance->add($row->creditBalance());
        }

        return new TrialBalance($rows, $debit, $credit, $debitBalance, $creditBalance);
    }

    /**
     * The balance sheet at `$range->to` — always from the year's FIRST day
     * (a balance sheet is a statement at a day; a «from» would make it a
     * movement report), with the current result in equity.
     */
    public function balanceSheet(ReportRange $range): BalanceSheet
    {
        $range = $range->fromYearStart();
        $rows  = $this->balances($range);
        $chart = $this->chart();

        $result = $this->section('Ertrag', $rows, [AccountType::Revenue], $chart)->total
            ->subtract($this->section('Aufwand', $rows, [AccountType::Expense], $chart)->total);

        return new BalanceSheet(
            $range,
            $this->section('Aktiven', $rows, [AccountType::Asset], $chart),
            $this->section('Fremdkapital', $rows, [AccountType::Liability], $chart),
            $this->section('Eigenkapital', $rows, [AccountType::Equity], $chart),
            $result,
        );
    }

    public function incomeStatement(ReportRange $range): IncomeStatement
    {
        $rows  = $this->balances($range);
        $chart = $this->chart();

        return new IncomeStatement(
            $this->section('Ertrag', $rows, [AccountType::Revenue], $chart),
            $this->section('Aufwand', $rows, [AccountType::Expense], $chart),
        );
    }

    /**
     * One page of an account's statement. The account is any account of the
     * chart — a group simply has no lines.
     */
    public function accountStatement(Account $account, ReportRange $range, int $page): AccountStatement
    {
        $lines     = $this->lines();
        $accountId = (int) $account->getId();
        $yearId    = (int) $range->year->getId();
        $debitSide = AccountType::from($account->getType())->isDebitNormal();
        /** debit − credit → the natural side */
        $natural   = fn(Money $net): Money => $debitSide ? $net : $net->negate();

        $totals  = $lines->accountTotals($accountId, $yearId, $range->fromDay(), $range->toDay());
        $opening = $this->money($totals['opening']);
        $debit   = $this->money($totals['debit']);
        $credit  = $this->money($totals['credit']);
        $paging  = new Paging($page, self::ACCOUNT_STATEMENT_PAGE_SIZE, $totals['lines']);

        $rows     = $lines->accountLines($accountId, $yearId, $range->fromDay(), $range->toDay(), $paging->offset(), $paging->pageSize);
        $counters = $lines->counterAccounts(array_column($rows, 'entry_id'), $accountId);
        $result   = [];
        $carry    = $opening;
        foreach ($rows as $i => $row) {
            $lineDebit  = $this->money($row['debit']);
            $lineCredit = $this->money($row['credit']);
            $balance    = $opening->add($this->money($row['running']));   // debit − credit up to and including this line
            if ($i === 0) {
                $carry = $balance->subtract($lineDebit->subtract($lineCredit));
            }
            $counter  = $counters[$row['entry_id']] ?? null;
            $result[] = new AccountStatementLine(
                $row['entry_id'],
                $row['number'],
                new \DateTimeImmutable($row['entry_date']),
                (string) $row['entry_text'],
                $row['line_text'],
                $counter['count'] ?? 0,
                $counter['number'] ?? null,
                $counter['name'] ?? null,
                $lineDebit,
                $lineCredit,
                $natural($balance),
            );
        }

        return new AccountStatement(
            $account,
            $natural($opening),
            $natural($carry),
            $result,
            $debit,
            $credit,
            $natural($opening->add($debit)->subtract($credit)),
            $paging,
        );
    }

    public function journal(ReportRange $range, int $page): JournalReport
    {
        /** @var JournalEntryRepository $entries */
        $entries = $this->em->getRepository(JournalEntry::class);
        $summary = $entries->rangeSummary($range->year, $range->fromDay(), $range->toDay());
        $paging  = new Paging($page, self::JOURNAL_PAGE_SIZE, $summary['entries']);

        return new JournalReport(
            $entries->chronological($range->year, $range->fromDay(), $range->toDay(), $paging->offset(), $paging->pageSize),
            $this->money($summary['debit']),
            $this->money($summary['credit']),
            $paging,
        );
    }

    // ── building blocks ─────────────────────────────────────────────────

    /** @return list<AccountBalance> chart order */
    private function balances(ReportRange $range): array
    {
        return array_map(fn(array $r) => new AccountBalance(
            $r['account_id'],
            (string) $r['number'],
            (string) $r['name'],
            AccountType::from((string) $r['type']),
            $this->money($r['debit']),
            $this->money($r['credit']),
        ), $this->lines()->balancesByAccount((int) $range->year->getId(), $range->fromDay(), $range->toDay()));
    }

    /**
     * The chart as a tree: id → account, and parent id (0 for a root) →
     * child ids in number order (`allInOrder()` is sorted by number).
     *
     * @return array{accounts: array<int, Account>, children: array<int, list<int>>}
     */
    private function chart(): array
    {
        /** @var AccountRepository $repository */
        $repository = $this->em->getRepository(Account::class);
        $accounts   = [];
        $children   = [];
        foreach ($repository->allInOrder() as $account) {
            $accounts[(int) $account->getId()] = $account;
            $children[(int) $account->getParent()?->getId()][] = (int) $account->getId();
        }

        return ['accounts' => $accounts, 'children' => $children];
    }

    /**
     * One block of a statement: the accounts of $types with lines in the
     * range, their balances on the natural side, and every group on their
     * parent chains with its subtotal — walked top-down in chart order, so a
     * group without such an account never appears.
     *
     * @param list<AccountBalance> $rows
     * @param list<AccountType> $types
     * @param array{accounts: array<int, Account>, children: array<int, list<int>>} $chart
     */
    private function section(string $title, array $rows, array $types, array $chart): StatementSection
    {
        $amounts = [];   // account or group id → natural amount
        $total   = $this->zero();
        foreach ($rows as $row) {
            if (!in_array($row->type, $types, true)) {
                continue;
            }
            $balance = $row->balance();
            $total   = $total->add($balance);
            $amounts[$row->accountId] = ($amounts[$row->accountId] ?? $this->zero())->add($balance);
            // Up the parent chain, with a visited set against a cycle already in the data.
            $seen = [$row->accountId => true];
            for ($parent = $chart['accounts'][$row->accountId]?->getParent(); $parent !== null && !isset($seen[(int) $parent->getId()]); $parent = $parent->getParent()) {
                $seen[(int) $parent->getId()] = true;
                $amounts[(int) $parent->getId()] = ($amounts[(int) $parent->getId()] ?? $this->zero())->add($balance);
            }
        }

        $lines   = [];
        $reached = [];
        // Top-down from the roots: a node reachable from a root has exactly one
        // path to it, so the walk ends even if the data held a detached cycle.
        $walk = function (int $parentId, int $depth) use (&$walk, &$lines, &$reached, $amounts, $chart): void {
            foreach ($chart['children'][$parentId] ?? [] as $id) {
                if (!isset($amounts[$id])) {
                    continue;
                }
                $account      = $chart['accounts'][$id];
                $reached[$id] = true;
                $lines[]      = new StatementLine($account->getNumber(), $account->getName(), $depth, !$account->isPostable(), $amounts[$id]);
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        // An account whose parent chain never reaches a root (a cycle or an
        // orphaned group in the data — the validator refuses both, a direct
        // database edit or an import may not) is not reached by the walk. It
        // is NEVER dropped silently: appended flat at depth 0 and named, so
        // the page can say the chart needs fixing. Afterwards Σ of the depth-0
        // rows equals the block total — the invariant a reader relies on.
        $unplaced = [];
        foreach ($rows as $row) {
            if (in_array($row->type, $types, true) && !isset($reached[$row->accountId])) {
                $account    = $chart['accounts'][$row->accountId];
                $unplaced[] = $account->getNumber();
                $lines[]    = new StatementLine($account->getNumber(), $account->getName(), 0, false, $amounts[$row->accountId]);
            }
        }
        $topLevel = $this->zero();
        foreach ($lines as $line) {
            $topLevel = $line->depth === 0 ? $topLevel->add($line->amount) : $topLevel;
        }
        if (!$topLevel->equals($total)) {
            throw new \LogicException("Statement block {$title}: the top-level rows ({$topLevel->toDecimal()}) do not add up to the block total ({$total->toDecimal()})");
        }

        return new StatementSection($title, $lines, $total, $unplaced);
    }

    private function lines(): JournalLineRepository
    {
        return $this->em->getRepository(JournalLine::class);
    }

    /** A DECIMAL string from SQL → Money, through Money's own parsing (money.md) — never a float. */
    private function money(string $decimal): Money
    {
        return Money::fromDecimal($decimal, $this->currency);
    }

    private function zero(): Money
    {
        return Money::zero($this->currency);
    }
}
