<?php
/**
 * BackendUser row context menu (⋮, core.js «context menu», owner 2026-10-22): edit + delete.
 * Only the items — each opens its dialog via data-fetch-get. Delete-protection is enforced by
 * the confirmDelete/remove actions.
 *
 * @var \Z77\Shared\Entities\BackendUser $entry
 */
$id = $entry->getId();
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($entry->getUsername()) ?>»">
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/system/backend-user/edit?id=<?= e($id) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/system/backend-user/confirm-delete?id=<?= e($id) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
</div>
