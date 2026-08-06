<?php
/**
 * Confirm rejecting an account (B7): the account is deleted; NO automatic
 * mail goes out — what we write, we write ourselves (spec decision).
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $entityCsrf
 */
?>
<form data-fetch-post="/backend/service/member-accounts/reject">
    <input type="hidden" name="account_id"  value="<?= e((string)$account->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Konto ablehnen</h2>
    </div>
    <div class="be-modal__body">
        <p>Das Konto «<?= e($account->getEmail()) ?>» wird gelöscht. Es geht
           <strong>keine automatische Mail</strong> an die Adresse — falls Sie dem
           Interessenten schreiben wollen, tun Sie das separat.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Ablehnen und löschen</button>
    </div>
</form>
