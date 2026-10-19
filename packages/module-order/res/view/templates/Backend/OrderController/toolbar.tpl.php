<?php
/**
 * Aufträge › Aufträge — the toolbar (hc2): the view tabs (`.be-viewtabs`, no counts — owner
 * 2026-10-16: «less is more»; a screen that wants one adds it), nothing else. The tabs are the PHASES (`OrderListing::viewOf()`, ADR-043 addendum 2026-10-15) — a
 * new status lands in its tab without code. «+ Neuer Auftrag» stands in the action cell,
 * «Buchungsdruck …» in the selection bar of «Termin» and «Geliefert».
 *
 * Part of the fragment: added by `listAction()` (financial.md, «fragment slots»).
 *
 * @var string $view
 * @var array<string, string> $views   view → label
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$default    = \Z77\Module\Order\Ui\OrderListing::DEFAULT_VIEW;   // the plain /list — not the first tab
?>
<nav class="be-viewtabs" aria-label="Aufträge">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === $default ? '' : '?view=' . $key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
