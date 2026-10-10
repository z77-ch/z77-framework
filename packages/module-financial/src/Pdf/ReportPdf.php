<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Module\Financial\Reports\AccountStatement;
use Z77\Module\Financial\Reports\BalanceSheet;
use Z77\Module\Financial\Reports\JournalReport;
use Z77\Module\Financial\Reports\ReportRange;
use Z77\Module\Financial\Reports\StatementComparison;
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
    /** The A4 text width of `pdf/report` (210 − 12 − 10 mm); landscape: 297 − 22. */
    private const WIDTH = 188.0;
    private const WIDTH_LANDSCAPE = 275.0;

    /** One amount column of a statement, per period. */
    private const AMOUNT_WIDTH = 30.0;

    /**
     * The balance sheet at its day — over one or more years side by side (owner 2026-10-10:
     * the last three years; «Vorjahre» off = the one year). $current decides the fault notice.
     */
    public static function balanceSheet(BalanceSheet $current, StatementComparison $comparison, string $issuer, string $printedAt): PdfDocument
    {
        $code = $current->range->year->getCode();

        return self::statement('Bilanz', 'Geschäftsjahr ' . $code . ' · per ' . $current->range->to->format('d.m.Y'), $comparison, $issuer, $printedAt,
            $current->isBalanced() ? '' : 'Aktiven ≠ Passiven — Differenz ' . self::fmt($current->difference()) . '. Das Journal ist nicht ausgeglichen.');
    }

    /** The income statement over the range — over one or more years side by side. */
    public static function incomeStatement(StatementComparison $comparison, ReportRange $range, string $issuer, string $printedAt): PdfDocument
    {
        return self::statement('Erfolgsrechnung', 'Geschäftsjahr ' . $range->year->getCode() . ' · ' . $range->from->format('d.m.Y') . ' – ' . $range->to->format('d.m.Y'), $comparison, $issuer, $printedAt);
    }

    /**
     * A statement through `pdf/report`: Konto | Bezeichnung | one 30 mm amount column per
     * period, the SAME columns in every block so the amounts stand in one line. Nothing wraps:
     * a name that does not fit ends in «…» (owner 2026-10-10). Groups bold, no indent
     * (FIN-UI-007), the extra rows (the year's result) bold, totals bold with a rule.
     */
    private static function statement(string $title, string $subtitle, StatementComparison $comparison, string $issuer, string $printedAt, string $notice = ''): PdfDocument
    {
        $n      = $comparison->width();
        $name   = self::WIDTH - 18 - $n * self::AMOUNT_WIDTH;
        $amt    = static fn(?Money $m): string => $m === null ? '' : self::fmt($m);
        $blocks = [];
        foreach ($comparison->blocks as $block) {
            $columns = [['label' => 'Konto', 'width' => 18], ['label' => $block['title'], 'width' => $name]];
            foreach ($comparison->labels as $label) {
                $columns[] = ['label' => $n === 1 ? 'Betrag' : $label, 'width' => self::AMOUNT_WIDTH, 'align' => 'R'];
            }
            $rows = [];
            foreach ($block['rows'] as $row) {
                $rows[] = ['cells' => [$row['number'], $row['name'], ...array_map($amt, $row['amounts'])], 'bold' => $row['isGroup']];
            }
            if ($block['title'] !== '' && $rows === [] && $block['extra'] === []) {
                $rows[] = ['cells' => ['', 'keine Buchung'], 'muted' => true];
            }
            foreach ($block['extra'] as $row) {
                $rows[] = ['cells' => ['', $row['label'], ...array_map($amt, $row['amounts'])], 'bold' => true];
            }
            $rows[]   = ['cells' => ['', $block['totalLabel'], ...array_map($amt, $block['totals'])], 'bold' => true, 'rule' => true];
            $blocks[] = ['columns' => $columns, 'rows' => $rows, 'wrap' => null, 'header' => $block['title'] !== ''] + ($block['title'] === '' ? ['gapAfter' => 0] : []);
        }

        return PdfDocument::create($title, $issuer)->partial('pdf/report', [
            'title'     => $title,
            'subtitle'  => $subtitle,
            'issuer'    => $issuer,
            'printedAt' => $printedAt,
            'notice'    => $notice,
            'blocks'    => $blocks,
        ], 'Z77\Shared');
    }

    /** Every account with lines: Σ Soll, Σ Haben, the balance on its side; the totals. */
    public static function trialBalance(TrialBalance $report, ReportRange $range, string $issuer, string $printedAt): PdfDocument
    {
        $columns = [
            ['label' => 'Konto', 'width' => 18],
            ['label' => 'Bezeichnung', 'width' => 70],
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
            ['label' => 'Text', 'width' => 62],
            ['label' => 'Gegenkonto', 'width' => 36],
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
            ['label' => 'Text', 'width' => 113],
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

    private static function fmt(?Money $amount): string
    {
        return AmountFormat::of($amount);
    }
}
