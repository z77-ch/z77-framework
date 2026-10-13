<?php
/**
 * The «Rollen» section of the contact dialog (owner decision 2026-10-12): one switch per
 * role another module contributes ({@see \Z77\Module\Contact\Roles\ContactRoles}, config
 * key `contactRoles`) — «Debitor» from module-debtor. This template knows no role: label,
 * state line, refusal reason and link are what the role answers
 * ({@see \Z77\Module\Contact\Roles\ContactRoleState}). Nothing renders when no installed
 * module registers a role.
 *
 * - EXISTING contact: the switch acts AT ONCE (`data-fetch-toggle` → `toggle-role`), not
 *   with «Speichern»; the answer replaces this section (`data-entity="contactRoles:<id>"`),
 *   a refusal reverts the switch and shows the role's sentence. A role that may not be
 *   switched now is a disabled switch with the reason under it. Switching OFF a role whose
 *   state names `offConfirm` (owner 2026-10-13, review M5 — «Debitor» deletes the profile)
 *   does not act: the answer is this section with the question and two buttons —
 *   «<Rolle> entfernen» (`remove-role`, entity token) and «Abbrechen». On «edit» this
 *   section stands AFTER the contact form (`edit.tpl.php`), so those forms are not nested.
 * - NEW contact («Kontakt anlegen»): the switch is a form field `roles[<key>]`; the trait
 *   applies the ticked roles AFTER the contact is saved.
 *
 * @var int|null $contactId  null = «Kontakt anlegen»
 * @var list<array{key: string, label: string, state: \Z77\Module\Contact\Roles\ContactRoleState, chosen: bool}> $roles
 * @var string $actionBase  URL root of THIS mount
 * @var string|null $confirming   the role key whose «off» is being asked (`offConfirm`) — toggle-role answered `confirm`
 * @var string      $confirmCsrf  the entity token of that question (`contactRole.<key>` + contact id)
 */
$actionBase  = $actionBase ?? '/backend/contact/contact';
$isNew       = $contactId === null;
$confirming  = $confirming ?? null;
$confirmCsrf = $confirmCsrf ?? '';
if ($roles === []) {
    return;
}
?>
<div<?= $isNew ? '' : ' data-entity="contactRoles:' . e((string) $contactId) . '"' ?>>
    <div class="be-form__section">Rollen <small><?= $isNew ? '(werden nach dem Speichern des Kontakts festgelegt)' : '(wirken sofort beim Umschalten — «Speichern» betrifft nur die Felder oben)' ?></small></div>
    <?php foreach ($roles as $role): ?>
    <?php $state = $role['state']; ?>
    <div class="be-form__field">
        <label class="be-switch">
            <?php if ($isNew): ?>
            <input type="checkbox" class="be-switch__input" name="roles[<?= e($role['key']) ?>]" value="1"<?= $role['chosen'] && $state->switchable ? ' checked' : '' ?><?= $state->switchable ? '' : ' disabled' ?>>
            <?php else: ?>
            <input type="checkbox" class="be-switch__input"
                   data-fetch-toggle="<?= e($actionBase) ?>/toggle-role?id=<?= e((string) $contactId) ?>&amp;role=<?= e($role['key']) ?>"<?= $state->on ? ' checked' : '' ?><?= $state->switchable ? '' : ' disabled' ?>>
            <?php endif; ?>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
            <span class="be-switch__label"><?= e($role['label']) ?><?php if ($state->summary !== ''): ?> <small>(<?= e($state->summary) ?>)</small><?php endif; ?></span>
        </label>
        <?php if (!$state->switchable && $state->reason !== ''): ?>
        <small class="be-form__hint"><?= e($state->reason) ?></small>
        <?php endif; ?>
        <?php if (!$isNew && $confirming === $role['key'] && $state->on && $state->offConfirm !== ''): ?>
        <?php /* The confirmed «off» (owner 2026-10-13, review M5): two forms of their own — this section stands
                 OUTSIDE the contact form on «edit», so they are no nested forms and the contact fields above
                 keep what was typed. «Abbrechen» asks toggle-role for «on» again: nothing changes, the
                 section comes back without the question. */ ?>
        <div class="be-modal__alert be-modal__alert--error" role="alert" data-role-confirm="<?= e($role['key']) ?>">
            <div><?= e($state->offConfirm) ?></div>
            <div style="display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .5rem">
                <form data-fetch-post="<?= e($actionBase) ?>/remove-role?id=<?= e((string) $contactId) ?>&amp;role=<?= e($role['key']) ?>">
                    <input type="hidden" name="entity_csrf" value="<?= e($confirmCsrf) ?>">
                    <button type="submit" class="be-btn be-btn--danger be-btn--sm"><?= e($role['label']) ?> entfernen</button>
                </form>
                <form data-fetch-post="<?= e($actionBase) ?>/toggle-role?id=<?= e((string) $contactId) ?>&amp;role=<?= e($role['key']) ?>">
                    <input type="hidden" name="value" value="1">
                    <button type="submit" class="be-btn be-btn--ghost be-btn--sm">Abbrechen</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($state->linkUrl !== ''): ?>
        <button type="button" class="be-btn be-btn--ghost be-btn--sm" data-fetch-get="<?= e($state->linkUrl) ?>"><?= e($state->linkLabel !== '' ? $state->linkLabel : $role['label'] . ' bearbeiten …') ?></button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
