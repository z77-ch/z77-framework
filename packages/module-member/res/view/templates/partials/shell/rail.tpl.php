<?php
/**
 * The left column — DATA, not navigation.
 *
 * One partial for every area, because the rail is the same thing everywhere:
 * a list of rows one chooses from. What differs is only the data the
 * controller hands in — snippets, the property/building tree, the areas on the
 * dashboard, the profile's sections. A second rail template per area would be
 * four places to change the same row.
 *
 * A row is a LINK, not a button: choosing an entry is a page change with its
 * own URL, so it can be bookmarked, opened in a new tab and reloaded. That
 * also means the narrow-screen overlay opens server-side — the skeleton sets
 * the attribute when something is selected.
 *
 * A row carrying `heading` is not a row but a NAME for the rows below it. An
 * area whose rail holds two kinds of choice (which view, then which level)
 * would otherwise be one undifferentiated stack — and the alternative, a second
 * control somewhere else on the page, is exactly what this column exists to
 * avoid: one looks left to choose (Peter, 2026-08-13).
 *
 * @var array<int,array{
 *     name?:string, url?:string, active?:bool, meta?:string,
 *     dot?:bool, level?:int, stacked?:bool, heading?:string
 * }> $items
 */
?>
<div class="me-rail__inner">
    <?php foreach ($items as $item): ?>
    <?php if (!empty($item['heading'])): ?>
    <p class="me-item__head"><?= e($item['heading']) ?></p>
    <?php continue; endif; ?>
    <?php
        $class = 'me-item';
        if (!empty($item['active']))  { $class .= ' me-item--active'; }
        if (!empty($item['stacked'])) { $class .= ' me-item--stacked'; }
        if (!empty($item['level']))   { $class .= ' me-item--l' . (int) $item['level']; }
    ?>
    <a class="<?= e($class) ?>" href="<?= e($item['url']) ?>"<?= !empty($item['active']) ? ' aria-current="true"' : '' ?>>
        <?php if (array_key_exists('dot', $item)): ?>
        <span class="me-item__dot<?= $item['dot'] ? '' : ' me-item__dot--off' ?>" aria-hidden="true"></span>
        <?php endif; ?>
        <span class="me-item__name"><?= e($item['name']) ?></span>
        <?php if (!empty($item['meta'])): ?>
        <span class="me-item__meta"><?= e($item['meta']) ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <?php if ($items === []): ?>
    <p class="me-item__meta" style="padding: 0.5rem"><?= e($emptyNote ?? '') ?></p>
    <?php endif; ?>
</div>
