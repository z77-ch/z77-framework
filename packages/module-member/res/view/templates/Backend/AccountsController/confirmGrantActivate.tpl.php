<?php
/**
 * Confirm activating a grant (ADR-037): an EXISTING account of one tenant may
 * from now on work for another. Nothing is created — no account, no tenant,
 * no activation hook — the grant is only switched on and the person is told.
 *
 * ⚠️ The modal names BOTH tenants: the home the account lives at and the one
 * it gets added to. It is the last screen before a person of customer A has a
 * foot in customer B's door, and that is the sentence to read before clicking.
 *
 * @var array{id:string,email:string,name:string,tenantName:string,homeName:string,inviter:string} $grant
 * @var string $entityCsrf
 * @var string $actionBase
 */
?>
<form data-fetch-post="<?= e($actionBase ?? '/backend/service/member-accounts') ?>/grant-activate">
    <input type="hidden" name="grant_id"    value="<?= e($grant['id']) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Zusätzlichen Mandanten freischalten</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($grant['email']) ?>»
           <?= $grant['name'] !== '' ? '(' . e($grant['name']) . ')' : '' ?>
           hat sein Konto bei <strong>«<?= e($grant['homeName'] !== '' ? $grant['homeName'] : '—') ?>»</strong>
           und darf nach der Freischaltung <strong>zusätzlich für «<?= e($grant['tenantName']) ?>»</strong>
           arbeiten — es entsteht <strong>kein neuer Mandant und kein neues Konto</strong>.
           <?= $grant['inviter'] !== '' ? 'Eingeladen von ' . e($grant['inviter']) . '.' : '' ?>
           Der Kunde erhält eine Mail und wählt den Mandanten nach der Anmeldung selbst.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn">Freischalten</button>
    </div>
</form>
