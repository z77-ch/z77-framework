<?php
/** @var \Z77\Shared\Entities\LoginUser $entry */
/** @var string $entityCsrf */
/** @var array<string, string> $roleLabels */
/** @var \Z77\Persistence\Validation\EntityValidator $validator */

$isNew = $entry->getId() === null;

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post data-check-url="/backend/system/login-user/check-field">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Benutzer anlegen' : 'Benutzer bearbeiten' ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
        <div class="be-form__grid" style="grid-template-columns:1fr 1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Benutzername</label>
                <input type="text" name="username" value="<?= e($entry->getUsername()) ?>" required autocomplete="off"
                       placeholder="z.B. max"
                       aria-invalid="<?= $validator->hasFieldError('username') ? 'true' : 'false' ?>">
                <?= raw($fieldError('username')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Passwort <?php if (!$isNew): ?><small>(leer = unverändert)</small><?php else: ?><small>(mind. <?= e($passwordMinLength) ?> Zeichen)</small><?php endif; ?></label>
                <input type="password" name="password" value="" autocomplete="new-password" data-z77-password data-z77-password-min="<?= e($passwordMinLength) ?>"<?= $isNew ? ' required' : '' ?>
                       placeholder="<?= $isNew ? 'mind. ' . e($passwordMinLength) . ' Zeichen' : '•••••••• (unverändert)' ?>"
                       aria-invalid="<?= $validator->hasFieldError('password') ? 'true' : 'false' ?>">
                <div data-z77-password-meter></div>
                <?= raw($fieldError('password')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Initialen <small>(2–3 Zeichen für den Avatar; leer = automatisch)</small></label>
                <input type="text" name="initials" value="<?= e($entry->getInitials()) ?>" maxlength="3" autocomplete="off"
                       placeholder="z.B. MX"
                       aria-invalid="<?= $validator->hasFieldError('initials') ? 'true' : 'false' ?>">
                <?= raw($fieldError('initials')) ?>
            </div>
        </div>
        <div class="be-form__section">Rollen <small>(mindestens eine; die höchste bestimmt die Zugriffsstufe)</small></div>
        <div class="be-form__field" data-z77-field-wrapper>
            <div class="be-form__tag-grid">
                <?php foreach ($roleLabels as $roleKey => $roleLabel): ?>
                <label class="be-choice">
                    <input type="checkbox" class="be-choice__input" name="roles" value="<?= e($roleKey) ?>"<?= in_array($roleKey, $entry->getRoles(), true) ? ' checked' : '' ?>>
                    <span class="be-choice__label"><?= e($roleLabel) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?= raw($fieldError('roles')) ?>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
