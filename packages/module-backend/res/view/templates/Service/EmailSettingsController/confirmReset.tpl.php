<?php
/**
 * Confirm resetting a form-mail override to the config seed — deletes the
 * backend record (the operator-entered recipients/subject/routes are lost; the
 * config default applies again). Mirrors the backend-user / navigation confirm
 * modals. A confirm without an input field: its action row may stand at the bottom (`end`,
 * ADR-049 revision 2026-10-10).
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
    <?= $this->partial('partials/modalActions', ['submit' => 'Zurücksetzen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
