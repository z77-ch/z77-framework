<?php
/**
 * Content row action hub (⋮): edit + delete. Identity is (slug, language). Launches the
 * specific modals via data-fetch-get (a click replaces this hub). Mirrors the DMS drive actions hub.
 *
 * @var \Z77\Shared\Entities\Content $entry
 */
$slug = e(rawurlencode($entry->getSlug()));
$lang = e(rawurlencode($entry->getLanguage()));
$ic   = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($entry->getTitle() !== '' ? $entry->getTitle() : $entry->getSlug()) ?>»</h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/content/edit?slug=<?= $slug ?>&language=<?= $lang ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/content/content/confirm-delete?slug=<?= $slug ?>&language=<?= $lang ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
