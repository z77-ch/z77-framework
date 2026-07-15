<?php
/** @var \Z77\Shared\Entities\LoginUser|null $entry */
/** @var string $entityCsrf */
/** @var string|null $blockReason */

if ($entry === null): ?>
<div class="be-modal__body">
    <p>Benutzer nicht gefunden.</p>
</div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
<?php return; endif; ?>

<?php if ($blockReason !== null): ?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Löschen nicht möglich</h2>
</div>
<div class="be-modal__body">
    <div class="be-modal__alert be-modal__alert--error"><?= e($blockReason) ?></div>
</div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
<?php return; endif; ?>

<form data-fetch-post="/backend/system/login-user/remove">
    <input type="hidden" name="id"          value="<?= (int)$entry->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Benutzer löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($entry->getUsername()) ?>» wirklich löschen? Das Konto wird dauerhaft entfernt.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
