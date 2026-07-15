<?php
/**
 * Translation row action hub (⋮): edit + delete for one catalog entry. Launches the
 * shared edit/confirm-delete modals via data-fetch-get (a click replaces this hub).
 * The entry is identified by kind (ui|slug) + key, mirroring the list row's fetch URLs.
 *
 * @var string $kind      'ui' | 'slug'
 * @var string $entryKey  the catalog key (UI key or canonical slug)
 */
$label = $kind === 'slug' ? 'Slug' : 'Text';
$ic    = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($entryKey) ?>»</h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/translation/edit?kind=<?= e(rawurlencode($kind)) ?>&key=<?= e(rawurlencode($entryKey)) ?>"><?= raw($ic('icon-edit')) ?> <?= e($label) ?> bearbeiten</button>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/content/translation/confirm-delete?kind=<?= e(rawurlencode($kind)) ?>&key=<?= e(rawurlencode($entryKey)) ?>"><?= raw($ic('icon-trash')) ?> <?= e($label) ?> löschen</button>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
