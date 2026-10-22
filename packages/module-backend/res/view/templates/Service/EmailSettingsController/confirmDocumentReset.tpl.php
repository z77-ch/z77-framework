<?php
/**
 * Confirm resetting a document mail kind to the module's text — deletes the operator's record
 * (subject and text of every language are lost). A confirm without an input field: its action
 * row may stand at the bottom (`end`, ADR-049 revision 2026-10-10).
 *
 * @var string $kind
 * @var string $label
 * @var string $entityCsrf
 */
?>
<form data-fetch-post="/backend/service/email-settings/document-reset">
    <input type="hidden" name="kind"        value="<?= e($kind) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Auf die Vorgabe zurücksetzen</h2>
    </div>
    <div class="be-modal__body">
        <p>Der eigene Betreff und Text der Beleg-Mail «<?= e($label) ?>» werden gelöscht — danach gilt wieder
           die Vorgabe des Moduls.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Zurücksetzen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
