<?php

namespace Z77\Module\Financial\Pdf;

use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Financial\Ui\VatYearLayout;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatReturnPreview;
use Z77\Module\Financial\VatReturn\VatYearBalance;
use Z77\Module\Financial\VatReturn\VatYearField;
use Z77\Module\Financial\VatReturn\VatYearOverview;
use Z77\Module\Financial\VatReturn\VatYearTurnoverItem;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Pdf\PdfDocument;

/**
 * The yearly overview of the VAT return as a PDF («MWST-Jahresübersicht {Jahr}», stage S7 — board
 * S7-3; owner S7-1, S7-4, B-1, B-2, B-4 of 2026-11-25). Like {@see VatReturnPdf} it only builds the
 * BLOCKS of the kernel's shared layout `Z77\Shared` `pdf/report`. ONE content, two ways:
 *
 *   - «PDF» prints the current state on request, never stored; while the overview is not final its
 *     title reads «… VORLÄUFIG» and the red notice names why («4. Quartal 2026 nicht gebucht ·
 *     Geschäftsjahr 2026 offen»);
 *   - «Ablegen …» freezes exactly this PDF into {@see VatYearArchive}, with the last line «Abgelegt
 *     {Zeit} von {wer}» ($stored).
 *
 * Order (board S7-3): first what goes into the ePortal (the Jahresabstimmung), then the blocks per
 * corrected period, what is entered but not reported, the returns of the year, the sum per field,
 * the deadlines; then the reconciliations of Art. 128 MWSTV and the information. Every tax amount
 * that has more than the Rappen carries its exact value in a muted line beneath (B-2). No interest,
 * no signature, no ESTV address, no «keine Steuerberatung» (S7-4) — z77 files nothing.
 */
final class VatYearPdf
{
    /** The A4 text width of `pdf/report` (210 − 12 − 10 mm). */
    private const WIDTH = 188.0;

    /**
     * @param array{at: \DateTimeImmutable, by: string}|null $stored the storing this PDF is frozen for; null = printed on request
     * @param string|null                                    $roundingAccount the mandator's rounding account (6945), for the information block
     * @param \DateTimeImmutable|null                        $closedAt when the fiscal year holding 31.12. was closed
     */
    public static function of(
        VatYearOverview $overview,
        ?Mandator $mandator,
        \DateTimeImmutable $stand,
        ?array $stored = null,
        ?string $roundingAccount = null,
        ?\DateTimeImmutable $closedAt = null,
    ): PdfDocument {
        $year     = $overview->taxYear->year;
        $currency = $overview->payables->currency;
        $reasons  = VatYearLayout::provisionalReasons($overview);
        $title    = VatYearLayout::title($year) . ($overview->final ? '' : ' VORLÄUFIG');

        $blocks   = [self::facts($overview, $mandator, $stand, $closedAt), self::forFiling($overview, $currency)];
        foreach ($overview->corrections as $i => $c) {
            $blocks[] = self::block($c, $i === 0);
        }
        $blocks[] = self::pending($overview);
        $blocks[] = self::returns($overview);
        $blocks[] = self::sum($overview);
        $blocks[] = ['title' => 'Fristen', 'columns' => [['label' => '', 'width' => self::WIDTH]], 'header' => false, 'wrap' => 0,
            'rows' => [['cells' => [$overview->deadlines->known
                ? '180. Tag nach Ende des Geschäftsjahres: ' . $overview->deadlines->day180()->format('d.m.Y') . ' · 240. Tag: ' . $overview->deadlines->day240()->format('d.m.Y')
                : \Z77\Module\Financial\VatReturn\VatYearDeadlines::UNKNOWN]]]];
        array_push($blocks, ...self::turnover($overview, $currency));
        $blocks[] = self::accounts($overview, $currency);
        $blocks[] = self::inputCorrections($overview);
        $blocks[] = self::information($overview, $roundingAccount);
        if ($stored !== null) {
            $blocks[] = ['columns' => [['label' => '', 'width' => self::WIDTH]], 'header' => false, 'wrap' => 0,
                'rows' => [['cells' => [VatYearLayout::storedLine($stored['by'], $stored['at'])], 'bold' => true]]];
        }

        return PdfDocument::create($title, $mandator?->getName() ?? '')->partial('pdf/report', [
            'title'     => $title,
            'subtitle'  => 'Steuerjahr ' . VatYearLayout::yearSpan($overview) . ' · Stand ' . $stand->format('d.m.Y H:i'),
            'issuer'    => $mandator?->getName() ?? '',
            'printedAt' => ($stored['at'] ?? new \DateTimeImmutable())->format('d.m.Y H:i'),
            'notice'    => $reasons === [] ? '' : 'Vorläufig — ' . implode(' · ', $reasons),
            'blocks'    => $blocks,
        ], 'Z77\\Shared');
    }

