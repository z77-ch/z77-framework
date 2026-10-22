<?php
/**
 * MetaData row context menu (⋮, core.js «context menu», owner 2026-10-22). Present → edit +
 * delete (by meta id); absent → create (by navigation_id). Only the items — each opens its
 * dialog via data-fetch-get.
 *
 * @var \Z77\Shared\Entities\Navigation $page
 * @var ?\Z77\Shared\Entities\MetaData  $meta
 */
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($page->getUrl()) ?>»">
    <?php if ($meta !== null): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/meta-data/edit?id=<?= e((string)$meta->getId()) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/content/meta-data/confirm-delete?id=<?= e((string)$meta->getId()) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
    <?php else: ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/meta-data/add?navigation_id=<?= e((string)$page->getId()) ?>"><?= raw($ic('icon-plus')) ?> Metadaten anlegen</button>
    <?php endif; ?>
</div>
