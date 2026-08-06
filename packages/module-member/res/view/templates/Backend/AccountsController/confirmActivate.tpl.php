<?php
/**
 * Confirm activating a confirmed account (B7): fires the project hook (AXO3:
 * creates the tenant), grants the member role, sends the activation mail.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $entityCsrf
 */
?>
<form data-fetch-post="/backend/service/member-accounts/activate">
    <input type="hidden" name="account_id"  value="<?= e((string)$account->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Konto freischalten</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($account->getEmail()) ?>»
           <?= $account->getCompany() !== null ? '(' . e($account->getCompany()) . ')' : '' ?>
           wird aktiv geschaltet: Die Projekt-Anbindung wird erstellt und der Kunde
           erhält die «Sie sind freigeschaltet»-Mail.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn">Freischalten</button>
    </div>
</form>