    /** «mwst-jahresuebersicht-2026.pdf», «…-vorlaeufig.pdf» — the name the printed PDF is offered under. */
    public static function fileName(int $taxYear, bool $final): string
    {
        return 'mwst-jahresuebersicht-' . $taxYear . ($final ? '' : '-vorlaeufig') . '.pdf';
    }

    // ── the blocks ───────────────────────────────────────────────────────

    private static function facts(VatYearOverview $o, ?Mandator $mandator, \DateTimeImmutable $stand, ?\DateTimeImmutable $closedAt): array
    {
        $rows = [];
        if ($mandator !== null) {
            $address = implode(', ', array_filter([trim($mandator->getStreet() . ' ' . $mandator->getHouseNo()), trim($mandator->getZip() . ' ' . $mandator->getCity())], static fn(string $s): bool => $s !== ''));
            $rows[]  = ['cells' => ['Mandant', $mandator->getName() . ($address !== '' ? ', ' . $address : '')]];
            if (trim($mandator->getUid()) !== '') {
                $rows[] = ['cells' => ['MWST-Nummer', trim($mandator->getUid()) . ($mandator->isLiableToVat() ? ' MWST' : '')]];
            }
        }
        $rows[] = ['cells' => ['Steuerjahr', VatYearLayout::yearSpan($o)]];
        $rows[] = ['cells' => ['Geschäftsjahr', VatYearLayout::isCalendarYear($o)
            ? VatYearLayout::yearEndState($o, $closedAt)
            : VatYearLayout::fiscalYearsLabel($o) . ' — ' . VatYearLayout::FISCAL_YEAR_DIFFERS]];
        $rows[] = ['cells' => ['Methode', $o->methodLabel]];
        $rows[] = ['cells' => ['Rhythmus', VatYearLayout::rhythmLabel($o)]];
        $rows[] = ['cells' => ['Deklaration', VatReturnPreview::DECLARATION_NOTE], 'bold' => true];
        $rows[] = ['cells' => ['Stand', $stand->format('d.m.Y H:i')]];

        return ['columns' => [['label' => '', 'width' => 32], ['label' => '', 'width' => self::WIDTH - 32]], 'rows' => $rows, 'header' => false, 'wrap' => 1];
    }

