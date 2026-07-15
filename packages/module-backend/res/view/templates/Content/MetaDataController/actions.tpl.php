<?php
/**
 * MetaData row action hub (⋮). Present → edit + delete (by meta id); absent → create
 * (by navigation_id). Launches the specific modals via data-fetch-get (a click replaces
 * this hub). Mirrors the DMS drive actions hub.
 *
 * @var \Z77\Shared\Entities\Navigation $page
 * @var ?\Z77\Shared\Entities\MetaData  $meta
 */
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($page->getUrl()) ?>»</h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <?php if ($meta !== null): ?>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/meta-data/edit?id=<?= e((string)$meta->getId()) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/content/meta-data/confirm-delete?id=<?= e((string)$meta->getId()) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
            <?php else: ?>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/meta-data/add?navigation_id=<?= e((string)$page->getId()) ?>"><?= raw($ic('icon-plus')) ?> Metadaten anlegen</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
