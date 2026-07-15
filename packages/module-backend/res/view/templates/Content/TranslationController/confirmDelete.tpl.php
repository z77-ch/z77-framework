<?php
/** @var string $kind 'ui' | 'slug' */
/** @var string $entryKey */
/** @var string $entityCsrf */

$isSlug = $kind === 'slug';
$noun   = $isSlug ? 'Routen-Slug' : 'UI-Text';
$tail   = $isSlug
    ? 'Die lokalisierten Formen werden entfernt; die Route bleibt kanonisch erreichbar.'
    : 'Templates, die diesen Schlüssel verwenden, zeigen danach den Schlüsselnamen statt des Textes.';
?>
<form data-fetch-post="/backend/content/translation/remove">
    <input type="hidden" name="kind"        value="<?= e($kind) ?>">
    <input type="hidden" name="key"         value="<?= e($entryKey) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= e($noun) ?> löschen</h2>
    </div>
    <div class="be-modal__body">
        <p><?= e($noun) ?> «<?= e($entryKey) ?>» wirklich löschen? <?= e($tail) ?></p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
