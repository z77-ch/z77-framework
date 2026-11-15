<?php
/**
 * Kreditorenjournal — the toolbar (hc2): the three tabs (`.be-viewtabs`). «Hilfe» / «KI-Assistent»
 * is written by the shell at the right end (HELP-003). «Gesperrt» names its count while invoices
 * wait for the owner's decision on a suspected duplicate.
 *
 * Part of the fragment: added by the trait.
 *
 * @var string $view
 * @var array{blocked: int} $totals
 * @var string $actionBase
 */
use Z77\Module\Creditor\Ui\CreditorJournalListing;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$blocked    = (int) ($totals['blocked'] ?? 0);
$tabs       = [
    CreditorJournalListing::VIEW_OPEN    => 'Im Journal',
    CreditorJournalListing::VIEW_BLOCKED => 'Gesperrt' . ($blocked > 0 ? ' (' . $blocked . ')' : ''),
    CreditorJournalListing::VIEW_POSTED  => 'Verbuchte Journale',
];
?>
<nav class="be-viewtabs" aria-label="Kreditorenjournal">
    <?php foreach ($tabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === CreditorJournalListing::VIEW_OPEN ? '' : '?view=' . $key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
