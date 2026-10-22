<?php
/**
 * Postausgang — the toolbar (hc2): the four views as tabs (`.be-viewtabs`,
 * no counts — «less is more», owner 2026-10-16), nothing else. The action cell
 * stays empty: nothing is created on this screen (ADR-033 rev. 2026-10-08).
 *
 * Part of the fragment: added by the trait.
 *
 * @var \Z77\Shared\Listing\ListState $state
 * @var array<string, string> $views   view => label
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/service/outbox';
$current    = $state->extra('view');
?>
<nav class="be-viewtabs" aria-label="Postausgang">
    <?php foreach ($views as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $current ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === 'queued' ? '' : '?view=' . $key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
