<?php
/**
 * Kreditorenjournal — the action cell (hc1): «Journal verbuchen …», the most frequent action of
 * the screen since stage 1c (creditor plan §8; backend-screen.md §2: it writes into the books → the
 * green confirm). It opens the posting WINDOW (`data-window-open`, origin the list region — the
 * posting closes the window and reloads that region; without the script the link is the page) with
 * the cut-off on the payment date, proposed today; the server judges the run there and at the POST.
 * While no invoice is PAID the button is DISABLED with its reason as title — unpaid invoices are no
 * part of a run. A blocked or incomplete paid invoice does not disable it: the window names it.
 * «PDF hochladen» stands in the toolbar.
 *
 * Part of the fragment: added by the trait.
 *
 * @var array{paid: int} $totals
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$url        = $actionBase . '/confirm-post';
$origin     = isset($definition) ? ' data-origin="region:' . e($definition->region()) . '"' : '';
?>
<?php if (($totals['paid'] ?? 0) > 0): ?>
<a class="be-btn be-btn--confirm" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>"<?= $origin ?>>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</a>
<?php else: ?>
<button type="button" class="be-btn be-btn--confirm" disabled title="Keine bezahlte Rechnung im Journal — zuerst «Bezahlt am …».">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</button>
<?php endif; ?>
