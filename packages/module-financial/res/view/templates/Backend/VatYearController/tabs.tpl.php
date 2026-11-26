<?php
/**
 * The yearly overview — the TOOLBAR (hc2, board S7-2): first the three tabs «Korrekturen | Umsatz |
 * Vorsteuer» (`.be-viewtabs`, links — each tab is its own page), then the tools (secondary, never
 * primary — the action cell stays empty, the overview posts nothing):
 *
 *   - «PDF» — the current state, opened in a new tab («VORLÄUFIG» while not final);
 *   - «Ablegen …» — the window that stores the PDF in the archive; only when the overview is final
 *     (owner B-4) and differs from the newest storing (B-5) — `$storeUrl` null otherwise.
 *
 * No JavaScript.
 *
 * @var array<string, string> $vatYearTabs  URL action → label
 * @var string $tab
 * @var callable $tabUrl
 * @var string $pdfUrl
 * @var ?string $storeUrl
 */
?>
<nav class="be-viewtabs" aria-label="Ansicht der Jahresübersicht">
    <?php foreach ($vatYearTabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $tab ? ' is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" data-no-pending title="Die Jahresübersicht als PDF in neuem Tab öffnen" data-vat-year-pdf>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg> <span class="be-btn__label">PDF</span>
</a>
<?php if (($storeUrl ?? null) !== null): ?>
<button type="button" class="be-btn be-btn--ghost be-btn--sm" data-fetch-get="<?= e($storeUrl) ?>" data-vat-year-store>Ablegen …</button>
<?php endif; ?>
