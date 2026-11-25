<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatAccountReconciliation;
use Z77\Module\Financial\VatReturn\VatReturnBreakdown;
use Z77\Module\Financial\VatReturn\VatReturnCorrectionBlock;
use Z77\Module\Financial\VatReturn\VatReturnFinding;
use Z77\Module\Financial\VatReturn\VatReturnPreview;
use Z77\Module\Financial\VatReturn\VatReturnReconciliation;
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
 * with «Gedruckt …» and the page count); {@see of()} is rendered on request, never stored; the
 * archived copy with the settlement entry is {@see voucher()} (stage S2 slice 5, frozen into
 * {@see VatReturnArchive}). A period a posted return covers prints «gebucht am … von …» as its state.
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

    /**
     * @param list<VatReturnFinding>         $findings
     * @param list<VatReturnCorrectionBlock> $corrections the block of correction entries the period carries (stage S3)
     */
    public static function of(VatReturnPreview $preview, array $findings, string $periodLabel, ?Mandator $mandator, string $printedAt, array $corrections = []): PdfDocument
    {
        $span    = VatReturnLayout::periodSpan($preview->period);
        $blocks  = [self::facts($preview, $periodLabel, $span, $mandator), self::form($preview), ...self::corrections($corrections)];
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

    /**
     * The VOUCHER of a posted return (ADR-041 addendum 2026-11-24, point 14; financial.md «MWST buchen», the voucher) — built by
     * `VatReturn\VatReturnPostingService` inside its unit of work, after the settlement entry has
     * its number, and frozen into {@see VatReturnArchive}. What the printed return carries, and
     * beside it: «gebucht am … von …» with the revision, the
     * settlement entry (number and lines — or «keine» for a nil return), the tree field › origin ›
     * account («Herkunft»), the reconciliation (VAT accounts and turnover) and the warnings the
     * poster confirmed. «Keine Einreichung» stays. Deterministic for its inputs: the print time is
     * the posting time.
     *
     * The block «Korrekturen früherer Perioden» (stage S3, board S3-2 part 3) follows the form when the
     * return carries one — per corrected period the fields with a correction, plus 399 and 500, each
     * with its exact value (B-2); the settlement lines of the block carry their own text «Korrektur
     * {Periode}». Without a block the voucher is what it was before stage S3.
     *
     * @param list<array{role: string, account: string, debit: Money, credit: Money, text?: ?string}> $lines the settlement lines
     * @param list<string>                                                           $warnings    the confirmed warnings
     * @param list<VatReturnCorrectionBlock>                                         $corrections the block the return carries
     */
    public static function voucher(
        VatReturnPreview $preview,
        VatReturnReconciliation $reconciliation,
        array $lines,
        ?string $entryNumber,
        array $warnings,
        int $revision,
        string $postedBy,
        \DateTimeImmutable $postedAt,
        string $periodLabel,
        ?Mandator $mandator,
        array $corrections = [],
    ): PdfDocument {
        $span   = VatReturnLayout::periodSpan($preview->period);
        $state  = 'gebucht am ' . $postedAt->format('d.m.Y H:i') . ' von ' . $postedBy . ($revision > 1 ? ' (Neuerstellung ' . $revision . ')' : '');
        $extra  = [['Buchung', $entryNumber ?? 'keine (Nullabrechnung)']];
        $blocks = [self::facts($preview, $periodLabel, $span, $mandator, $state, $extra), self::form($preview), ...self::corrections($corrections), self::breakdown($preview), self::reconciliation($reconciliation), self::settlement($lines, $entryNumber)];
        if ($warnings !== []) {
            $blocks[] = ['title' => 'Bestätigte Warnungen', 'columns' => [['label' => 'Warnung', 'width' => self::WIDTH]],
                'rows' => array_map(static fn(string $w): array => ['cells' => [$w]], $warnings), 'wrap' => 0];
        }
        $blocks[] = ['columns' => [['label' => '', 'width' => self::WIDTH]], 'rows' => [['cells' => [self::NOT_A_FILING], 'bold' => true]], 'header' => false, 'wrap' => 0];

        return PdfDocument::create(self::TITLE . ' ' . $periodLabel, $mandator?->getName() ?? '')->partial('pdf/report', [
            'title'     => self::TITLE,
            'subtitle'  => $periodLabel . ' · ' . $span . ' · Beleg',
            'issuer'    => $mandator?->getName() ?? '',
            'printedAt' => $postedAt->format('d.m.Y H:i'),
            'notice'    => '',
            'blocks'    => $blocks,
        ], 'Z77\\Shared');
    }

    /** «mwst-abrechnung-3-quartal-2026.pdf» — kebab-case lower (file names follow the layer). */
    public static function fileName(string $periodLabel): string
    {
        // «Mwst», not the title's «MWST»: the slug splits capitals into words («m-w-s-t»).
        return Naming::toSlug('Mwst-Abrechnung ' . $periodLabel) . '.pdf';
    }

    /**
     * Who, which period, how computed — two columns without a head. $state replaces the line
     * «Zustand» (the voucher, written before its return row exists); $extra rows follow it.
     *
     * @param list<array{0: string, 1: string}> $extra
     */
    private static function facts(VatReturnPreview $preview, string $periodLabel, string $span, ?Mandator $mandator, ?string $state = null, array $extra = []): array
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
        $rows[] = ['cells' => ['Zustand', $state ?? ($posted === null
            ? $preview->stateLabel() . ' — nicht gebucht'
            : $preview->stateLabel() . ' am ' . $posted->getPostedAt()->format('d.m.Y') . ' von ' . $posted->getPostedBy() . ($posted->getRevision() > 1 ? ' (Neuerstellung ' . $posted->getRevision() . ')' : ''))]];
        foreach ($extra as [$label, $value]) {
            $rows[] = ['cells' => [$label, $value]];
        }

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

    /** «Herkunft»: field › origin › account, with lines, base, tax and the amount the node gives its field. */
    private static function breakdown(VatReturnPreview $preview): array
    {
        $rows = [];
        foreach ($preview->breakdown as $field) {
            $rows[] = self::breakdownRow($field, $field->key . ' ' . $field->label, true);
            foreach ($field->children as $origin) {
                $rows[] = self::breakdownRow($origin, '   ' . $origin->label, false);
                foreach ($origin->children as $account) {
                    $rows[] = self::breakdownRow($account, '      ' . $account->key . ' ' . $account->label, false);
                }
            }
        }
        if ($rows === []) {
            $rows[] = ['cells' => ['keine Buchung mit MWST-Code', '', '', '', '']];
        }

        return ['title' => 'Herkunft', 'columns' => [
            ['label' => 'Ziffer › Herkunft › Konto', 'width' => 92],
            ['label' => 'Zeilen', 'width' => 16, 'align' => 'R'],
            ['label' => 'Basis', 'width' => 28, 'align' => 'R'],
            ['label' => 'Steuer', 'width' => 26, 'align' => 'R'],
            ['label' => 'Betrag', 'width' => 26, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 0];
    }

    private static function breakdownRow(VatReturnBreakdown $node, string $label, bool $bold): array
    {
        return ['cells' => [$label, (string) $node->lines, AmountFormat::of($node->base), AmountFormat::of($node->tax), AmountFormat::of($node->amount)], 'bold' => $bold];
    }

    /** «Abstimmung»: every VAT account against the coded tax, then the turnover against field 200 − 235. */
    private static function reconciliation(VatReturnReconciliation $reco): array
    {
        $rows = [];
        foreach ($reco->vatAccounts as $a) {
            $rows[] = ['cells' => [trim($a->accountNumber . ' ' . $a->accountName), $a->role === VatAccountReconciliation::OWED ? 'geschuldete MWST' : 'Vorsteuer',
                AmountFormat::of($a->expected), AmountFormat::of($a->movement), self::signed($a->difference())]];
        }
        $rows[] = ['cells' => ['Ertragskonten (Bewegung)', 'Umsatz', AmountFormat::of($reco->declared()), AmountFormat::of($reco->revenueTotal), self::signed($reco->difference())], 'rule' => true];
        foreach ($reco->explained as $line) {
            $rows[] = ['cells' => ['   ' . trim($line->accountNumber . ' ' . $line->accountName), $line->label(), '', AmountFormat::of($line->amount), '']];
        }
        $rows[] = ['cells' => ['Nicht erklärt', '', '', '', self::signed($reco->remainder())], 'bold' => true];

        return ['title' => 'Abstimmung', 'columns' => [
            ['label' => 'Konto', 'width' => 70],
            ['label' => 'Art', 'width' => 34],
            ['label' => 'Formular', 'width' => 28, 'align' => 'R'],
            ['label' => 'Buchhaltung', 'width' => 28, 'align' => 'R'],
            ['label' => 'Differenz', 'width' => 28, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 0];
    }

    /**
     * The settlement entry line by line, each named by its role; a line of the correction block
     * (role `correction`) by its own text «Korrektur 3. Quartal 2026» (FIN-VAT-VOUCHER-ROLE-001).
     *
     * @param list<array{role: string, account: string, debit: Money, credit: Money, text?: ?string}> $lines
     */
    private static function settlement(array $lines, ?string $entryNumber): array
    {
        $roles = ['owed' => 'geschuldete MWST', 'input' => 'Vorsteuer', 'settlement' => 'Abrechnungskonto', 'rounding' => 'Rundungsdifferenz', 'correction' => 'Korrektur'];
        $rows  = array_map(static fn(array $l): array => ['cells' => [$l['account'], $l['role'] === 'correction' && ($l['text'] ?? '') !== '' ? (string) $l['text'] : ($roles[$l['role']] ?? $l['role']),
            $l['debit']->isZero() ? '' : AmountFormat::of($l['debit']), $l['credit']->isZero() ? '' : AmountFormat::of($l['credit'])]], $lines);
        if ($rows === []) {
            $rows[] = ['cells' => ['', 'keine Buchung — Nullabrechnung', '', '']];
        }

        return ['title' => 'Abrechnungsbuchung' . ($entryNumber === null ? '' : ' ' . $entryNumber), 'columns' => [
            ['label' => 'Konto', 'width' => 30],
            ['label' => 'Zeile', 'width' => 98],
            ['label' => 'Soll', 'width' => 30, 'align' => 'R'],
            ['label' => 'Haben', 'width' => 30, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 1];
    }

    /** @param list<VatReturnFinding> $findings */
    private static function findings(array $findings): array
    {
        $rows = array_map(static fn(VatReturnFinding $f): array => ['cells' => [$f->blocking ? 'sperrt' : 'Warnung', $f->message], 'bold' => $f->blocking], $findings);

        return ['title' => 'Offene Punkte', 'columns' => [['label' => 'Art', 'width' => 20], ['label' => 'Punkt', 'width' => self::WIDTH - 20]], 'rows' => $rows, 'wrap' => 1];
    }

    /** «958.82211» — every decimal the exact amount has beyond the Rappen, grouped like {@see AmountFormat} (the screens' {@see VatReturnLayout::exact()}). */
    private static function exact(ExactAmount $amount): string
    {
        return VatReturnLayout::exact($amount);
    }

    /**
     * The block «Korrekturen früherer Perioden — separat im ePortal melden» (board S3-2, part 3): per
     * corrected period a line (the period, its span, when it was posted, the correction entries) and
     * a table — one row per field and kind (Umsatz, Steuer), «ursprünglich», «frühere Korrekturen»
     * (only when an earlier return corrected the period — C-C), «Korrektur» and «neu», the tax with
     * its exact value beside (B-2: «neu» rounded once from the exact sum). Nothing without a block.
     *
     * @param list<VatReturnCorrectionBlock> $corrections
     * @return list<array<string, mixed>>
     */
    private static function corrections(array $corrections): array
    {
        $blocks = [];
        foreach ($corrections as $i => $block) {
            $posted   = $block->correctedReturn?->getPostedAt();
            $entries  = implode(', ', array_map(static fn(array $e): string => $e['ref'] . ' vom ' . $e['date']->format('d.m.Y'), $block->entries));
            $blocks[] = ['title' => $i === 0 ? VatReturnLayout::CORRECTIONS_TITLE : '', 'columns' => [['label' => '', 'width' => self::WIDTH]], 'header' => false, 'wrap' => 0, 'gapAfter' => 1,
                'rows' => [['cells' => [$block->period->label() . ' (' . $block->period->from->format('d.m.') . '–' . $block->period->to->format('d.m.Y')
                    . ($posted === null ? '' : ', gebucht ' . $posted->format('d.m.Y')) . ') · ' . (count($block->entries) === 1 ? 'Korrekturbuchung ' : 'Korrekturbuchungen ') . $entries], 'bold' => true]]];

            $keys    = $block->hasEarlier()
                ? ['original' => 'ursprünglich', 'earlier' => 'frühere Korr.', 'correction' => 'Korrektur', 'new' => 'neu']
                : ['original' => 'ursprünglich', 'correction' => 'Korrektur', 'new' => 'neu'];
            $columns = [['label' => 'Ziffer', 'width' => 10], ['label' => 'Art', 'width' => 16]];
            foreach ($keys as $label) {
                $columns[] = ['label' => $label, 'width' => 19, 'align' => 'R'];
                $columns[] = ['label' => 'genau', 'width' => 21, 'align' => 'R'];
            }
            $rows = [];
            foreach (VatReturnLayout::correctionRows($block) as $row) {
                foreach (['turnover' => 'Umsatz', 'tax' => 'Steuer'] as $kind => $art) {
                    if ($row[$kind] === null) {
                        continue;
                    }
                    $cells = [$row['field'], $art];
                    foreach (array_keys($keys) as $key) {
                        $value   = $row[$kind][$key];
                        $money   = $value instanceof ExactAmount ? $value->rounded() : $value;
                        $cells[] = in_array($key, ['correction', 'earlier'], true) ? self::signed($money) : AmountFormat::of($money);
                        $cells[] = $value instanceof ExactAmount ? self::exact($value) : '';
                    }
                    $rows[] = ['cells' => $cells];
                }
            }
            $blocks[] = ['columns' => $columns, 'rows' => $rows, 'wrap' => null];
        }

        return $blocks;
    }

    /** «+0.01», «−0.01», «0.00». */
    private static function signed(Money $difference): string
    {
        return ($difference->isPositive() ? '+' : '') . AmountFormat::of($difference);
    }
}
