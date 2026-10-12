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
 *   switched now is a disabled switch with the reason under it.
 * - NEW contact («Kontakt anlegen»): the switch is a form field `roles[<key>]`; the trait
 *   applies the ticked roles AFTER the contact is saved.
 *
 * @var int|null $contactId  null = «Kontakt anlegen»
 * @var list<array{key: string, label: string, state: \Z77\Module\Contact\Roles\ContactRoleState, chosen: bool}> $roles
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/contact/contact';
$isNew      = $contactId === null;
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
        <?php if ($state->linkUrl !== ''): ?>
        <button type="button" class="be-btn be-btn--ghost be-btn--sm" data-fetch-get="<?= e($state->linkUrl) ?>"><?= e($state->linkLabel !== '' ? $state->linkLabel : $role['label'] . ' bearbeiten …') ?></button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
