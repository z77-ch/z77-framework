<?php
/**
 * One VAT return — the TOOLBAR (hc2): first the three views as tabs «Formular | Herkunft |
 * Abstimmung» (`.be-viewtabs`, links — each view is its own page, like the report tabs), then the
 * one tool «PDF» (secondary, opens in a new tab). The list «Buchungen einer Ziffer» keeps the tabs
 * with «Herkunft» active — its way back — and has no PDF. No JavaScript.
 *
 * @var array<string, string> $tabs   URL action → label
 * @var string $tab                    the active view
 * @var callable $tabUrl               view → URL of this period
 * @var ?string $pdfUrl
 * @var string $crumbField             set on the entries list
 */
$onEntries = ($crumbField ?? '') !== '';
?>
<nav class="be-viewtabs" aria-label="Ansicht der Abrechnung">
    <?php foreach ($tabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $tab ? ' is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>"<?= $key === $tab && !$onEntries ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (!$onEntries && $pdfUrl !== null): ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" title="Die Abrechnung als PDF in neuem Tab öffnen" data-vat-return-pdf>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg> <span class="be-btn__label">PDF</span>
</a>
<?php endif; ?>
