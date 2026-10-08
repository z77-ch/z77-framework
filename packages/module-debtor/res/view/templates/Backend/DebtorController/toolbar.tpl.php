<?php
/**
 * Debitoren (open items) — the toolbar (hc2): the three views as tabs
 * (`.be-viewtabs`, the open and the overdue one with their counts). They
 * act on the list in the work area (ADR-033 rev. 2026-09-28); the column
 * search lives in the list's head (listing.md).
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var \Z77\Shared\Listing\ListState $state
 * @var array<string, string> $views   view → label
 * @var int $openCount
 * @var int $overdueCount
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/debtor';
$counts     = ['open' => $openCount ?? 0, 'overdue' => $overdueCount ?? 0];
$current    = $state->extra('view');
?>
<nav class="be-viewtabs" aria-label="Offene Posten">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $current ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === 'open' ? '' : '?view=' . $key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= isset($counts[$key]) ? ' <small>' . (int) $counts[$key] . '</small>' : '' ?></a>
    <?php endforeach; ?>
</nav>
