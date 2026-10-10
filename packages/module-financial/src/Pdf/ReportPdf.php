<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Module\Financial\Reports\AccountStatement;
use Z77\Module\Financial\Reports\BalanceSheet;
use Z77\Module\Financial\Reports\IncomeStatement;
use Z77\Module\Financial\Reports\JournalReport;
use Z77\Module\Financial\Reports\ReportRange;
use Z77\Module\Financial\Reports\StatementSection;
use Z77\Module\Financial\Reports\TrialBalance;
use Z77\Module\Financial\Ui\ManualEntryForm;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Pdf\PdfDocument;

/**
 * The ledger reports as PDFs (FIN-PDF-001, owner 2026-10-10: «sauberer und sofort
 * downloadbar ohne Browsereinfluss»). This class only turns a report object into the BLOCKS
 * of the kernel's shared layout `Z77\Shared` `pdf/report` (running head, foot, tables) —
 * the layout draws. Rendered on request, never stored, like the invoice.
 *
 * The hierarchy reads as on the screen (FIN-UI-007): no indent, a group row bold, an account
 * row plain, a total bold with a rule above. Every block of one report uses the SAME columns,
 * so the amounts stand in one line from the first page to the last.
 */
final class ReportPdf
{
    /** Konto | Bezeichnung | Betrag — 180 mm, the A4 text width of `pdf/report`. */
    private const STATEMENT_COLUMNS = [
        ['label' => 'Konto',       'width' => 20],
        ['label' => 'Bezeichnung', 'width' => 125],
        ['label' => 'Betrag',      'width' => 35, 'align' => 'R'],
    ];

    /** The balance sheet at its day: Aktiven, Fremdkapital, Eigenkapital (with the year's result), Total Passiven. */
    public static function balanceSheet(BalanceSheet $report, string $issuer, string $printedAt): PdfDocument
    {
        $code     = $report->range->year->getCode();
        $result   = $report->result;
        $blocks   = [
            self::statementBlock($report->assets, 'Total Aktiven', $report->assets->total),
            self::statementBlock($report->liabilities, 'Total Fremdkapital', $report->liabilities->total),
            self::statementBlock($report->equity, 'Total Eigenkapital', $report->totalEquity(), [[
                'label'  => ($result->isNegative() ? 'Jahresverlust ' : 'Jahresgewinn ') . $code,
                'amount' => $result,
            ]]),
            // Total Passiven closes the sheet; Total Aktiven is not repeated (owner 2026-10-10).
            [
                'columns'  => self::STATEMENT_COLUMNS,
                'header'   => false,
                'rows'     => [['cells' => ['', 'Total Passiven', self::fmt($report->totalLiabilitiesAndEquity())], 'bold' => true, 'rule' => true]],
                'gapAfter' => 0,
            ],
        ];

        return PdfDocument::create('Bilanz ' . $code, $issuer)->partial('pdf/report', [
            'title'     => 'Bilanz',
            'subtitle'  => 'Geschäftsjahr ' . $code . ' · per ' . $report->range->to->format('d.m.Y'),
            'issuer'    => $issuer,
            'printedAt' => $printedAt,
            'notice'    => $report->isBalanced() ? '' : 'Aktiven ≠ Passiven — Differenz ' . self::fmt($report->difference()) . '. Das Journal ist nicht ausgeglichen.',
            'blocks'    => $blocks,
        ], 'Z77\\Shared');
    }

    /** The income statement over the range: Ertrag, Aufwand, the result (Ertrag − Aufwand). */
    public static function incomeStatement(IncomeStatement $report, ReportRange $range, string $issuer, string $printedAt): PdfDocument
    {
        $code   = $range->year->getCode();
        $result = $report->result();
        $blocks = [
            self::statementBlock($report->revenue, 'Total Ertrag', $report->revenue->total),
            self::statementBlock($report->expense, 'Total Aufwand', $report->expense->total),
            [
                'columns'  => self::STATEMENT_COLUMNS,
                'header'   => false,
                'rows'     => [['cells' => ['', ($result->isNegative() ? 'Verlust' : 'Gewinn') . ' (Ertrag − Aufwand)', self::fmt($result)], 'bold' => true, 'rule' => true]],
                'gapAfter' => 0,
            ],
        ];

        return PdfDocument::create('Erfolgsrechnung ' . $code, $issuer)->partial('pdf/report', [
            'title'     => 'Erfolgsrechnung',
            'subtitle'  => 'Geschäftsjahr ' . $code . ' · ' . $range->from->format('d.m.Y') . ' – ' . $range->to->format('d.m.Y'),
            'issuer'    => $issuer,
            'printedAt' => $printedAt,
            'blocks'    => $blocks,
        ], 'Z77\\Shared');
    }

