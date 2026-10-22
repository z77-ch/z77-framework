<?php
/**
 * Navigation row context menu (⋮, core.js «context menu», owner 2026-10-22). Opened from a
 * list row's `data-menu-open`; each item opens its dialog via data-fetch-get (core-wired when
 * the menu opens). Only the items — no header, no action row.
 *
 * @var \Z77\Shared\Entities\Navigation $entry
 * @var string $siblingUrl
 */
$id = $entry->getId();
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($entry->getName()) ?>»">
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($siblingUrl) ?>"><?= raw($ic('icon-plus')) ?> Geschwister hinzufügen</button>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/navigation/edit?id=<?= e($id) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/content/navigation/confirm-delete?id=<?= e($id) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
</div>
