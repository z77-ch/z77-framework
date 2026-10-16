<?php
/**
 * Debitoren (open items) — the toolbar (hc2): the three views as tabs
 * (`.be-viewtabs`, no counts — owner 2026-10-16: «less is more»). They act on the list in the work area (ADR-033 rev. 2026-09-28); the column
 * search lives in the list's head (listing.md).
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var \Z77\Shared\Listing\ListState $state
 * @var array<string, string> $views   view → label
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/debtor';
$current    = $state->extra('view');
?>
<nav class="be-viewtabs" aria-label="Offene Posten">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $current ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === 'open' ? '' : '?view=' . $key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
