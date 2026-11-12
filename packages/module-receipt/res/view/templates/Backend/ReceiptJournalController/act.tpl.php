<?php
/**
 * Belegjournal — the action cell (hc1): «Journal verbuchen …», the most frequent action of
 * «Finanzen › Belegjournal» (backend-screen.md §2: it writes into the books → the green confirm).
 * It opens the confirmation page with the cut-off (E11); the server judges the run there and at the
 * POST. While the journal is empty the button is DISABLED with its reason as title — the button
 * stays, so the user sees why. An incomplete receipt does not disable it: whether it blocks depends
 * on the cut-off, which the confirmation page shows.
 *
 * Part of the fragment: added by the trait.
 *
 * @var array{count: int} $totals
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
?>
<?php if (($totals['count'] ?? 0) > 0): ?>
<a class="be-btn be-btn--confirm" href="<?= e($actionBase . '/confirm-post') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</a>
<?php else: ?>
<button type="button" class="be-btn be-btn--confirm" disabled title="Das Belegjournal ist leer — nichts zu verbuchen.">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</button>
<?php endif; ?>
