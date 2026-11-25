<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Vat\CountryPack\FieldKind;

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
}
