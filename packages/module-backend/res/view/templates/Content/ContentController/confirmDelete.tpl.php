<?php
/** @var \Z77\Shared\Entities\Content $content */
/** @var string $entityCsrf */
?>
<form data-fetch-post="/backend/content/content/remove">
    <input type="hidden" name="slug"        value="<?= e($content->getSlug()) ?>">
    <input type="hidden" name="language"    value="<?= e($content->getLanguage()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Inhalt löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($content->getTitle() !== '' ? $content->getTitle() : $content->getSlug()) ?>» (<?= e($content->getSlug() . '.' . $content->getLanguage()) ?>) wirklich löschen? Die Datei wird entfernt.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
