<?php
/**
 * The yearly overview — tab «Korrekturen» (board S7-2 parts 1, 3, 6c):
 *
 *   - «Abrechnungen {Jahr}» — every VAT period of the year with its live return (posted on, the
 *     settlement entry, the payable amount AS POSTED, the due date); a period not posted says so;
 *     the total is Σ of the posted payable amounts, never re-rounded;
 *   - «Korrekturen für {Jahr}» — each stored block that corrects a period of the year, with the
 *     return that reported it and its posting day (posted means filed), «vor / nach dem 240. Tag»
 *     only when the fiscal year is the calendar year; per field «ursprünglich · (frühere Korrekturen)
 *     · Korrektur · neu» for Umsatz and Steuer; none → «Keine Korrekturen — keine Jahresabstimmung
 *     einzureichen»;
 *   - «Steuerjahr {Jahr} — Summe pro Ziffer» — declared · corrections · new (with corrections), else
 *     Umsatz · Steuer; the payable row is the sum as posted plus the payable difference;
 *   - «Erfasst, noch nicht abgerechnet» — the correction entries for the year whose return is not
 *     posted yet («kommt mit …»); not in the sum.
 *
 * Every tax amount with more than the Rappen carries its exact value small beneath (owner B-2).
 * Fixed sheets of a few rows each — v2 tables with their columns declared here, not standard lists
 * (like the return's «Formular»); the columns of the middle drop on a narrow pane.
 *
 * @var \Z77\Module\Financial\VatReturn\VatYearOverview $overview
 * @var callable $returnUrl  VatPeriod → the return page of the period
 * @var callable $fmt
 */
use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Financial\Ui\VatYearLayout;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\Money;

