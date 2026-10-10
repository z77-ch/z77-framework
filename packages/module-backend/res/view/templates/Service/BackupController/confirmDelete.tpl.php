<?php
/**
 * Confirm deleting one archive — a confirm WITHOUT any input field, so its action row may stand
 * at the bottom (`end`, ADR-049 revision 2026-10-10). The answer removes the row in place.
 *
 * @var string $type     'data' | 'db' | 'full'
 * @var string $fileName
 * @var string $entityCsrf
 */
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
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
