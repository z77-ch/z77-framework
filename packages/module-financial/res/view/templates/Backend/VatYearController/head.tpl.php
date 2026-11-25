<?php
/**
 * The head of the yearly overview (board S7-2): «MWST-Jahresübersicht 2026» with its state — the
 * badge «vorläufig» while a period is not posted or the fiscal year holding 31.12. is open (owner
 * B-4), «abgelegt» once a storing exists —, under it the facts in muted lines: the tax year, the
 * method, the rhythm, «Deklaration netto (ohne MWST)»; the fiscal year's state (or why it is
 * «vorläufig»), «Stand», the deadlines (or «Frist bei der ESTV klären»), the newest storing with its
 * PDF; «Geschäftsjahr ≠ Kalenderjahr …» when the fiscal year is not the calendar year (S7-2); and the
 * one hint line «Seit der Ablage geändert — …» when the overview differs from the newest stored PDF
 * (owner B-5). No explanation here — the help has it.
 *
 * @var \Z77\Module\Financial\VatReturn\VatYearOverview $overview
 * @var \DateTimeImmutable $stand
 * @var ?\DateTimeImmutable $closedAt
 * @var ?\Z77\Module\Financial\Entities\StoredVatYearOverview $stored  the newest storing
 * @var bool $changed      the overview differs from $stored
 * @var ?string $storedUrl the stored PDF
 */
use Z77\Module\Financial\Ui\VatYearLayout;
use Z77\Module\Financial\VatReturn\VatReturnPreview;

$year     = $overview->taxYear->year;
$calendar = VatYearLayout::isCalendarYear($overview);
$state    = $overview->final ? 'final' : 'provisional';
$parts    = $calendar ? [] : [VatYearLayout::fiscalYearsLabel($overview)];
$parts    = array_merge($parts, $overview->final ? ($calendar ? [VatYearLayout::yearEndState($overview, $closedAt)] : []) : VatYearLayout::provisionalReasons($overview));
$parts[]  = 'Stand ' . $stand->format('d.m.Y H:i');
$parts[]  = VatYearLayout::deadlinesLine($overview);
?>
<div class="be-list__section-header">
    <h2 class="be-list__section-title"><?= e(VatYearLayout::title($year)) ?></h2>
    <?php if (!$overview->final): ?>
    <span class="badge badge--warning" data-vat-year-state="provisional">vorläufig</span>
    <?php elseif ($stored !== null): ?>
    <span class="badge badge--success" data-vat-year-state="stored">abgelegt</span>
    <?php endif; ?>
</div>
<p class="be-list__section-hint" data-vat-year-facts>
    Steuerjahr <?= e(VatYearLayout::yearSpan($overview)) ?>
    · <?= e($overview->methodLabel) ?>
    · Rhythmus <?= e(VatYearLayout::rhythmLabel($overview)) ?>
    · <strong><?= e(VatReturnPreview::DECLARATION_NOTE) ?></strong>
</p>
<p class="be-list__section-hint" data-vat-year-state-line="<?= e($state) ?>">
    <?= e(implode(' · ', $parts)) ?>
    <?php if ($stored !== null): ?>
    · <span data-vat-year-stored><?= e(VatYearLayout::storedLine($stored->getCreatedBy(), $stored->getCreatedAt())) ?></span>
    · <a href="<?= e((string) $storedUrl) ?>" target="_blank" rel="noopener" data-no-pending data-vat-year-stored-pdf>Abgelegtes PDF</a>
    <?php endif; ?>
</p>
<?php if (!$calendar): ?>
<p class="be-list__section-hint" data-vat-year-fiscal-differs><?= e(VatYearLayout::FISCAL_YEAR_DIFFERS) ?></p>
<?php endif; ?>
<?php if ($changed && $stored !== null): ?>
<p class="be-list__section-hint" role="status" data-vat-year-changed><?= e(VatYearLayout::changedSince($stored)) ?></p>
<?php endif; ?>