    /** Every account with lines: Σ Soll, Σ Haben, the balance on its side; the totals. */
    public static function trialBalance(TrialBalance $report, ReportRange $range, string $issuer, string $printedAt): PdfDocument
    {
        $columns = [
            ['label' => 'Konto', 'width' => 18],
            ['label' => 'Bezeichnung', 'width' => 62],
            ['label' => 'Soll', 'width' => 25, 'align' => 'R'],
            ['label' => 'Haben', 'width' => 25, 'align' => 'R'],
            ['label' => 'Saldo Soll', 'width' => 25, 'align' => 'R'],
            ['label' => 'Saldo Haben', 'width' => 25, 'align' => 'R'],
        ];
        $blank = static fn(Money $m): string => $m->isZero() ? '' : self::fmt($m);
        $rows  = [];
        foreach ($report->rows as $row) {
            $rows[] = ['cells' => [$row->number, $row->name, self::fmt($row->debit), self::fmt($row->credit), $blank($row->debitBalance()), $blank($row->creditBalance())]];
        }
        if ($rows === []) {
            $rows[] = ['cells' => ['', 'Keine Buchung in diesem Zeitraum.', '', '', '', ''], 'muted' => true];
        }
        $rows[] = ['cells' => ['', 'Total', self::fmt($report->totalDebit), self::fmt($report->totalCredit), self::fmt($report->totalDebitBalance), self::fmt($report->totalCreditBalance)], 'bold' => true, 'rule' => true];

        return self::document('Saldobilanz', $range, $issuer, $printedAt, [['columns' => $columns, 'rows' => $rows, 'wrap' => 1]],
            $report->rows !== [] && !$report->isBalanced() ? 'Soll ≠ Haben — die Totale stimmen nicht überein. Das Journal ist nicht ausgeglichen.' : '');
    }

    /** One account's lines over the range: the opening balance, every line with the running balance, the totals and the closing balance. */
    public static function accountStatement(AccountStatement $report, ReportRange $range, string $issuer, string $printedAt): PdfDocument
    {
        $columns = [
            ['label' => 'Datum', 'width' => 18],
            ['label' => 'Nr.', 'width' => 12, 'align' => 'R'],
            ['label' => 'Text', 'width' => 58],
            ['label' => 'Gegenkonto', 'width' => 32],
            ['label' => 'Soll', 'width' => 20, 'align' => 'R'],
            ['label' => 'Haben', 'width' => 20, 'align' => 'R'],
            ['label' => 'Saldo', 'width' => 20, 'align' => 'R'],
        ];
        $blank = static fn(Money $m): string => $m->isZero() ? '' : self::fmt($m);
        $rows  = [['cells' => [$range->from->format('d.m.Y'), '', 'Anfangssaldo', '', '', '', self::fmt($report->opening)], 'muted' => true]];
        foreach ($report->lines as $line) {
            $counter = $line->hasSeveralCounterAccounts() ? 'div.' : ($line->counterNumber === null ? '–' : trim($line->counterNumber . ' ' . (string) $line->counterName));
            $text    = $line->text . ($line->lineText !== null && $line->lineText !== '' ? ' · ' . $line->lineText : '');
            $rows[]  = ['cells' => [$line->date->format('d.m.Y'), (string) $line->entryNumber, $text, $counter, $blank($line->debit), $blank($line->credit), self::fmt($line->balance)]];
        }
        if ($report->lines === []) {
            $rows[] = ['cells' => ['', '', 'Keine Buchung im Zeitraum.', '', '', '', ''], 'muted' => true];
        }
        $rows[] = ['cells' => [$range->to->format('d.m.Y'), '', 'Total Zeitraum / Schlusssaldo', '', self::fmt($report->totalDebit), self::fmt($report->totalCredit), self::fmt($report->closing)], 'bold' => true, 'rule' => true];

        $account = $report->account;

        return self::document('Kontoblatt ' . $account->getNumber() . ' ' . $account->getName(), $range, $issuer, $printedAt, [['columns' => $columns, 'rows' => $rows, 'wrap' => 2]]);
    }

