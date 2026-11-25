<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnCorrectionBlock;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * Layout config, URL root and the German display names of the VAT return screens (P5 part 2,
 * stage S1b; ADR-018 mount pattern): the host's `Ui/Config/Finance/vatReturnControllerConfig.inc.php`
 * in module-backend delegates here (one line) and pins the page body to the list. The other
 * actions swap that section for their own template in {@see VatReturnControllerTrait}.
 */
final class VatReturnLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\\Module\\Financial';

    /** Finanzen › MWST-Abrechnungen. */
    public const BASE = '/backend/finance/vat-return';

    /** The other backend mounts a VAT return screen links to — the places a finding or a refusal names. */
    public const JOURNAL_BASE  = '/backend/finance/journal';
    public const REPORT_BASE   = '/backend/finance/report';
    public const MANDATOR_URL  = '/backend/finance/mandator/edit';
    public const TAX_CODES_URL = '/backend/finance/tax-code/list';
    public const FISCAL_YEAR_URL = '/backend/finance/fiscal-year/list';
    /** Finanzen › Änderungsprotokoll, tab «Abschlüsse und MWST» — the whole protocol of the returns (owner decision 13 of 2026-11-25). */
    public const PROTOCOL_URL = '/backend/finance/change-log/closes-and-vat';

    /** How many protocol rows the list shows under the table, newest first (owner decision 9 of 2026-11-25). */
    public const LOG_ROWS = 3;

    /**
     * The states of a return as the screens name them (owner decision 9 of 2026-11-25): a period
     * without a live return is «offen», a live one «gebucht», a stored revision that was lifted
     * «aufgehoben» — key → German label; the icon of the list's state cell beside it.
     */
    public const STATE_LABELS = [
        'open'   => 'offen',
        'posted' => 'gebucht',
        'lifted' => 'aufgehoben',
    ];
    public const STATE_ICONS = [
        'open'   => 'eye',
        'posted' => 'lock',
        'lifted' => 'x',
    ];

    /** What a protocol row did — `VatReturnLogAction` value → German (the list's last three rows). */
    public const LOG_ACTIONS = [
        'post' => 'MWST gebucht',
        'lift' => 'MWST aufgehoben',
    ];

    /** The three views of one return — URL action → tab label, in reading order (screen decision 8 / 9). */
    public const TABS = [
        'form'           => 'Formular',
        'origin'         => 'Herkunft',
        'reconciliation' => 'Abstimmung',
    ];

    /** The sections of the form (the ESTV form's own headings) — section key → heading. */
    public const SECTIONS = [
        'turnover' => 'I · Umsatz',
        'tax'      => 'II · Steuerberechnung',
        'input'    => 'III · Vorsteuer',
        'payable'  => '',
    ];

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/VatReturnController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }

    /** «3. Quartal 2026», «Juli 2026», «Jahr 2026» — the name of a period on every screen and in the PDF ({@see VatPeriod::label()}, the one source). */
    public static function periodLabel(VatPeriod $period): string
    {
        return $period->label();
    }

    /** «Effektive Methode · Vereinbartes Entgelt» — the method a posted return was computed with (its snapshot, not today's settings). */
    public static function storedMethodLabel(\Z77\Module\Financial\Entities\VatReturn $return): string
    {
        return (\Z77\Module\Mandator\Ui\MandatorLayout::VAT_METHODS[$return->getMethod()->value] ?? $return->getMethod()->value)
            . ' · ' . (\Z77\Module\Mandator\Ui\MandatorLayout::VAT_CONSIDERATIONS[$return->getConsideration()->value] ?? $return->getConsideration()->value);
    }

    /** «2026/1188» — a journal entry as the screens name it. */
    public static function entryRef(\Z77\Module\Financial\Entities\JournalEntry $entry): string
    {
        return $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
    }

    /** «01.07.2026 – 30.09.2026». */
    public static function periodSpan(VatPeriod $period): string
    {
        return $period->from->format('d.m.Y') . ' – ' . $period->to->format('d.m.Y');
    }

    /** The section of the form a field kind stands in — by its role, never by a field number. */
    public static function section(FieldKind $kind): string
    {
        return match ($kind) {
            FieldKind::Total, FieldKind::Deduction, FieldKind::Reduction, FieldKind::DeductionsTotal, FieldKind::TaxableTotal => 'turnover',
            FieldKind::Rate, FieldKind::Acquisition, FieldKind::TaxTotal                                                       => 'tax',
            FieldKind::Input, FieldKind::InputCorrection, FieldKind::InputTotal                                                => 'input',
            FieldKind::Payable, FieldKind::Credit                                                                              => 'payable',
        };
    }

    /** A field whose figure is turnover (the column «Umsatz») — the others carry tax (the column «Steuer»). */
    public static function isTurnover(FieldKind $kind): bool
    {
        return self::section($kind) === 'turnover';
    }

    /** A total of the form (bold, never filled by journal lines). */
    public static function isTotal(FieldKind $kind): bool
    {
        return in_array($kind, [FieldKind::DeductionsTotal, FieldKind::TaxableTotal, FieldKind::TaxTotal, FieldKind::InputTotal], true);
    }

    /** The title of the block a return carries for its correction entries (board S3-2) — page, window, PDF and voucher. */
    public const CORRECTIONS_TITLE = 'Korrekturen früherer Perioden — separat im ePortal melden';

    /**
     * «958.82211» — every decimal an exact amount has beyond the Rappen, at least the two of the
     * Rappen, grouped like {@see \Z77\Shared\Money\AmountFormat} («−» for a negative one). What the
     * screens show small under a tax amount and the PDF in its column «genau» (owner B-2: every sum
     * can be recomputed).
     */
    public static function exact(ExactAmount $amount): string
    {
        [$units, $fraction] = explode('.', ltrim($amount->toDecimal(), '-'));
        $fraction = str_pad(rtrim($fraction, '0'), 2, '0');
        $grouped  = strrev(implode("'", str_split(strrev($units), 3)));

        return ($amount->isNegative() ? '−' : '') . $grouped . '.' . $fraction;
    }

    /** «+499.54», «−16.20», «0.00» — a correction as the block shows it. */
    public static function signed(Money $amount): string
    {
        return ($amount->isPositive() ? '+' : '') . AmountFormat::of($amount);
    }

    /**
     * The rows of a correction block as the screens and the PDF show them (board S3-2): per field
     * its turnover (a rate / acquisition field's base, or the figure of a turnover field) and its
     * tax (every other field's figure), each as «ursprünglich», «frühere Korrekturen», «Korrektur»
     * and «neu» — the tax exact beside the rounded value; «neu» rounded ONCE from the exact sum.
     *
     * @return list<array{field: string, label: string, turnover: ?array{original: Money, earlier: Money, correction: Money, new: Money}, tax: ?array{original: ExactAmount, earlier: ExactAmount, correction: ExactAmount, new: ExactAmount}}>
     */
    public static function correctionRows(VatReturnCorrectionBlock $block): array
    {
        $rows = [];
        foreach ($block->rows as $row) {
            $kind     = $row['kind'];
            $turnover = null;
            $tax      = null;
            if (self::isTurnover($kind)) {
                // A turnover field's figure is turnover: exact to the Rappen already, shown rounded.
                $turnover = ['original' => $row['original']->rounded(), 'earlier' => $row['earlier']->rounded(), 'correction' => $row['correction']->rounded(), 'new' => $row['new']->rounded()];
            } else {
                if ($row['correctionBase'] !== null || $row['originalBase'] !== null) {
                    $zero     = Money::zero($row['new']->rounded()->currency);
                    $turnover = ['original' => $row['originalBase'] ?? $zero, 'earlier' => $row['earlierBase'] ?? $zero, 'correction' => $row['correctionBase'] ?? $zero, 'new' => $row['newBase'] ?? $zero];
                }
                $tax = ['original' => $row['original'], 'earlier' => $row['earlier'], 'correction' => $row['correction'], 'new' => $row['new']];
            }
            $rows[] = ['field' => $row['field'], 'label' => $row['label'], 'turnover' => $turnover, 'tax' => $tax];
        }

        return $rows;
    }
}
