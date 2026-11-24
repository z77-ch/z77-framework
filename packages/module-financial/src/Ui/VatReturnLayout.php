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
