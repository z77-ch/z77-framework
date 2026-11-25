<?php
/**
 * One VAT return — the TOOLBAR (hc2): first the three views as tabs «Formular | Herkunft |
 * Abstimmung» (`.be-viewtabs`, links — each view is its own page, like the report tabs), then the
 * tools (secondary, never primary):
 *
 *   - an OPEN period: «PDF» — the computed return, opened in a new tab;
 *   - a POSTED period (owner decision 6 of 2026-11-25): «Beleg» — the ARCHIVED voucher — and, for
 *     who may lift and only on the latest posted return, «Aufheben …» (danger, red border — the
 *     window `confirm-lift`; never in the action cell);
 *   - a lifted revision: no tabs (its period is open again and computed elsewhere), «Beleg» only.
 *
 * The list «Buchungen einer Ziffer» keeps the tabs with «Herkunft» active — its way back — and has
 * no tool. No JavaScript.
 *
 * @var array<string, string> $tabs   URL action → label
 * @var string $tab                    the active view
 * @var callable $tabUrl               view → URL of this period
 * @var ?string $pdfUrl
 * @var string $crumbField             set on the entries list
 * @var ?\Z77\Module\Financial\Entities\VatReturn $stored  the posted return (or the lifted revision)
 * @var string $voucherUrl             with $stored
 * @var ?string $liftUrl               with $stored, for who may lift the latest return
 * @var bool $revision                 a lifted revision's page
 */
$onEntries = ($crumbField ?? '') !== '';
$stored    = $stored ?? null;
$revision  = $revision ?? false;
?>
<?php if (!$revision): ?>
<nav class="be-viewtabs" aria-label="Ansicht der Abrechnung">
    <?php foreach ($tabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $tab ? ' is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>"<?= $key === $tab && !$onEntries ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if (!$onEntries && $stored !== null): ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($voucherUrl) ?>" target="_blank" rel="noopener" data-no-pending title="Den archivierten Beleg der Abrechnung in neuem Tab öffnen" data-vat-return-voucher>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg> <span class="be-btn__label">Beleg</span>
</a>
<?php if (($liftUrl ?? null) !== null): ?>
<button type="button" class="be-btn be-btn--danger be-btn--sm" data-fetch-get="<?= e($liftUrl) ?>" data-vat-return-lift>Aufheben …</button>
<?php endif; ?>
<?php elseif (!$onEntries && $pdfUrl !== null): ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" data-no-pending title="Die Abrechnung als PDF in neuem Tab öffnen" data-vat-return-pdf>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg> <span class="be-btn__label">PDF</span>
</a>
<?php endif; ?>
