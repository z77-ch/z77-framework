<?php
/**
 * Documents — the toolbar (hc2): the three views as tabs (`.be-viewtabs`,
 * each with its count), nothing else. «+ Rechnung» stands in the action cell
 * (`act.tpl.php`), «Definitiv stellen …» is bound to the selection and stands
 * in the bar above the list (`listAction.tpl.php`) — ADR-033 rev. 2026-10-08.
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var \Z77\Shared\Listing\ListState $state
 * @var array<string, string> $views   view → label
 * @var array<string, int>    $counts  view → documents
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$current    = $state->extra('view');
?>
<nav class="be-viewtabs" aria-label="Dokumente">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $current ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === 'invoicing' ? '' : '?view=' . $key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?> <small><?= (int) ($counts[$key] ?? 0) ?></small></a>
    <?php endforeach; ?>
</nav>
