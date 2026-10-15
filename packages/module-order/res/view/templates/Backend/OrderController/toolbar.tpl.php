<?php
/**
 * Aufträge › Aufträge — the toolbar (hc2): the view tabs (`.be-viewtabs`, each with its count),
 * nothing else. The tabs are the PHASES (`OrderListing::viewOf()`, ADR-043 addendum 2026-10-15) — a
 * new status lands in its tab without code. «+ Neuer Auftrag» stands in the action cell,
 * «Buchungsdruck …» in the selection bar of «In Arbeit» and «Geliefert».
 *
 * Part of the fragment: added by `listAction()` (financial.md, «fragment slots»).
 *
 * @var string $view
 * @var array<string, string> $views   view → label
 * @var array<string, int>    $counts  view → orders
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$first      = array_key_first($views);
?>
<nav class="be-viewtabs" aria-label="Aufträge">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === $first ? '' : '?view=' . $key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?> <small><?= (int) ($counts[$key] ?? 0) ?></small></a>
    <?php endforeach; ?>
</nav>
