<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Module\Financial\Reports\BalanceSheet;
use Z77\Module\Financial\Reports\IncomeStatement;
use Z77\Module\Financial\Reports\ReportRange;
use Z77\Module\Financial\Reports\StatementSection;
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
        ];
    }

    private static function fmt(?Money $amount): string
    {
        return AmountFormat::of($amount);
    }
}
