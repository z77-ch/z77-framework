<?php
/**
 * Belegjournal — the action cell (hc1): «Journal verbuchen …», the most frequent action of
 * «Finanzen › Belegjournal» (backend-screen.md §2: it writes into the books → the green confirm).
 * It opens the posting WINDOW (owner decision E16; `data-window-open`, origin the list region — the
 * posting closes the window and reloads that region; without the script the link is the page) with
 * the cut-off (E11, proposed today); the server judges the run there and at the POST. While the
 * journal is empty the button is DISABLED with its reason as title — the button stays, so the user
 * sees why. An incomplete receipt does not disable it: whether it blocks depends on the cut-off,
 * which the window shows (only the errors then).
 *
 * Part of the fragment: added by the trait.
 *
 * @var array{count: int} $totals
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$url        = $actionBase . '/confirm-post';
$origin     = isset($definition) ? ' data-origin="region:' . e($definition->region()) . '"' : '';
?>
<?php if (($totals['count'] ?? 0) > 0): ?>
<a class="be-btn be-btn--confirm" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>"<?= $origin ?>>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</a>
<?php else: ?>
<button type="button" class="be-btn be-btn--confirm" disabled title="Das Belegjournal ist leer — nichts zu verbuchen.">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</button>
<?php endif; ?>
