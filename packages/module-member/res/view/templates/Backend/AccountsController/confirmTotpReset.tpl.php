<?php
/**
 * Confirm resetting a member's 2FA (B8 spec: the lost-device handgrip — like
 * the activation, an operator action). The account keeps its state; only the
 * second factor falls, the customer sets it up anew.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $entityCsrf
 */
?>
<form data-fetch-post="<?= e($actionBase ?? '/backend/service/member-accounts') ?>/totp-reset">
    <input type="hidden" name="account_id"  value="<?= e((string)$account->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Zwei-Faktor-Schutz zurücksetzen</h2>
    </div>
    <div class="be-modal__body">
        <p>Der Zwei-Faktor-Schutz von «<?= e($account->getEmail()) ?>» wird entfernt
           — die Anmeldung verlangt danach nur noch den Magic-Link, bis der Kunde
           den Schutz im Profil neu einrichtet. Nur bei verlorenem Gerät verwenden.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Zurücksetzen</button>
    </div>
</form>
