<?php
/**
 * Belegjournal — the toolbar (hc2): the two tabs (`.be-viewtabs`). «Journal verbuchen …» stands in
 * the action cell; «Hilfe» / «KI-Assistent» is written by the shell at the right end (HELP-003).
 * «Journal drucken» (the journal list as PDF, on the board) is not built yet — receipt.md pending.
 * The tool «Zahlungen suchen …» (ADR-061 stage C5) opens the window with the bank movements that
 * fit the receipts — only while a bank account reads statements.
 *
 * Part of the fragment: added by the trait.
 *
 * @var string $view
 * @var string $actionBase
 * @var bool $bankAvailable a bank account reads statements → the tool «Zahlungen suchen …»
 */
use Z77\Module\Receipt\Ui\ReceiptJournalListing;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$tabs       = [ReceiptJournalListing::VIEW_PENDING => 'Im Journal', ReceiptJournalListing::VIEW_POSTED => 'Verbuchte Journale'];
?>
<nav class="be-viewtabs" aria-label="Belegjournal">
    <?php foreach ($tabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === ReceiptJournalListing::VIEW_PENDING ? '' : '?view=' . $key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (!empty($bankAvailable)): $bankUrl = $actionBase . '/confirm-bank'; ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($bankUrl) ?>" data-window-open="<?= e($bankUrl) ?>"<?= isset($definition) ? ' data-origin="region:' . e($definition->region()) . '"' : '' ?> data-bank-search title="Auf dem Kontoauszug die Zahlungen zu den Belegen suchen">Zahlungen suchen …</a>
<?php endif; ?>
