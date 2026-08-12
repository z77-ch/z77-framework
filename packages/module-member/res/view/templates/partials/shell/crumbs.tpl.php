<?php
/**
 * The breadcrumb — a POSITION, on the content line.
 *
 * A crumb may carry a switch: in the Objekte area the property and the
 * building are switchable, and the switch sits HERE, where one is standing,
 * instead of in the list on the left. That keeps the rail pure navigation —
 * one thing per place.
 *
 * It carries nothing else. The counts that used to sit in the rail moved under
 * the detail's title, not into this line: a breadcrumb one has to read instead
 * of scan has stopped being one, and this row grows with every long property
 * name as it is.
 *
 * A switch names the `hook` its page listens on — the data attribute is the
 * only project-specific part of it, and the value always arrives as `data-id`.
 * The partial itself stays free of any knowledge about what is being switched.
 *
 * @var array<int,array{
 *     label:string, url?:string, here?:bool,
 *     switch?:array{id:string, on:bool, disabled?:bool, hook?:string, title?:string}
 * }> $crumbs
 * @var string $csrfToken
 */
?>
<nav class="me-crumb" aria-label="Pfad">
    <?php foreach ($crumbs as $i => $crumb): ?>
    <?php if ($i > 0): ?><span class="me-crumb__sep" aria-hidden="true">›</span><?php endif; ?>

    <?php if (!empty($crumb['switch'])): ?>
    <span class="me-crumb__level">
        <?php $s = $crumb['switch']; ?>
        <?php /* No CSRF attribute: the page's script posts through core.js,
                 which sets the header from the `csrf-token` meta tag — the only
                 thing AccessGuard reads on a Fetch POST. */ ?>
        <label class="me-switch" title="<?= e($s['title'] ?? 'Sichtbar im Web') ?>">
            <input type="checkbox" data-<?= e($s['hook'] ?? 'estate-toggle') ?>
                   data-id="<?= e($s['id']) ?>"<?= $s['on'] ? ' checked' : '' ?><?= !empty($s['disabled']) ? ' disabled' : '' ?>>
            <span class="me-switch__track"></span>
        </label>
        <span class="me-crumb__here"><?= e($crumb['label']) ?></span>
    </span>
    <?php elseif (!empty($crumb['url'])): ?>
    <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
    <?php else: ?>
    <span class="<?= !empty($crumb['here']) ? 'me-crumb__here' : '' ?>"><?= e($crumb['label']) ?></span>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
