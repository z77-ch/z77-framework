<?php
/**
 * Confirm rejecting / removing a grant (ADR-037): the grant disappears, the
 * account behind it stays at its own tenant; NO automatic mail goes out —
 * what we write, we write ourselves (B7 spec decision, same as accounts).
 *
 * @var array{id:string,email:string,tenantName:string,homeName:string,waiting:bool} $grant
 * @var string $entityCsrf
 * @var string $actionBase
 */
?>
<form data-fetch-post="<?= e($actionBase ?? '/backend/service/member-accounts') ?>/grant-reject">
    <input type="hidden" name="grant_id"    value="<?= e($grant['id']) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Zusätzlichen Mandanten <?= $grant['waiting'] ? 'ablehnen' : 'entfernen' ?></h2>
    </div>
    <div class="be-modal__body">
        <p>Der Zugang von «<?= e($grant['email']) ?>» zu
           <strong>«<?= e($grant['tenantName']) ?>»</strong> wird entfernt.
           Das Konto selbst bleibt bei «<?= e($grant['homeName'] !== '' ? $grant['homeName'] : '—') ?>»
           bestehen. Es geht <strong>keine automatische Mail</strong> an die Adresse
           — falls Sie der Person schreiben wollen, tun Sie das separat.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger"><?= $grant['waiting'] ? 'Ablehnen und entfernen' : 'Entfernen' ?></button>
    </div>
</form>
