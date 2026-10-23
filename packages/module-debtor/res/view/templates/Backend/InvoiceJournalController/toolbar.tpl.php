<?php
/**
 * Faktura-Journal — the toolbar (hc2): first the two tabs (`.be-viewtabs`), then the tool
 * «Journal drucken» (secondary, `be-btn--ghost` — the journal list as PDF, in a new tab) on the
 * tab «Im Journal». «Journal verbuchen …» stands in the action cell; «Hilfe» is written by the
 * shell at the right end (HELP-003).
 *
 * Part of the fragment: added by the trait.
 *
 * @var string $view
 * @var array<string, mixed> $totals
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice-journal';
$tabs       = [\Z77\Module\Debtor\Ui\InvoiceJournalListing::VIEW_PENDING => 'Im Journal', \Z77\Module\Debtor\Ui\InvoiceJournalListing::VIEW_POSTED => 'Verbuchte Journale'];
?>
<nav class="be-viewtabs" aria-label="Faktura-Journal">
    <?php foreach ($tabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === \Z77\Module\Debtor\Ui\InvoiceJournalListing::VIEW_PENDING ? '' : '?view=' . $key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($view === \Z77\Module\Debtor\Ui\InvoiceJournalListing::VIEW_PENDING && ($totals['count'] ?? 0) > 0): ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/pdf') ?>" target="_blank" rel="noopener">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-print"/></svg>
    <span class="be-btn__label">Journal drucken</span>
</a>
<?php endif; ?>
