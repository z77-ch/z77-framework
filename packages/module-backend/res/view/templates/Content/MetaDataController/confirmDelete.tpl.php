<?php
/** @var \Z77\Shared\Entities\MetaData $meta */
/** @var \Z77\Shared\Entities\Navigation|null $page */
/** @var string $entityCsrf */

$pageLabel = $page !== null ? $page->getName() : ('#' . $meta->getNavigationId());
?>
<form data-fetch-post="/backend/content/meta-data/remove">
    <input type="hidden" name="id"          value="<?= e((string)$meta->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Metadaten löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>Metadaten für «<?= e($pageLabel) ?>» (Sprache «<?= e($meta->getLanguage()) ?>») wirklich löschen?</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
