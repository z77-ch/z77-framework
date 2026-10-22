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
use Z77\Module\Financial\Reports\JournalSearch;
use Z77\Shared\Paging\Paging;
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
    /** Account statement lines per SCREEN page — a year of a busy bank account is thousands of lines; paper is the PDF, which takes them all (FIN-PDF-001). */
    public const ACCOUNT_STATEMENT_PAGE_SIZE = 500;

    /** Journal report entries per SCREEN page — each entry is 2–n lines; paper is the PDF, which takes them all (FIN-PDF-001). */
    public const JOURNAL_PAGE_SIZE = 200;

    /** How many entries / statement lines the PDF reads per chunk before it releases them (review 2026-10-13). */
    public const PDF_CHUNK = 500;

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

        // The year's result = Σ revenue − Σ expense on their natural sides — summed from the rows,
        // not by building the income statement's two blocks a second time (review 2026-10-13).
        $result = $this->zero();
        foreach ($rows as $row) {
            $result = match ($row->type) {
                AccountType::Revenue => $result->add($row->balance()),
                AccountType::Expense => $result->subtract($row->balance()),
                default              => $result,
            };
        }

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
     * One page of an account's statement — the screen. The account is any account of the
     * chart; a group simply has no lines.
     */
    public function accountStatement(Account $account, ReportRange $range, int $page): AccountStatement
    {
        $lines   = $this->lines();
        $totals  = $lines->accountTotals((int) $account->getId(), (int) $range->year->getId(), $range->fromDay(), $range->toDay());
        $paging  = new Paging($page, self::ACCOUNT_STATEMENT_PAGE_SIZE, $totals['lines']);
        $rows    = $lines->accountLines((int) $account->getId(), (int) $range->year->getId(), $range->fromDay(), $range->toDay(), $paging->offset(), $paging->pageSize);
        $opening = $this->money($totals['opening']);
        $result  = iterator_to_array($this->statementLines($account, $opening, $rows), false);
        // The page's carry = the first line's balance minus its own movement (= the opening on page 1).
        $carry   = $result === [] ? $opening : $this->toNet($account, $result[0]->balance)->subtract($result[0]->debit->subtract($result[0]->credit));

        return $this->statement($account, $totals, $carry, $result, $paging);
    }

    /**
     * EVERY line of an account's statement over the range — the PDF (review 2026-10-13: the PDF
     * hydrated every line and fetched the counter accounts with an `IN` of all entry ids). The
     * lines are a lazy GENERATOR over ONE SQL stream (`JournalLineRepository::streamAccountLines()`,
     * one sort for the window function), the counter accounts fetched per chunk of
     * {@see PDF_CHUNK} lines; `paging` is one page holding all lines (its `total` says whether
     * there are any). Read the lines once.
     */
    public function accountStatementForPdf(Account $account, ReportRange $range): AccountStatement
    {
        $lines  = $this->lines();
        $totals = $lines->accountTotals((int) $account->getId(), (int) $range->year->getId(), $range->fromDay(), $range->toDay());
        $paging = new Paging(1, max(1, $totals['lines']), $totals['lines']);
        $stream = $lines->streamAccountLines((int) $account->getId(), (int) $range->year->getId(), $range->fromDay(), $range->toDay());
        $chunks = (static function (\Generator $rows): \Generator {
            $chunk = [];
            foreach ($rows as $row) {
                $chunk[] = $row;
                if (count($chunk) >= self::PDF_CHUNK) {
                    yield $chunk;
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                yield $chunk;
            }
        })($stream);
        $opening = $this->money($totals['opening']);
        $all     = (function () use ($account, $opening, $chunks): \Generator {
            foreach ($chunks as $chunk) {
                yield from $this->statementLines($account, $opening, $chunk);
            }
        })();

        return $this->statement($account, $totals, $opening, $all, $paging);
    }

    /**
     * Statement rows → lines on the account's natural side, each with its counter account
     * (ONE query for the rows given — a page, or a chunk of the PDF's stream:
     * `JournalLineRepository::linesOfEntries()`).
     *
     * One row per counter line where the pairing is unambiguous (owner 2026-10-22: «ein
     * Kontoblatt sollte jede Buchung zeigen … weiss das Gegenkonto nicht wer sein Gegenüber
     * ist?»), see {@see counterRows()}. A split row carries the entry's date, number and text,
     * the counter line's account and that line's amount on the statement line's side; the
     * running balance steps through the split rows and ends on the line's own running
     * balance, so the totals, the closing and the next line are untouched. Display only — the
     * posting is what it is.
     *
     * @param list<array<string, mixed>> $rows from `JournalLineRepository::accountLines()` / the stream
     * @return \Generator<int, AccountStatementLine>
     */
    private function statementLines(Account $account, Money $opening, array $rows): \Generator
    {
        $entries = $this->lines()->linesOfEntries(array_column($rows, 'entry_id'));
        foreach ($rows as $row) {
            $debit   = $this->money($row['debit']);
            $credit  = $this->money($row['credit']);
            // debit − credit up to and including this line; split rows step from the balance before it
            $running = $opening->add($this->money($row['running']));
            $parts   = $this->counterRows($entries[$row['entry_id']] ?? [], $row['line_id'], (int) $account->getId(), $debit, $credit);
            $step    = $running->subtract($debit->subtract($credit));
            foreach ($parts as $i => $part) {
                $step = $step->add($part['debit']->subtract($part['credit']));
                yield new AccountStatementLine(
                    $row['entry_id'],
                    $row['number'],
                    new \DateTimeImmutable($row['entry_date']),
                    (string) $row['entry_text'],
                    $row['line_text'],
                    $part['count'],
                    $part['number'],
                    $part['name'],
                    $part['debit'],
                    $part['credit'],
                    // the last part ends exactly on the SQL running balance
                    $this->natural($account, $i === count($parts) - 1 ? $running : $step),
                    count($parts) > 1,
                );
            }
        }
    }

    /**
     * The counter account(s) of ONE statement line (owner 2026-10-22, display only):
     *
     *   - the line is the ONLY line on its side (debit or credit) of the entry and no line on
     *     the other side is on the same account → its amount splits EXACTLY into the other
     *     side's lines: one row per counter line with that line's account and amount (1:n — a
     *     sale 1100 / 3200 + 2200 shows two rows on 1100; one row when the other side has one);
     *   - else the other side has exactly ONE line, on another account → that is the counter
     *     account, with the line's own amount (the n side of 1:n — 3200 → 1100);
     *   - else (m:n — a manual Sammelbuchung with several lines on both sides, or the account
     *     itself on both sides) the pairing is ambiguous: one row, the one other account of the
     *     entry when there is just one, «div.» when there are several, «–» when none.
     *
     * An account with several lines in the same entry: each of its lines is its own statement
     * row and is judged on its own — two own lines on the same side are not «the only line»,
     * they fall to the second or third case. Defensive: a split whose parts do not add up to
     * the line exactly (an unbalanced entry written past the ledger) falls back to one row.
     *
     * @param list<array{line_id: int, account_id: int, number: string, name: string, debit: string, credit: string}> $lines the entry's lines
     * @return non-empty-list<array{count: int, number: ?string, name: ?string, debit: Money, credit: Money}>
     */
    private function counterRows(array $lines, int $lineId, int $accountId, Money $debit, Money $credit): array
    {
        $isDebit = $debit->isPositive() && $credit->isZero();
        $sided   = $isDebit || ($credit->isPositive() && $debit->isZero());
        $zero    = $debit->subtract($debit);
        $same    = 0;
        $other   = [];   // the lines on the other side, with their amount
        $others  = [];   // the distinct other accounts of the entry: number → name
        foreach ($lines as $line) {
            if ($line['account_id'] !== $accountId) {
                $others[$line['number']] = $line['name'];
            }
            if ($line['line_id'] === $lineId) {
                continue;
            }
            $lineDebit  = $this->money($line['debit']);
            $lineCredit = $this->money($line['credit']);
            $onDebit    = $lineDebit->isPositive() && $lineCredit->isZero();
            $onCredit   = $lineCredit->isPositive() && $lineDebit->isZero();
            if ($isDebit ? $onDebit : $onCredit) {
                $same++;
            } elseif ($isDebit ? $onCredit : $onDebit) {
                $other[] = $line + ['amount' => $isDebit ? $lineCredit : $lineDebit];
            } else {
                $sided = false;   // a line on both sides or on none — nothing to pair against
            }
        }
        $ownOpposite = array_filter($other, static fn(array $l) => $l['account_id'] === $accountId) !== [];

        if ($sided && $same === 0 && $other !== [] && !$ownOpposite) {
            $amount = $isDebit ? $debit : $credit;
            $sum    = array_reduce($other, static fn(Money $s, array $l) => $s->add($l['amount']), $zero);
            if ($sum->equals($amount)) {
                return array_map(static fn(array $l) => [
                    'count'  => 1,
                    'number' => $l['number'],
                    'name'   => $l['name'],
                    'debit'  => $isDebit ? $l['amount'] : $zero,
                    'credit' => $isDebit ? $zero : $l['amount'],
                ], $other);
            }
        }
        if ($sided && count($other) === 1 && !$ownOpposite) {
            return [['count' => 1, 'number' => $other[0]['number'], 'name' => $other[0]['name'], 'debit' => $debit, 'credit' => $credit]];
        }
        $number = count($others) === 1 ? (string) array_key_first($others) : null;

        return [['count' => count($others), 'number' => $number, 'name' => $number === null ? null : $others[array_key_first($others)], 'debit' => $debit, 'credit' => $credit]];
    }

    /**
     * @param array{opening: string, debit: string, credit: string, lines: int} $totals
     * @param iterable<AccountStatementLine> $lines
     */
    private function statement(Account $account, array $totals, Money $carry, iterable $lines, Paging $paging): AccountStatement
    {
        $opening = $this->money($totals['opening']);
        $debit   = $this->money($totals['debit']);
        $credit  = $this->money($totals['credit']);

        return new AccountStatement(
            $account,
            $this->natural($account, $opening),
            $this->natural($account, $carry),
            $lines,
            $debit,
            $credit,
            $this->natural($account, $opening->add($debit)->subtract($credit)),
            $paging,
        );
    }

    /** debit − credit → the account's natural side (positive there). */
    private function natural(Account $account, Money $net): Money
    {
        return AccountType::from($account->getType())->isDebitNormal() ? $net : $net->negate();
    }

    /** The inverse of {@see natural()} — a natural-side balance back to debit − credit. */
    private function toNet(Account $account, Money $natural): Money
    {
        return $this->natural($account, $natural);
    }

    /** One page of the journal report — the screen. */
    public function journal(ReportRange $range, int $page, ?JournalSearch $criteria = null): JournalReport
    {
        /** @var JournalEntryRepository $entries */
        $entries = $this->em->getRepository(JournalEntry::class);
        // The column search (owner 2026-10-15) runs in the database, held to the range — without
        // one, the whole range in date and number order.
        $search  = ($criteria ?? new JournalSearch(sort: 'date', descending: false))
            ->within((int) $range->year->getId(), $range->fromDay(), $range->toDay());
        $summary = $entries->searchSummary($search);
        $paging  = new Paging($page, self::JOURNAL_PAGE_SIZE, $summary['entries']);

        return new JournalReport(
            $entries->search($search, $paging->offset(), $paging->pageSize),
            $this->money($summary['debit']),
            $this->money($summary['credit']),
            $paging,
        );
    }

    /**
     * EVERY entry the screen's search finds in the range, in the screen's order — the PDF prints
     * what the list shows (owner 2026-10-16), only not paged; without a search the whole range,
     * oldest first. The entries are a lazy generator reading {@see PDF_CHUNK} at a time and
     * releasing each chunk from the EntityManager (`JournalEntryRepository::searchInChunks()`,
     * review 2026-10-13); `paging` is one page holding all entries. Read the entries once, read-only.
     */
    public function journalForPdf(ReportRange $range, ?JournalSearch $criteria = null): JournalReport
    {
        /** @var JournalEntryRepository $entries */
        $entries = $this->em->getRepository(JournalEntry::class);
        $search  = ($criteria ?? new JournalSearch(sort: 'date', descending: false))
            ->within((int) $range->year->getId(), $range->fromDay(), $range->toDay());
        $summary = $entries->searchSummary($search);

        return new JournalReport(
            $entries->searchInChunks($search, self::PDF_CHUNK),
            $this->money($summary['debit']),
            $this->money($summary['credit']),
            new Paging(1, max(1, $summary['entries']), $summary['entries']),
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
