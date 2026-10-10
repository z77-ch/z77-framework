<?php
/** @var \Z77\Shared\Entities\Content $content */
/** @var string $entityCsrf */
$ident = $content->getSlug() . '.' . $content->getLanguage() . ($content->isLive() ? '' : ($content->isVersion() ? ' · Version ' : ' · Variante ') . $content->getVariant());
?>
<form data-fetch-post="/backend/content/content/remove">
    <input type="hidden" name="slug"        value="<?= e($content->getSlug()) ?>">
    <input type="hidden" name="language"    value="<?= e($content->getLanguage()) ?>">
    <input type="hidden" name="variant"     value="<?= e($content->getVariant()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Inhalt löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($content->getTitle() !== '' ? $content->getTitle() : $content->getSlug()) ?>» (<?= e($ident) ?>) wirklich löschen? Die Datei wird entfernt.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
