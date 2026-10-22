<?php
/**
 * Translation row context menu (⋮, core.js «context menu», owner 2026-10-22): edit + delete
 * for one catalog entry. Only the items — each opens the shared edit / confirm-delete dialog
 * via data-fetch-get. The entry is identified by kind (ui|slug) + key, mirroring the list
 * row's fetch URLs.
 *
 * @var string $kind      'ui' | 'slug'
 * @var string $entryKey  the catalog key (UI key or canonical slug)
 */
$label = $kind === 'slug' ? 'Slug' : 'Text';
$ic    = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($entryKey) ?>»">
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/translation/edit?kind=<?= e(rawurlencode($kind)) ?>&key=<?= e(rawurlencode($entryKey)) ?>"><?= raw($ic('icon-edit')) ?> <?= e($label) ?> bearbeiten</button>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/content/translation/confirm-delete?kind=<?= e(rawurlencode($kind)) ?>&key=<?= e(rawurlencode($entryKey)) ?>"><?= raw($ic('icon-trash')) ?> <?= e($label) ?> löschen</button>
</div>
