<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatReturnFinding;
use Z77\Module\Financial\VatReturn\VatReturnPreview;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Pdf\PdfDocument;

/**
 * The VAT return of one period as a PDF (P5 part 2, stage S1b) — the paper the taxable person files
 * NEXT TO, not instead of, the ePortal. Like {@see ReportPdf} it only builds the BLOCKS of the
 * kernel's shared layout `Z77\Shared` `pdf/report` (running head with the mandator's name, foot
 * with «Gedruckt …» and the page count); rendered on request, never stored — the archived copy
 * with the settlement entry is stage S2 (slice 5). A period a posted return covers prints
 * «gebucht am … von …» as its state.
 *
 * What it carries (screen decision 5: the exact figures are the PDF's): the facts — mandator,
 * address, VAT number, period, method, form edition, the declaration note (review T2) —; the form
 * with every SHOWN field, base and tax to the Rappen, the EXACT tax of each rate field, and the
 * posted tax with its difference at the total owed and at the payable amount; the open points; the
 * sentence that this is no filing.
 */
final class VatReturnPdf
{
    /** The A4 text width of `pdf/report` (210 − 12 − 10 mm). */
    private const WIDTH = 188.0;

    public const TITLE = 'MWST-Abrechnung';

    /** The last line of every printed return: it is an aid, the filing happens in the portal. */
    public const NOT_A_FILING = 'Keine Einreichung — die Abrechnung wird im ePortal der ESTV eingereicht.';

    /** @param list<VatReturnFinding> $findings */
    public static function of(VatReturnPreview $preview, array $findings, string $periodLabel, ?Mandator $mandator, string $printedAt): PdfDocument
    {
        $span    = VatReturnLayout::periodSpan($preview->period);
        $blocks  = [self::facts($preview, $periodLabel, $span, $mandator), self::form($preview)];
        if ($findings !== []) {
            $blocks[] = self::findings($findings);
        }
        $blocks[] = ['columns' => [['label' => '', 'width' => self::WIDTH]], 'rows' => [['cells' => [self::NOT_A_FILING], 'bold' => true]], 'header' => false, 'wrap' => 0];
        $blocking = count(array_filter($findings, static fn(VatReturnFinding $f): bool => $f->blocking));

        return PdfDocument::create(self::TITLE . ' ' . $periodLabel, $mandator?->getName() ?? '')->partial('pdf/report', [
            'title'     => self::TITLE,
            'subtitle'  => $periodLabel . ' · ' . $span,
            'issuer'    => $mandator?->getName() ?? '',
            'printedAt' => $printedAt,
            'notice'    => $blocking === 0 ? '' : ($blocking === 1 ? '1 offener Punkt sperrt' : "{$blocking} offene Punkte sperren") . ' das Buchen der Abrechnung — siehe «Offene Punkte».',
            'blocks'    => $blocks,
        ], 'Z77\\Shared');
    }

    /** «mwst-abrechnung-3-quartal-2026.pdf» — kebab-case lower (file names follow the layer). */
    public static function fileName(string $periodLabel): string
    {
        // «Mwst», not the title's «MWST»: the slug splits capitals into words («m-w-s-t»).
        return Naming::toSlug('Mwst-Abrechnung ' . $periodLabel) . '.pdf';
    }

    /** Who, which period, how computed — two columns without a head. */
    private static function facts(VatReturnPreview $preview, string $periodLabel, string $span, ?Mandator $mandator): array
    {
        $rows = [];
        if ($mandator !== null) {
            $address = implode(', ', array_filter([trim($mandator->getStreet() . ' ' . $mandator->getHouseNo()), trim($mandator->getZip() . ' ' . $mandator->getCity())], static fn(string $s): bool => $s !== ''));
            $rows[]  = ['cells' => ['Mandant', $mandator->getName() . ($address !== '' ? ', ' . $address : '')]];
            if (trim($mandator->getUid()) !== '') {
                $rows[] = ['cells' => ['MWST-Nummer', trim($mandator->getUid()) . ($mandator->isLiableToVat() ? ' MWST' : '')]];
            }
        }
        $rows[] = ['cells' => ['Periode', $periodLabel . ' · ' . $span . ($preview->period->short ? ' (verkürzte Periode)' : '')]];
        $rows[] = ['cells' => ['Methode', $preview->methodLabel]];
        $rows[] = ['cells' => ['Formular', $preview->edition]];
        $rows[] = ['cells' => ['Deklaration', VatReturnPreview::DECLARATION_NOTE], 'bold' => true];
        $posted = $preview->posted;
        $rows[] = ['cells' => ['Zustand', $posted === null
            ? $preview->stateLabel() . ' — nicht gebucht'
            : $preview->stateLabel() . ' am ' . $posted->getPostedAt()->format('d.m.Y') . ' von ' . $posted->getPostedBy() . ($posted->getRevision() > 1 ? ' (Neuerstellung ' . $posted->getRevision() . ')' : '')]];

        return ['columns' => [['label' => '', 'width' => 32], ['label' => '', 'width' => self::WIDTH - 32]], 'rows' => $rows, 'header' => false, 'wrap' => 1];
    }

