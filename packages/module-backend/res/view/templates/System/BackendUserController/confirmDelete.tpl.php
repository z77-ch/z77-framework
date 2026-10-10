<?php
/**
 * Delete a backend user. Three shapes: not found, blocked (self / last admin — the reason and a
 * close), and the confirm itself. The confirm has no input field, so its action row may stand at
 * the bottom (`end`, ADR-049 revision 2026-10-10); the two message shapes carry their «Schliessen»
 * in the row under the header like every dialog. The delete answers in place (`removeRow`).
 *
 * @var \Z77\Shared\Entities\BackendUser|null $entry
 * @var string $entityCsrf
 * @var string|null $blockReason
 */

if ($entry === null): ?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Benutzer nicht gefunden</h2>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
<div class="be-modal__body">
    <p>Benutzer nicht gefunden.</p>
</div>
<?php return; endif; ?>

<?php if ($blockReason !== null): ?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Löschen nicht möglich</h2>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
<div class="be-modal__body">
    <div class="be-modal__alert be-modal__alert--error"><?= e($blockReason) ?></div>
</div>
<?php return; endif; ?>

<form data-fetch-post="/backend/system/backend-user/remove">
    <input type="hidden" name="id"          value="<?= (int)$entry->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Benutzer löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($entry->getUsername()) ?>» wirklich löschen? Das Konto wird dauerhaft entfernt.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
