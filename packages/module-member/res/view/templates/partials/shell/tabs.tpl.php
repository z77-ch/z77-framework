<?php
/**
 * The page's tabs — sections of ONE surface, switched client-side.
 *
 * A button names the panel it reveals (`data-shell-tab`); shell.js flips
 * `hidden` on every `[data-shell-panel]` of the page. The buttons are NOT
 * navigation: the page keeps its state across a switch — a form spanning the
 * panels loses nothing, and one save carries all of it (B10 spec v1.7.0).
 *
 * Which panel starts open is the SERVER's line: `active` here must match the
 * one panel rendered without `hidden`, or the first paint lies.
 *
 * @var array<int,array{id:string, label:string, active?:bool}> $tabs
 */
?>
<nav class="me-tabs" role="tablist" aria-label="Abschnitte">
    <?php foreach ($tabs as $tab): ?>
    <button type="button" class="me-tabs__tab<?= !empty($tab['active']) ? ' is-active' : '' ?>"
            role="tab" aria-selected="<?= !empty($tab['active']) ? 'true' : 'false' ?>"
            data-shell-tab="<?= e($tab['id']) ?>"><?= e($tab['label']) ?></button>
    <?php endforeach; ?>
</nav>
