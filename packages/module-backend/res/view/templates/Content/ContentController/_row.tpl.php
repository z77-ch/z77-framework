<?php
/**
 * One row of the content list. Rendered by listAction.tpl.php AND by the in-place answers
 * (`insertRow` of a create / «Variante anlegen», ADR-047 addendum 2026-10-10), so list and
 * answer are the same markup. `data-entity="content:<id>"` (id = the entity-CSRF key
 * slug.lang[.variant]) addresses the row; the `data-field` cells take `update-fields`.
 *
 * @var array{content:\Z77\Shared\Entities\Content, id:string, name:string, meta:string, qs:string} $row
 *      ContentController::rowDisplay()
 */
$c  = $row['content'];
$qs = $row['qs'];
?>
<div class="be-tree__node<?= $c->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:<?= $c->isLive() ? 0 : 1 ?>"
     data-entity="content:<?= e($row['id']) ?>"
     data-content-slug="<?= e($c->getSlug()) ?>" data-content-lang="<?= e($c->getLanguage()) ?>" data-content-variant="<?= e($c->getVariant()) ?>">
    <div class="be-tree__row">
        <span class="be-tree__toggle" aria-hidden="true"></span>
        <?php if ($c->isVersion()): /* history: shown, not switched — restore it first */ ?>
        <label class="be-switch be-switch--sm be-tree__switch" title="Version — nicht schaltbar">
            <input type="checkbox" class="be-switch__input" disabled<?= $c->isActive() ? ' checked' : '' ?>>
        <?php else: ?>
        <label class="be-switch be-switch--sm be-tree__switch" title="Aktiv schalten">
            <input type="checkbox" class="be-switch__input"
                   data-fetch-toggle="/backend/content/content/toggle-active?<?= e($qs) ?>"<?= $c->isActive() ? ' checked' : '' ?>>
        <?php endif; ?>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
        </label>
        <button type="button" class="be-tree__menu" title="Aktionen"
                data-fetch-get="/backend/content/content/actions?<?= e($qs) ?>">⋮</button>
        <span class="be-tree__name" data-field="name"><?= e($row['name']) ?></span>
        <span class="be-tree__url"><?= e($c->getSlug()) ?></span>
        <span class="be-tree__route" data-field="meta"><?= e($row['meta']) ?></span>
    </div>
</div>