$year    = $overview->taxYear->year;
$money   = static fn(?Money $m): string => $m === null ? '' : e($fmt($m));
$signed  = static fn(?Money $m): string => $m === null ? '' : ($m->isZero() ? '—' : e(VatYearLayout::signed($m)));
$exact   = static function (ExactAmount $x, bool $isSigned = false) use ($fmt): string {
    $shown = $isSigned ? ($x->isZero() ? '—' : VatYearLayout::signed($x->rounded())) : $fmt($x->rounded());

    return e($shown) . (VatYearLayout::showsExact($x) ? '<br><small class="be-list__cell--muted" data-exact>' . e($isSigned ? VatYearLayout::signedExact($x) : VatYearLayout::exact($x)) . '</small>' : '');
};
$withCorrections = $overview->hasCorrections() || array_filter($overview->fields, static fn($f): bool => $f->hasCorrection()) !== [];
?>
<div class="be-list__section" data-vat-year-returns>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Abrechnungen <?= (int) $year ?></h2>
        <span class="be-list__section-badge"><?= count($overview->periods) ?></span>
    </div>
    <div class="be-list__frame">
        <div class="be-list__table be-list__table--drop" style="--be-list-cols: minmax(9rem, 1fr) 8rem 6.5rem 6rem 7rem 6.5rem; --be-list-cols-sm: minmax(0, 1fr) 6.5rem 6.5rem">
            <div class="be-list__head">
                <span class="be-list__col">Periode</span>
                <span class="be-list__col" data-priority="3">Zeitraum</span>
                <span class="be-list__col">Gebucht am</span>
                <span class="be-list__col" data-priority="3">Buchung</span>
                <span class="be-list__col be-list__col--num">Zahllast</span>
                <span class="be-list__col" data-priority="3">fällig bis</span>
            </div>
            <?php foreach ($overview->periods as $p): ?>
            <?php $r = $p->return; ?>
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-period="<?= e($p->period->from->format('Y-m-d')) ?>">
                    <span class="be-list__cell"><a href="<?= e($returnUrl($p->period)) ?>"><?= e($p->period->label()) ?></a><?= $r !== null && $r->getRevision() > 1 ? ' · Fassung ' . (int) $r->getRevision() : '' ?></span>
                    <span class="be-list__cell" data-priority="3"><?= e($p->period->from->format('d.m.') . '–' . $p->period->to->format('d.m.')) ?></span>
                    <span class="be-list__cell<?= $r === null ? ' be-list__cell--muted' : '' ?>"><?= $r === null ? 'nicht gebucht' : e($r->getPostedAt()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell" data-priority="3"><?= $r === null ? '' : (VatYearLayout::periodEntry($p) === '' ? 'keine' : e(VatYearLayout::periodEntry($p))) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $r === null ? '' : $money($r->getPayable()) ?></span>
                    <span class="be-list__cell" data-priority="3"><?= e($p->due->format('d.m.Y')) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="be-list__item">
                <div class="be-list__row be-list__row--total" data-vat-year-payables>
                    <span class="be-list__cell">Total</span>
                    <span class="be-list__cell" data-priority="3"></span>
                    <span class="be-list__cell"></span>
                    <span class="be-list__cell" data-priority="3"></span>
                    <span class="be-list__cell be-list__cell--num"><?= $money($overview->payables) ?></span>
                    <span class="be-list__cell" data-priority="3"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="be-list__section" data-vat-year-corrections>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Korrekturen für <?= (int) $year ?></h2>
        <?php if ($overview->hasCorrections()): ?>
        <span class="be-list__section-badge"><?= count($overview->corrections) ?></span>
        <?php endif; ?>
    </div>
    <?php if (!$overview->hasCorrections()): ?>
    <p class="be-list__empty" data-vat-year-no-corrections><?= e(VatYearLayout::NO_CORRECTIONS) ?></p>
    <?php endif; ?>
    <?php foreach ($overview->corrections as $c): ?>
    <?php $earlier = $c->block->hasEarlier(); ?>
    <p class="be-list__section-hint" data-vat-year-correction="<?= e($c->block->period->from->format('Y-m-d')) ?>"><strong><?= e(VatYearLayout::correctionLine($c)) ?></strong></p>
    <div class="be-list__frame">
        <div class="be-list__table be-list__table--drop" style="--be-list-cols: 3rem minmax(8rem, 1fr) repeat(<?= $earlier ? 8 : 6 ?>, 6.5rem); --be-list-cols-sm: 3rem minmax(0, 1fr) 6rem 6rem">
            <div class="be-list__head">
                <span class="be-list__col">Ziffer</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Umsatz ursprünglich</span>
                <?php if ($earlier): ?><span class="be-list__col be-list__col--num" data-priority="3">frühere Korrekturen</span><?php endif; ?>
                <span class="be-list__col be-list__col--num" data-priority="3">Korrektur</span>
                <span class="be-list__col be-list__col--num">Umsatz neu</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Steuer ursprünglich</span>
                <?php if ($earlier): ?><span class="be-list__col be-list__col--num" data-priority="3">frühere Korrekturen</span><?php endif; ?>
                <span class="be-list__col be-list__col--num" data-priority="3">Korrektur</span>
                <span class="be-list__col be-list__col--num">Steuer neu</span>
            </div>
            <?php foreach ($c->block->rows as $row): ?>
            <?php
            $turnover = VatReturnLayout::isTurnover($row['kind']);
            $zero     = Money::zero($row['original']->currency);
            ?>
            <div class="be-list__item">
                <div class="be-list__row<?= VatReturnLayout::isTotal($row['kind']) || $row['kind'] === FieldKind::Payable ? ' be-list__row--l1' : '' ?>" data-vat-year-block-field="<?= e($row['field']) ?>">
                    <span class="be-list__cell be-list__cell--mono"><?= e($row['field']) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($row['label']) ?></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= $turnover ? $money($row['original']->rounded()) : $money($row['originalBase']) ?></span>
                    <?php if ($earlier): ?><span class="be-list__cell be-list__cell--num" data-priority="3"><?= $turnover ? $signed($row['earlier']->rounded()) : ($row['originalBase'] === null ? '' : $signed($row['earlierBase'] ?? $zero)) ?></span><?php endif; ?>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= $turnover ? $signed($row['correction']->rounded()) : ($row['originalBase'] === null ? '' : $signed($row['correctionBase'] ?? $zero)) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $turnover ? $money($row['new']->rounded()) : $money($row['newBase']) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap" data-priority="3"><?= $turnover ? '' : $exact($row['original']) ?></span>
                    <?php if ($earlier): ?><span class="be-list__cell be-list__cell--num be-list__cell--wrap" data-priority="3"><?= $turnover ? '' : $exact($row['earlier'], true) ?></span><?php endif; ?>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap" data-priority="3"><?= $turnover ? '' : $exact($row['correction'], true) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap"><?= $turnover ? '' : $exact($row['new']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="be-list__section" data-vat-year-sum>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Steuerjahr <?= (int) $year ?> — Summe pro Ziffer</h2>
    </div>
    <div class="be-list__frame">
        <?php if ($withCorrections): ?>
        <div class="be-list__table be-list__table--drop" style="--be-list-cols: 3rem minmax(8rem, 1fr) repeat(6, 6.5rem); --be-list-cols-sm: 3rem minmax(0, 1fr) 6rem 6rem">
            <div class="be-list__head">
                <span class="be-list__col">Ziffer</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Umsatz deklariert</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Korrekturen</span>
                <span class="be-list__col be-list__col--num">Umsatz neu</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Steuer deklariert</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Korrekturen</span>
                <span class="be-list__col be-list__col--num">Steuer neu</span>
            </div>
        <?php else: ?>
        <div class="be-list__table" style="--be-list-cols: 3rem minmax(8rem, 1fr) 7.5rem 7.5rem; --be-list-cols-xs: 2.6rem minmax(0, 1fr) 5.6rem 5.6rem">
            <div class="be-list__head">
                <span class="be-list__col">Ziffer</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num">Umsatz</span>
                <span class="be-list__col be-list__col--num">Steuer</span>
            </div>
        <?php endif; ?>
            <?php foreach ($overview->fields as $f): ?>
            <?php if (!$f->shown) { continue; } ?>
            <?php
            $turnover = VatReturnLayout::isTurnover($f->kind);
            $payable  = $f->kind === FieldKind::Payable;
            $label    = $payable ? 'Zahllast — ' . ($withCorrections ? 'gebucht · Differenz Zahllast · neu' : 'Summe der gebuchten Abrechnungen') : $f->label;
            $class    = $payable ? ' be-list__row--total' : (VatReturnLayout::isTotal($f->kind) ? ' be-list__row--l1' : '');
            ?>
            <div class="be-list__item">
                <div class="be-list__row<?= $class ?>" id="ziffer-<?= e($f->field) ?>" data-vat-year-field="<?= e($f->field) ?>">
                    <span class="be-list__cell be-list__cell--mono"><?= e($f->field) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($label) ?></span>
                    <?php if ($withCorrections): ?>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= $turnover ? $money($f->declared->rounded()) : $money($f->declaredBase) ?></span>
                    <span class="be-list__cell be-list__cell--num" data-priority="3"><?= $turnover ? $signed($f->corrections->rounded()) : ($f->correctionsBase === null ? '' : $signed($f->correctionsBase)) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $turnover ? $money($f->newValue()->rounded()) : $money($f->newBase()) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap" data-priority="3"><?= $turnover ? '' : $exact($f->declared) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap" data-priority="3"><?= $turnover ? '' : $exact($f->corrections, true) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap"><?= $turnover ? '' : $exact($f->newValue()) ?></span>
                    <?php else: ?>
                    <span class="be-list__cell be-list__cell--num"><?= $turnover ? $money($f->declared->rounded()) : $money($f->declaredBase) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap"><?= $turnover ? '' : $exact($f->declared) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="be-list__section" data-vat-year-pending>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Erfasst, noch nicht abgerechnet</h2>
        <?php if ($overview->pending !== []): ?>
        <span class="be-list__section-badge"><?= count($overview->pending) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($overview->pending === []): ?>
    <p class="be-list__empty">keine</p>
    <?php else: ?>
    <div class="be-list__frame">
        <div class="be-list__table be-list__table--drop" style="--be-list-cols: 8rem 6.5rem minmax(7rem, 1fr) minmax(7rem, 1fr) 6.5rem 9rem; --be-list-cols-sm: 7rem minmax(0, 1fr) 6rem">
            <div class="be-list__head">
                <span class="be-list__col">Buchung</span>
                <span class="be-list__col" data-priority="3">Datum</span>
                <span class="be-list__col">korrigiert</span>
                <span class="be-list__col" data-priority="3">Ziffern</span>
                <span class="be-list__col be-list__col--num">Zahllast</span>
                <span class="be-list__col" data-priority="3">kommt mit</span>
            </div>
            <?php foreach ($overview->pending as $p): ?>
            <?php
            $payableRow = null;
            foreach ($p->block->rows as $row) {
                $payableRow = $row['kind'] === FieldKind::Payable ? $row : $payableRow;
            }
            ?>
            <div class="be-list__item">
                <div class="be-list__row" data-vat-year-pending-period="<?= e($p->block->period->from->format('Y-m-d')) ?>">
                    <span class="be-list__cell"><?= e($p->block->entryRefs()) ?></span>
                    <span class="be-list__cell" data-priority="3"><?= e(implode(', ', array_map(static fn(array $x): string => $x['date']->format('d.m.Y'), $p->block->entries))) ?></span>
                    <span class="be-list__cell"><?= e($p->block->period->label()) ?></span>
                    <span class="be-list__cell" data-priority="3"><?= e(implode(', ', array_column($p->block->rows, 'field'))) ?></span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--wrap"><?= $payableRow === null ? '' : $exact($payableRow['correction'], true) ?></span>
                    <span class="be-list__cell" data-priority="3"><?= e($p->carryingPeriod->label()) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