    /**
     * The form: the shown fields in form order under the form's section headings; base and tax to
     * the Rappen, the exact tax of a rate field, the posted tax and the difference at the total owed
     * and the payable amount (posted − declared).
     */
    private static function form(VatReturnPreview $preview): array
    {
        $columns = [
            ['label' => 'Ziffer', 'width' => 13],
            ['label' => 'Bezeichnung', 'width' => 65],
            ['label' => 'Umsatz', 'width' => 24, 'align' => 'R'],
            ['label' => 'Steuer', 'width' => 22, 'align' => 'R'],
            ['label' => 'Steuer genau', 'width' => 24, 'align' => 'R'],
            ['label' => 'gebucht', 'width' => 20, 'align' => 'R'],
            ['label' => 'Differenz', 'width' => 20, 'align' => 'R'],
        ];
        $rows    = [];
        $section = null;
        foreach ($preview->fields as $field) {
            if (!$field->shown) {
                continue;
            }
            $kind = $field->kind;
            $here = VatReturnLayout::section($kind);
            if ($here !== $section && VatReturnLayout::SECTIONS[$here] !== '') {
                $rows[] = ['cells' => ['', VatReturnLayout::SECTIONS[$here], '', '', '', '', ''], 'bold' => true];
            }
            $section = $here;

            $turnover = VatReturnLayout::isTurnover($kind) ? AmountFormat::of($field->rounded) : ($field->base !== null ? AmountFormat::of($field->base) : '');
            $tax      = VatReturnLayout::isTurnover($kind) ? '' : AmountFormat::of($field->rounded);
            $exact    = in_array($kind, [FieldKind::Rate, FieldKind::Acquisition, FieldKind::TaxTotal], true) ? self::exact($field->value) : '';
            $posted   = match ($kind) {
                FieldKind::TaxTotal => $preview->postedTaxOwed(),
                FieldKind::Payable  => $preview->form->postedTax,
                FieldKind::Credit   => $preview->form->postedTax->negate(),
                default             => null,
            };
            $rows[] = [
                'cells' => [$field->field, $field->label, $turnover, $tax, $exact, $posted === null ? '' : AmountFormat::of($posted), $posted === null ? '' : self::signed($posted->subtract($field->rounded))],
                'bold'  => VatReturnLayout::isTotal($kind) || $here === 'payable',
                'rule'  => $here === 'payable',
            ];
        }

        return ['title' => 'Formular', 'columns' => $columns, 'rows' => $rows, 'wrap' => 1];
    }

    /** @param list<VatReturnFinding> $findings */
    private static function findings(array $findings): array
    {
        $rows = array_map(static fn(VatReturnFinding $f): array => ['cells' => [$f->blocking ? 'sperrt' : 'Warnung', $f->message], 'bold' => $f->blocking], $findings);

        return ['title' => 'Offene Punkte', 'columns' => [['label' => 'Art', 'width' => 20], ['label' => 'Punkt', 'width' => self::WIDTH - 20]], 'rows' => $rows, 'wrap' => 1];
    }

    /** «958.82211» — every decimal the exact amount has beyond the Rappen, grouped like {@see AmountFormat}. */
    private static function exact(ExactAmount $amount): string
    {
        [$units, $fraction] = explode('.', ltrim($amount->toDecimal(), '-'));
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, Money::SCALE, '0');
        $grouped  = strrev(implode("'", str_split(strrev($units), 3)));

        return ($amount->isNegative() ? '−' : '') . $grouped . '.' . $fraction;
    }

    /** «+0.01», «−0.01», «0.00». */
    private static function signed(Money $difference): string
    {
        return ($difference->isPositive() ? '+' : '') . AmountFormat::of($difference);
    }
}
