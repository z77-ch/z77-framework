<?php
/** @var \Z77\Shared\Entities\Navigation|null $entry */
/** @var string $entityCsrf */

if ($entry === null): ?>
<div class="be-modal__body">
    <p>Eintrag nicht gefunden.</p>
</div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
<?php return; endif; ?>

<form data-fetch-post="/backend/content/navigation/remove">
    <input type="hidden" name="id"          value="<?= e($entry->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Eintrag löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($entry->getName()) ?>» wirklich löschen?</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