    /** «Für die Jahresabstimmung (Berichtigungsabrechnung)» — the column «Korrekturen» of the sum, what goes into the ePortal. */
    private static function forFiling(VatYearOverview $o, string $currency): array
    {
        $title = 'Für die Jahresabstimmung (Berichtigungsabrechnung)';
        if (!$o->hasCorrections()) {
            return ['title' => $title, 'columns' => [['label' => '', 'width' => self::WIDTH]], 'header' => false, 'wrap' => 0,
                'rows' => [['cells' => [VatYearLayout::NO_CORRECTIONS . '.'], 'bold' => true]]];
        }
        $rows    = [];
        $payable = null;
        foreach ($o->fields as $f) {
            if (!$f->hasCorrection()) {
                continue;
            }
            if ($f->kind === FieldKind::Payable) {
                $payable = $f;
                continue;
            }
            $turnover = VatReturnLayout::isTurnover($f->kind);
            $rows[]   = ['cells' => [$f->field, $f->label,
                $turnover ? VatYearLayout::signed($f->corrections->rounded()) : ($f->correctionsBase === null ? '' : VatYearLayout::signed($f->correctionsBase)),
                $turnover ? '' : VatYearLayout::signed($f->corrections->rounded()),
                $turnover || !VatYearLayout::showsExact($f->corrections) ? '' : VatYearLayout::signedExact($f->corrections)]];
        }
        if ($payable !== null) {
            $rows[] = ['cells' => ['', 'Differenz Zahllast', '', VatYearLayout::signed($payable->corrections->rounded()), ''], 'bold' => true, 'rule' => true];
        }

        return ['title' => $title, 'columns' => [
            ['label' => 'Ziffer', 'width' => 13],
            ['label' => 'Korrekturen ' . $o->taxYear->year . ', Summe', 'width' => 85],
            ['label' => 'Umsatz', 'width' => 30, 'align' => 'R'],
            ['label' => 'Steuer', 'width' => 30, 'align' => 'R'],
            ['label' => 'genau', 'width' => 30, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 1];
    }

    /** One reported block: «ursprünglich · (frühere Korrekturen) · Korrektur · neu» per field and Umsatz / Steuer. */
    private static function block(\Z77\Module\Financial\VatReturn\VatYearCorrection $c, bool $first): array
    {
        $earlier = $c->block->hasEarlier();
        $rows    = [['cells' => [VatYearLayout::correctionLine($c), '', '', '', '', ''], 'bold' => true]];
        foreach ($c->block->rows as $row) {
            $kind = $row['kind'];
            if (VatReturnLayout::isTurnover($kind) || $row['originalBase'] !== null) {
                $base = VatReturnLayout::isTurnover($kind);
                $rows[] = ['cells' => [
                    $row['field'] . ' Umsatz',
                    AmountFormat::of($base ? $row['original']->rounded() : $row['originalBase']),
                    $earlier ? VatYearLayout::signed($base ? $row['earlier']->rounded() : ($row['earlierBase'] ?? Money::zero($row['original']->currency))) : '',
                    VatYearLayout::signed($base ? $row['correction']->rounded() : ($row['correctionBase'] ?? Money::zero($row['original']->currency))),
                    AmountFormat::of($base ? $row['new']->rounded() : $row['newBase']),
                    '',
                ]];
            }
            if (VatReturnLayout::isTurnover($kind)) {
                continue;
            }
            $label  = $kind === FieldKind::Payable || $kind === FieldKind::Credit ? ' Zahllast' : ' Steuer';
            $rows[] = ['cells' => [$row['field'] . $label, AmountFormat::of($row['original']->rounded()), $earlier ? VatYearLayout::signed($row['earlier']->rounded()) : '',
                VatYearLayout::signed($row['correction']->rounded()), AmountFormat::of($row['new']->rounded()), '']];
            $exact = [$row['original'], $row['earlier'], $row['correction'], $row['new']];
            if (array_filter($exact, static fn(ExactAmount $e): bool => VatYearLayout::showsExact($e)) !== []) {
                $rows[] = ['cells' => ['   genau', VatYearLayout::exact($row['original']), $earlier ? VatYearLayout::signedExact($row['earlier']) : '',
                    VatYearLayout::signedExact($row['correction']), VatYearLayout::exact($row['new']), ''], 'muted' => true];
            }
        }

        return ['title' => $first ? 'Korrekturen je ursprüngliche Periode' : '', 'columns' => [
            ['label' => 'Ziffer', 'width' => 50],
            ['label' => 'ursprünglich', 'width' => 32, 'align' => 'R'],
            ['label' => $earlier ? 'frühere Korr.' : '', 'width' => 30, 'align' => 'R'],
            ['label' => 'Korrektur', 'width' => 32, 'align' => 'R'],
            ['label' => 'neu', 'width' => 32, 'align' => 'R'],
            ['label' => '', 'width' => 12],
        ], 'rows' => $rows, 'wrap' => 0];
    }

    /** «Erfasst, noch nicht abgerechnet» (small rule 5). */
    private static function pending(VatYearOverview $o): array
    {
        $rows = [];
        foreach ($o->pending as $p) {
            $tax = $p->block->row(self::payableField($p->block->rows) ?? '');
            $rows[] = ['cells' => [$p->block->entryRefs(), $p->block->period->label(), implode(', ', array_column($p->block->rows, 'field')),
                $tax === null ? '' : VatYearLayout::signed($tax['correction']->rounded()), $p->carryingPeriod->label()]];
        }
        if ($rows === []) {
            $rows[] = ['cells' => ['keine', '', '', '', '']];
        }

        return ['title' => 'Erfasst, noch nicht abgerechnet', 'columns' => [
            ['label' => 'Buchung', 'width' => 34],
            ['label' => 'korrigiert', 'width' => 36],
            ['label' => 'Ziffern', 'width' => 50],
            ['label' => 'Zahllast', 'width' => 28, 'align' => 'R'],
            ['label' => 'kommt mit', 'width' => 40],
        ], 'rows' => $rows, 'wrap' => 2];
    }

    /** «Abrechnungen {Jahr}» — the periods with their live return; Total = Σ payable as posted. */
    private static function returns(VatYearOverview $o): array
    {
        $rows = [];
        foreach ($o->periods as $p) {
            $r      = $p->return;
            $rows[] = ['cells' => [
                $p->period->label() . ($r !== null && $r->getRevision() > 1 ? ' (Fassung ' . $r->getRevision() . ')' : ''),
                $r === null ? 'nicht gebucht' : $r->getPostedAt()->format('d.m.Y'),
                $r === null ? '' : (VatYearLayout::periodEntry($p) === '' ? 'keine' : VatYearLayout::periodEntry($p)),
                $r === null ? '' : AmountFormat::of($r->getPayable()),
                $p->due->format('d.m.Y'),
            ]];
        }
        $rows[] = ['cells' => ['Total', '', '', AmountFormat::of($o->payables), ''], 'bold' => true, 'rule' => true];

        return ['title' => 'Abrechnungen ' . $o->taxYear->year, 'columns' => [
            ['label' => 'Periode', 'width' => 64],
            ['label' => 'gebucht am', 'width' => 30],
            ['label' => 'Buchung', 'width' => 30],
            ['label' => 'Zahllast', 'width' => 34, 'align' => 'R'],
            ['label' => 'fällig bis', 'width' => 30],
        ], 'rows' => $rows, 'wrap' => 0];
    }

    /** «Steuerjahr {Jahr} — Summe pro Ziffer»: declared · corrections · new, Umsatz and Steuer; the exact values beneath. */
    private static function sum(VatYearOverview $o): array
    {
        $with = $o->hasCorrections() || array_filter($o->fields, static fn(VatYearField $f): bool => $f->hasCorrection()) !== [];
        $rows = [];
        foreach ($o->fields as $f) {
            if (!$f->shown) {
                continue;
            }
            $turnover = VatReturnLayout::isTurnover($f->kind);
            $payable  = $f->kind === FieldKind::Payable;
            $label    = $payable ? 'Zahllast' . ($with ? ' — gebucht · Differenz · neu' : ' — Summe der gebuchten Abrechnungen') : $f->label;
            $bold     = VatReturnLayout::isTotal($f->kind) || $payable;
            if ($with) {
                $rows[] = ['cells' => [$f->field, $label,
                    $turnover ? AmountFormat::of($f->declared->rounded()) : ($f->declaredBase === null ? '' : AmountFormat::of($f->declaredBase)),
                    $turnover ? self::correctionCell($f->corrections) : ($f->correctionsBase === null ? '' : self::correctionMoney($f->correctionsBase)),
                    $turnover ? AmountFormat::of($f->newValue()->rounded()) : ($f->newBase() === null ? '' : AmountFormat::of($f->newBase())),
                    $turnover ? '' : AmountFormat::of($f->declared->rounded()),
                    $turnover ? '' : self::correctionCell($f->corrections),
                    $turnover ? '' : AmountFormat::of($f->newValue()->rounded()),
                ], 'bold' => $bold, 'rule' => $payable];
                if (!$turnover && (VatYearLayout::showsExact($f->declared) || VatYearLayout::showsExact($f->corrections) || VatYearLayout::showsExact($f->newValue()))) {
                    $rows[] = ['cells' => ['', '   genau', '', '', '', VatYearLayout::exact($f->declared), $f->corrections->isZero() ? '' : VatYearLayout::signedExact($f->corrections), VatYearLayout::exact($f->newValue())], 'muted' => true];
                }
                continue;
            }
            $rows[] = ['cells' => [$f->field, $label,
                $turnover ? AmountFormat::of($f->declared->rounded()) : ($f->declaredBase === null ? '' : AmountFormat::of($f->declaredBase)),
                $turnover ? '' : AmountFormat::of($f->declared->rounded()),
                !$turnover && VatYearLayout::showsExact($f->declared) ? VatYearLayout::exact($f->declared) : '',
            ], 'bold' => $bold, 'rule' => $payable];
        }
        $columns = $with ? [
            ['label' => 'Ziffer', 'width' => 11],
            ['label' => 'Bezeichnung', 'width' => 45],
            ['label' => 'Umsatz dekl.', 'width' => 23, 'align' => 'R'],
            ['label' => 'Korr.', 'width' => 18, 'align' => 'R'],
            ['label' => 'neu', 'width' => 23, 'align' => 'R'],
            ['label' => 'Steuer dekl.', 'width' => 23, 'align' => 'R'],
            ['label' => 'Korr.', 'width' => 22, 'align' => 'R'],
            ['label' => 'neu', 'width' => 23, 'align' => 'R'],
        ] : [
            ['label' => 'Ziffer', 'width' => 13],
            ['label' => 'Bezeichnung', 'width' => 85],
            ['label' => 'Umsatz', 'width' => 30, 'align' => 'R'],
            ['label' => 'Steuer', 'width' => 30, 'align' => 'R'],
            ['label' => 'genau', 'width' => 30, 'align' => 'R'],
        ];

        return ['title' => 'Steuerjahr ' . $o->taxYear->year . ' — Summe pro Ziffer', 'columns' => $columns, 'rows' => $rows, 'wrap' => 1];
    }

    /**
     * «Umsatzabstimmung (Art. 128 Abs. 2 MWSTV)»: the revenue accounts, then the items, the
     * remainder, and beside the equation the corrections reported later.
     *
     * @return list<array<string, mixed>>
     */
    private static function turnover(VatYearOverview $o, string $currency): array
    {
        $year = $o->taxYear->year;
        $t    = $o->turnover;
        $rows = [];
        foreach ($t->revenueAccounts as $line) {
            $rows[] = ['cells' => [$line->accountNumber, $line->accountName, AmountFormat::of($line->amount)]];
        }
        $rows[] = ['cells' => ['', 'Total Ertrag ' . $year, AmountFormat::of($t->revenueTotal)], 'bold' => true, 'rule' => true];
        $accounts = ['title' => 'Umsatzabstimmung (Art. 128 Abs. 2 MWSTV)', 'columns' => [
            ['label' => 'Konto', 'width' => 20],
            ['label' => 'Ertrag ' . $year . ' gemäss Buchhaltung', 'width' => 130],
            ['label' => 'Bewegung', 'width' => 38, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 1];

        $rows   = [['cells' => ['a', 'Total Ertrag ' . $year . ' gemäss Buchhaltung', AmountFormat::of($t->revenueTotal)]]];
        $rows[] = ['cells' => ['', 'Deklariert, ordentliche Abrechnungen ' . $year . ' (Ziffer 200 − 235)', AmountFormat::of($t->declared)]];
        foreach ($t->items as $item) {
            $rows[] = ['cells' => [(string) $item->letter, self::itemLabel($item), $item->amount === null ? 'von Hand prüfen' : AmountFormat::of($item->amount)], 'muted' => !$item->counted];
        }
        $rows[] = ['cells' => ['', 'Unerklärter Rest', AmountFormat::of($t->remainder())], 'bold' => true, 'rule' => true];
        foreach ($o->corrections as $c) {
            if ($c->laterYear) {
                $rows[] = ['cells' => ['', 'Korrekturen für ' . $year . ', später gemeldet: ' . $c->block->period->label() . ' (' . $c->block->entryRefs() . ')', VatYearLayout::signed(VatYearLayout::blockTurnover($c, $currency))]];
            }
        }
        $rows[] = ['cells' => ['', 'Neu deklariert ' . $year, AmountFormat::of($t->correctedDeclared)], 'bold' => true];

        return [$accounts, ['title' => '', 'columns' => [
            ['label' => '', 'width' => 10],
            ['label' => 'Überleitung', 'width' => 140],
            ['label' => 'Betrag', 'width' => 38, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 1]];
    }

    /** «MWST-Konten (Art. 128 Abs. 3 MWSTV)» with 1179 and 2209 (B-1) and the corrections booked in a later year. */
    private static function accounts(VatYearOverview $o, string $currency): array
    {
        $year = $o->taxYear->year;
        $rows = [];
        foreach ($o->accounts as $a) {
            $rows[] = ['cells' => [$a->accountNumber, $a->accountName, AmountFormat::of($a->movement), implode(', ', $a->fields), AmountFormat::of($a->declared),
                AmountFormat::of($a->difference()), AmountFormat::of($a->remainder())], 'bold' => !$a->remainder()->isZero()];
        }
        foreach ([$o->inputPending, $o->correctionPending] as $balance) {
            if ($balance instanceof VatYearBalance) {
                $rows[] = ['cells' => [$balance->accountNumber, $balance->accountName . ' — Saldo 31.12.' . $year . ($balance->open !== null ? ', offen heute ' . AmountFormat::of($balance->open) : ''),
                    AmountFormat::of($balance->balance), '', '', '', $balance->open === null ? AmountFormat::of($balance->balance) : ''], 'rule' => $balance === $o->inputPending];
            }
        }
        foreach ($o->corrections as $c) {
            if ($c->laterYear) {
                $rows[] = ['cells' => ['', 'Korrektur für ' . $year . ', gebucht ' . $c->carryingReturn->getPeriodFrom()->format('Y') . ' (' . $c->block->entryRefs() . '), abgerechnet mit ' . $c->carryingReturn->period()->label(),
                    '', '', '', '', VatYearLayout::signed(VatYearLayout::blockPayable($c, $currency))], 'muted' => true];
            }
        }
        if ($rows === []) {
            $rows[] = ['cells' => ['', 'keine MWST-Konten beim Mandanten', '', '', '', '', '']];
        }

        return ['title' => 'MWST-Konten (Art. 128 Abs. 3 MWSTV)', 'columns' => [
            ['label' => 'Konto', 'width' => 14],
            ['label' => 'Bezeichnung', 'width' => 64],
            ['label' => 'Bewegung', 'width' => 24, 'align' => 'R'],
            ['label' => 'Ziffer', 'width' => 16],
            ['label' => 'deklariert', 'width' => 24, 'align' => 'R'],
            ['label' => 'Rundung', 'width' => 22, 'align' => 'R'],
            ['label' => 'Rest', 'width' => 24, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 1];
    }

    /** «Korrekturen der Vorsteuer (Ziffern 410, 415, 420)» — the fields of those categories, or «keine». */
    private static function inputCorrections(VatYearOverview $o): array
    {
        $fields = VatYearLayout::inputCorrectionFields($o);
        $rows   = [];
        foreach ($fields as $number) {
            $f = $o->field($number);
            if ($f === null || ($f->declared->isZero() && !$f->hasCorrection())) {
                continue;
            }
            $rows[] = ['cells' => [$f->field, $f->label, AmountFormat::of($f->declared->rounded()), self::correctionCell($f->corrections), AmountFormat::of($f->newValue()->rounded())]];
        }
        if ($rows === []) {
            $rows[] = ['cells' => ['', 'keine', '', '', '']];
        }

        return ['title' => 'Korrekturen der Vorsteuer' . ($fields === [] ? '' : ' (Ziffern ' . implode(', ', $fields) . ')'), 'columns' => [
            ['label' => 'Ziffer', 'width' => 13],
            ['label' => 'Bezeichnung', 'width' => 85],
            ['label' => 'deklariert', 'width' => 30, 'align' => 'R'],
            ['label' => 'Korrekturen', 'width' => 30, 'align' => 'R'],
            ['label' => 'neu', 'width' => 30, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 1];
    }

    /** «Information»: the rounding of the year's returns, 2201 at 31.12., the payable amounts summed. */
    private static function information(VatYearOverview $o, ?string $roundingAccount): array
    {
        $year = $o->taxYear->year;
        $rows = [['cells' => [(string) $roundingAccount, 'Rundungsdifferenzen MWST aus den Abrechnungen ' . $year, AmountFormat::of($o->rounding)]]];
        if ($o->settlement !== null) {
            $rows[] = ['cells' => [$o->settlement->accountNumber, $o->settlement->accountName . ', Saldo am 31.12.' . $year, AmountFormat::of($o->settlement->balance)]];
        }
        $rows[] = ['cells' => ['', 'Zahllast der Abrechnungen ' . $year . ', Summe', AmountFormat::of($o->payables)]];

        return ['title' => 'Information', 'columns' => [
            ['label' => 'Konto', 'width' => 14],
            ['label' => '', 'width' => 136],
            ['label' => 'Betrag', 'width' => 38, 'align' => 'R'],
        ], 'rows' => $rows, 'wrap' => 1, 'header' => false];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** The label of a turnover item, with its account: «Ertrag ohne MWST-Code — 6950 Finanzertrag». */
    public static function itemLabel(VatYearTurnoverItem $item): string
    {
        return $item->label . ($item->accountNumber !== '' ? ' — ' . trim($item->accountNumber . ' ' . $item->accountName) : '');
    }

    /** The field of a block's payable row, null when it has none. */
    private static function payableField(array $rows): ?string
    {
        foreach ($rows as $row) {
            if ($row['kind'] === FieldKind::Payable) {
                return $row['field'];
            }
        }

        return null;
    }

    private static function correctionCell(ExactAmount $amount): string
    {
        return $amount->isZero() ? '—' : VatYearLayout::signed($amount->rounded());
    }

    private static function correctionMoney(Money $amount): string
    {
        return $amount->isZero() ? '—' : VatYearLayout::signed($amount);
    }
}
