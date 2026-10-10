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
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
