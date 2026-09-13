<?php
/**
 * Confirm activating a confirmed account (B7): fires the project hook (AXO3:
 * creates the tenant), grants the member role, sends the activation mail.
 *
 * ⚠️ Two shapes since B7 v1.1.0, and the modal must name which one — it is the
 * last screen before the half of the decision that cannot be taken back: an
 * open registration CREATES a tenant, an invitation ATTACHES to an existing
 * one.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $entityCsrf
 * @var list<array{ref:string,label:string,usable:bool,note:string}> $memberships
 *      the project's memberships of this account (ADR-038) — a pending one
 *      means the activation ATTACHES, none means it CREATES
 */
$mine     = $memberships ?? [];
$attaches = $mine !== [];
$name     = implode(', ', array_map(static fn(array $m): string => (string)$m['label'], $mine));
$note     = (string)($mine[0]['note'] ?? '');
?>
<form data-fetch-post="<?= e($actionBase ?? '/backend/service/member-accounts') ?>/activate">
    <input type="hidden" name="account_id"  value="<?= e((string)$account->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Konto freischalten</h2>
    </div>
    <div class="be-modal__body">
        <?php if ($attaches): ?>
        <p>«<?= e($account->getEmail()) ?>» wird aktiv geschaltet und
           <strong>an den bestehenden Mandanten «<?= e($name) ?>» angehängt</strong>
           — es entsteht <strong>kein neuer Mandant</strong>.
           <?= $note !== '' ? e($note) . '.' : '' ?>
           Der Kunde erhält die «Sie sind freigeschaltet»-Mail.</p>
        <?php else: ?>
        <p>«<?= e($account->getEmail()) ?>»
           <?= $account->getCompany() !== null ? '(' . e($account->getCompany()) . ')' : '' ?>
           wird aktiv geschaltet: <strong>Es entsteht ein neuer Mandant</strong>,
           die Projekt-Anbindung wird erstellt und der Kunde erhält die
           «Sie sind freigeschaltet»-Mail.</p>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn">Freischalten</button>
    </div>
</form>
