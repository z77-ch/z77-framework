<?php
/**
 * Content row context menu (⋮, core.js «context menu», owner 2026-10-22): edit + delete;
 * «Variante anlegen» on a live row, «Vorschau öffnen» + «Satz veröffentlichen» on a variant
 * row, «Vorschau öffnen» + «Wiederherstellen» on a version row (no edit: a version is history).
 * «Löschen» only when the user may delete (ADMIN, access config — the item follows the same
 * rule the server enforces). Identity is (slug, language, variant) — variant '' = the live
 * copy. Only the items: no header, no action row — an item's data-fetch-get opens its dialog.
 *
 * @var \Z77\Shared\Entities\Content $entry
 * @var string $previewUrl  website start page with ?preview=<set>, '' on a live row
 * @var bool   $canDelete   the user may call confirm-delete/remove
 */
$qs   = 'slug=' . rawurlencode($entry->getSlug())
      . '&language=' . rawurlencode($entry->getLanguage())
      . '&variant=' . rawurlencode($entry->getVariant());
$ic   = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$name = ($entry->getTitle() !== '' ? $entry->getTitle() : $entry->getSlug())
      . ($entry->isLive() ? '' : ($entry->isVersion() ? ' · Version ' : ' · Variante ') . $entry->getVariant());
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($name) ?>»">
    <?php if (!$entry->isVersion()): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/content/edit?<?= e($qs) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
    <?php endif; ?>
    <?php if ($entry->isLive()): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/content/create-variant?<?= e($qs) ?>"><?= raw($ic('icon-copy')) ?> Variante anlegen</button>
    <?php elseif ($entry->isVersion()): ?>
    <a class="be-menu__item" role="menuitem" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener"><?= raw($ic('icon-eye')) ?> Vorschau öffnen</a>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/content/confirm-restore?<?= e($qs) ?>"><?= raw($ic('icon-upload')) ?> Wiederherstellen</button>
    <?php else: ?>
    <a class="be-menu__item" role="menuitem" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener"><?= raw($ic('icon-eye')) ?> Vorschau öffnen</a>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/content/content/confirm-publish?variant=<?= e(rawurlencode($entry->getVariant())) ?>"><?= raw($ic('icon-upload')) ?> Satz veröffentlichen</button>
    <?php endif; ?>
    <?php if ($canDelete): ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/content/content/confirm-delete?<?= e($qs) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
    <?php endif; ?>
</div>
