<?php
/**
 * Geräte — the toolbar (hc2): the two views as tabs (`.be-viewtabs`), nothing else (board
 * «Geräte»: «Aktiv» · «Alle»). The list's help closes the row on its own (HELP-003).
 *
 * Part of the fragment: added by `listAction()`.
 *
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiDeviceLayout::BASE;
$current    = $state->extra('view');
$views      = ['active' => 'Aktiv', 'all' => 'Alle'];
?>
<nav class="be-viewtabs" aria-label="Geräte">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $current ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === 'active' ? '' : '?view=' . $key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
