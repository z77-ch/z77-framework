<?php
/**
 * Add / edit an account or a group. The number is fixed once the account
 * exists — settings and postings name accounts by number; the field is
 * read-only on edit and the controller never passes it on. There is no
 * delete: an account is deactivated on the list row.
 *
 * @var \Z77\Module\Financial\Entities\Account $entry
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var array<string,string> $typeLabels
 * @var list<\Z77\Module\Financial\Entities\Account> $groups  the groups a parent may be chosen from
 * @var string $actionBase
 */
$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/finance/account';
$parentId   = $entry->getParent()?->getId();

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post="<?= e($actionBase) ?>/<?= $isNew ? 'add' : 'edit?id=' . e((string) $entry->getId()) ?>">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Konto anlegen' : 'Konto «' . e($entry->label()) . '» bearbeiten' ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Kontonummer <small>(nur Ziffern<?= $isNew ? '' : ' — nach dem Anlegen fix' ?>)</small></label>
                <input type="text" name="number" value="<?= e($entry->getNumber()) ?>" maxlength="10" inputmode="numeric" autocomplete="off"
                       placeholder="z.B. 1020"<?= $isNew ? ' required' : ' readonly' ?>
                       aria-invalid="<?= $validator->hasFieldError('number') ? 'true' : 'false' ?>">
                <?= raw($fieldError('number')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Bezeichnung</label>
                <input type="text" name="name" value="<?= e($entry->getName()) ?>" maxlength="120" required autocomplete="off"
                       placeholder="z.B. Bankguthaben"
                       aria-invalid="<?= $validator->hasFieldError('name') ? 'true' : 'false' ?>">
                <?= raw($fieldError('name')) ?>
            </div>
        </div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Kontoart <small>(Bilanz: Aktiven, Fremdkapital, Eigenkapital — Erfolgsrechnung: Aufwand, Ertrag)</small></label>
                <select name="type" required aria-invalid="<?= $validator->hasFieldError('type') ? 'true' : 'false' ?>">
                    <?php foreach ($typeLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $entry->getType() === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('type')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Bebuchbar <small>(eine Gruppe sammelt Konten und trägt keine Buchungen)</small></label>
                <select name="postable" aria-invalid="<?= $validator->hasFieldError('postable') ? 'true' : 'false' ?>">
                    <option value="1"<?= $entry->isPostable() ? ' selected' : '' ?>>Ja — Konto</option>
                    <option value="0"<?= $entry->isPostable() ? '' : ' selected' ?>>Nein — Gruppe</option>
                </select>
                <?= raw($fieldError('postable')) ?>
            </div>
        </div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Gruppe <small>(übergeordnet)</small></label>
                <select name="parent_id" aria-invalid="<?= $validator->hasFieldError('parent') ? 'true' : 'false' ?>">
                    <option value="0"<?= $parentId === null ? ' selected' : '' ?>>– keine (oberste Ebene) –</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= e((string) $group->getId()) ?>"<?= $parentId === $group->getId() ? ' selected' : '' ?>><?= e($group->label()) ?><?= $group->isActive() ? '' : ' (inaktiv)' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('parent')) ?>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
