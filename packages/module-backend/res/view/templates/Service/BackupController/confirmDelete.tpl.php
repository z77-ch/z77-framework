<?php
/** @var string $type     'data' | 'db' | 'full' */
/** @var string $fileName */
/** @var string $entityCsrf */
?>
<form data-fetch-post="/backend/service/backup/remove">
    <input type="hidden" name="type"        value="<?= e($type) ?>">
    <input type="hidden" name="file"        value="<?= e($fileName) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Backup löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>Backup «<?= e($fileName) ?>» wirklich löschen? Die Archivdatei wird endgültig entfernt — es gibt keinen Papierkorb.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
