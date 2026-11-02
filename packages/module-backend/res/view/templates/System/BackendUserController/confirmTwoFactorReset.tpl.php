<?php
/**
 * «2FA zurücksetzen» for a lost device (login.md BACKEND-2FA-001). Two shapes: refused (the reason
 * and a close) and the confirm itself, which has no input field, so its row stands at the bottom
 * (`end`, ADR-049 revision 2026-10-10).
 *
 * @var \Z77\Shared\Entities\BackendUser|null $entry
 * @var string $entityCsrf
 * @var string|null $blockReason
 */
?>
<?php if ($blockReason !== null): ?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Zurücksetzen nicht möglich</h2>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
<div class="be-modal__body">
    <div class="be-modal__alert be-modal__alert--error"><?= e($blockReason) ?></div>
</div>
<?php return; endif; ?>

<form data-fetch-post="/backend/system/backend-user/two-factor-reset">
    <input type="hidden" name="id"          value="<?= (int) $entry->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">2FA zurücksetzen</h2>
    </div>
    <div class="be-modal__body">
        <p>Zwei-Faktor-Schutz von «<?= e($entry->getUsername()) ?>» wirklich zurücksetzen? Alle Anmeldungen dieses Kontos werden beendet, danach genügt das Passwort. Falls eine Adresse hinterlegt ist, wird die Person per E-Mail informiert.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Zurücksetzen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
