<?php
/**
 * Confirm resetting a form-mail override to the config seed — deletes the
 * backend record (the operator-entered recipients/subject/routes are lost; the
 * config default applies again). Mirrors the login-user / navigation confirm
 * modals.
 *
 * @var string $formKey
 * @var string $entityCsrf
 */
?>
<form data-fetch-post="/backend/service/email-settings/reset">
    <input type="hidden" name="form_key"    value="<?= e($formKey) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Auf Config zurücksetzen</h2>
    </div>
    <div class="be-modal__body">
        <p>Die Backend-Einstellungen für «<?= e($formKey) ?>» werden gelöscht — danach gilt wieder die
           Entwickler-Vorgabe (Config). Die hier erfassten Empfänger, CC, Betreff und Routen gehen verloren.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Zurücksetzen</button>
    </div>
</form>
