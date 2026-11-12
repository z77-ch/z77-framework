<?php
/**
 * Faktura-Journal — the action cell (hc1): «Journal verbuchen …», the most frequent action of
 * «Auftragswesen › Faktura-Journal» (backend-screen.md §2: it writes into the books → the green
 * confirm). It opens the posting WINDOW (owner decision E16; `data-window-open`, origin the list
 * region — the posting closes the window and reloads that region; without the script the link is
 * the page); the server re-judges everything there and at the POST. While the journal is EMPTY the
 * button is DISABLED with its reason as title — the button stays, so the user sees why. A blocked
 * journal (a document «Neu fakturieren nötig») opens the window, which then shows only the errors
 * (E16; until 2026-11-12 the button was disabled then as well).
 *
 * Part of the fragment: added by the trait.
 *
 * @var array<string, mixed> $totals  JournalPostingService::totals()
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice-journal';
$url        = $actionBase . '/confirm-post';
$origin     = isset($definition) ? ' data-origin="region:' . e($definition->region()) . '"' : '';
?>
<?php if (($totals['count'] ?? 0) > 0): ?>
<a class="be-btn be-btn--confirm" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>"<?= $origin ?>>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</a>
<?php else: ?>
<button type="button" class="be-btn be-btn--confirm" disabled title="Das Faktura-Journal ist leer — nichts zu verbuchen.">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</button>
<?php endif; ?>