    /** The journal of the range, oldest first: each entry bold, its lines below; landscape for the width. */
    public static function journal(JournalReport $report, ReportRange $range, string $issuer, string $printedAt): PdfDocument
    {
        $columns = [
            ['label' => 'Datum', 'width' => 20],
            ['label' => 'Nr.', 'width' => 14, 'align' => 'R'],
            ['label' => 'Konto', 'width' => 18],
            ['label' => 'Text', 'width' => 105],
            ['label' => 'MWST', 'width' => 50],
            ['label' => 'Soll', 'width' => 30, 'align' => 'R'],
            ['label' => 'Haben', 'width' => 30, 'align' => 'R'],
        ];
        $blank = static fn(Money $m): string => $m->isZero() ? '' : self::fmt($m);
        $rows  = [];
        foreach ($report->entries as $entry) {
            $reversal = $entry->isReversal() ? ' · Storno von ' . $entry->getReversalOf()->getFiscalYear()->getCode() . '/' . $entry->getReversalOf()->getNumber() : '';
            $rows[]   = ['cells' => [$entry->getDate()->format('d.m.Y'), (string) $entry->getNumber(), '', $entry->getText() . $reversal, '', '', ''], 'bold' => true, 'rule' => true];
            foreach ($entry->getLines() as $line) {
                $text   = $line->getAccount()->getName() . ($line->getText() !== null && $line->getText() !== '' ? ' · ' . $line->getText() : '');
                $tax    = $line->hasTax() ? $line->getTaxCode() . ' ' . ManualEntryForm::percent((int) $line->getTaxRate()) . ' · ' . self::fmt($line->getTaxAmount()) : '';
                $rows[] = ['cells' => ['', '', $line->getAccount()->getNumber(), $text, $tax, $blank($line->getDebit()), $blank($line->getCredit())]];
            }
        }
        if ($rows === []) {
            $rows[] = ['cells' => ['', '', '', 'Keine Buchung in diesem Zeitraum.', '', '', ''], 'muted' => true];
        }
        $rows[] = ['cells' => ['', '', '', 'Total Zeitraum (' . $report->paging->total . ' Buchungen)', '', self::fmt($report->totalDebit), self::fmt($report->totalCredit)], 'bold' => true, 'rule' => true];

        return self::document('Journal', $range, $issuer, $printedAt, [['columns' => $columns, 'rows' => $rows, 'wrap' => 3]], '', 'L');
    }

    /** A report over a range through `pdf/report` — the frame the three list reports share. */
    private static function document(string $title, ReportRange $range, string $issuer, string $printedAt, array $blocks, string $notice = '', string $orientation = 'P'): PdfDocument
    {
        $code = $range->year->getCode();

        return PdfDocument::create($title . ' ' . $code, $issuer, $orientation)->partial('pdf/report', [
            'title'     => $title,
            'subtitle'  => 'Geschäftsjahr ' . $code . ' · ' . $range->from->format('d.m.Y') . ' – ' . $range->to->format('d.m.Y'),
            'issuer'    => $issuer,
            'printedAt' => $printedAt,
            'notice'    => $notice,
            'blocks'    => $blocks,
        ], 'Z77\\Shared');
    }

    /** «bilanz-2026-per-2026-12-31.pdf» — kebab-case lower (file names follow the layer). */
    public static function fileName(string $title, string $yearCode, string $day, ?string $from = null): string
    {
        // A statement AT a day says «per», one over a range says «von … bis» (erfolgsrechnung-2026-von-…-bis-….pdf).
        $range = $from === null ? ' per ' . $day : ' von ' . $from . ' bis ' . $day;

        return Naming::toSlug($title . ' ' . $yearCode . $range) . '.pdf';
    }

    /**
     * One statement block: the section's lines (groups bold, accounts plain, no indent), the
     * caller's extra lines (the year's result in equity), the total with a rule.
     *
     * @param list<array{label: string, amount: Money}> $extra
     * @return array<string, mixed>
     */
    private static function statementBlock(StatementSection $section, string $totalLabel, Money $total, array $extra = []): array
    {
        $rows = [];
        foreach ($section->lines as $line) {
            $rows[] = ['cells' => [$line->number, $line->name, self::fmt($line->amount)], 'bold' => $line->isGroup];
        }
        if ($rows === [] && $extra === []) {
            $rows[] = ['cells' => ['', 'keine Buchung', ''], 'muted' => true];
        }
        foreach ($extra as $row) {
            // A position of the block (OR 959a: the year's result is an equity item), bold.
            $rows[] = ['cells' => ['', $row['label'], self::fmt($row['amount'])], 'bold' => true];
        }
        $rows[] = ['cells' => ['', $totalLabel, self::fmt($total)], 'bold' => true, 'rule' => true];

        return [
            'columns' => array_replace(self::STATEMENT_COLUMNS, [1 => ['label' => $section->title, 'width' => 125]]),
            'rows'    => $rows,
            'wrap'    => 1,
        ];
    }

    private static function fmt(?Money $amount): string
    {
        return AmountFormat::of($amount);
    }
}
