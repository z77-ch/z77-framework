<?php
/** @var \Z77\Shared\Entities\BackendUser $entry */
/** @var string $entityCsrf */
/** @var array<string, string> $roleLabels */
/** @var \Z77\Persistence\Validation\EntityValidator $validator */

$isNew = $entry->getId() === null;

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};

$identities      = $entry->getSenderIdentities();
$invalidIdentity = $validator instanceof \Z77\Shared\Validators\BackendUserValidator ? $validator->invalidSenderIdentity() : null;

/** One sender-identity row; $key is the row key of the field names (`__i__` in the template). */
$identityRow = static function (string $key, string $name, string $email, bool $invalid): string {
    $aria = $invalid ? 'true' : 'false';
    return '<div class="be-list__item" data-row><div class="be-list__row">'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="bu-identity-name-' . e($key) . '" name="identity_name[' . e($key) . ']" value="' . e($name) . '"'
        . ' maxlength="' . \Z77\Shared\Mail\SenderIdentity::NAME_LENGTH . '" autocomplete="off" placeholder="z.B. Max Muster" aria-label="Anzeigename" aria-invalid="' . $aria . '"></span>'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="email" id="bu-identity-email-' . e($key) . '" name="identity_email[' . e($key) . ']" value="' . e($email) . '"'
        . ' maxlength="' . \Z77\Shared\Mail\SenderIdentity::EMAIL_LENGTH . '" autocomplete="off" placeholder="z.B. max@firma.ch" aria-label="E-Mail" aria-invalid="' . $aria . '"></span>'
        . '<span class="be-list__cell be-rows__end"><button type="button" class="be-rows__remove" data-row-remove title="Absender entfernen" aria-label="Absender entfernen">×</button></span>'
        . '</div></div>';
};

// «n Fehler» in the action row (ADR-049): count + the first invalid field in document order.
$fieldIds = ['username' => 'bu-username', 'password' => 'bu-password', 'initials' => 'bu-initials', 'name' => 'bu-name',
    'sender_identities' => 'bu-identity-name-' . ($invalidIdentity ?? 0), 'roles' => 'bu-roles'];
$invalid  = array_values(array_filter(array_keys($fieldIds), [$validator, 'hasFieldError']));
$errors   = ['count' => count($validator->getFieldErrors()), 'target' => $fieldIds[$invalid[0] ?? ''] ?? ''];
?>
<form data-fetch-post data-check-url="/backend/system/backend-user/check-field">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Benutzer anlegen' : 'Benutzer bearbeiten' ?></h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Speichern', 'errors' => $errors], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
        <div class="be-form__grid" style="grid-template-columns:1fr 1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Benutzername</label>
                <input type="text" id="bu-username" name="username" value="<?= e($entry->getUsername()) ?>" required autocomplete="off"
                       placeholder="z.B. max"
                       aria-invalid="<?= $validator->hasFieldError('username') ? 'true' : 'false' ?>">
                <?= raw($fieldError('username')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Passwort <?php if (!$isNew): ?><small>(leer = unverändert)</small><?php else: ?><small>(mind. <?= e($passwordMinLength) ?> Zeichen)</small><?php endif; ?></label>
                <input type="password" id="bu-password" name="password" value="" autocomplete="new-password" data-z77-password data-z77-password-min="<?= e($passwordMinLength) ?>"<?= $isNew ? ' required' : '' ?>
                       placeholder="<?= $isNew ? 'mind. ' . e($passwordMinLength) . ' Zeichen' : '•••••••• (unverändert)' ?>"
                       aria-invalid="<?= $validator->hasFieldError('password') ? 'true' : 'false' ?>">
                <div data-z77-password-meter></div>
                <?= raw($fieldError('password')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Initialen <small>(2–3 Zeichen für den Avatar; leer = automatisch)</small></label>
                <input type="text" id="bu-initials" name="initials" value="<?= e($entry->getInitials()) ?>" maxlength="3" autocomplete="off"
                       placeholder="z.B. MX"
                       aria-invalid="<?= $validator->hasFieldError('initials') ? 'true' : 'false' ?>">
                <?= raw($fieldError('initials')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Name <small>(Vor- und Nachname, erscheint auf dem Dashboard)</small></label>
                <input type="text" id="bu-name" name="name" value="<?= e($entry->getName()) ?>" maxlength="<?= \Z77\Shared\Entities\BackendUser::NAME_LENGTH ?>" autocomplete="off"
                       placeholder="z.B. Max Muster"
                       aria-invalid="<?= $validator->hasFieldError('name') ? 'true' : 'false' ?>">
                <?= raw($fieldError('name')) ?>
            </div>
        </div>
        <?php // Sender identities (owner 2026-10-23): rows of display name + e-mail, the first the default.
              // core.js «rows» adds (clone of the template) and removes them. The names are two parallel
              // one-bracket maps — identity_name[k] / identity_email[k] — because a fetch form nests ONE
              // bracket level only (_z77CollectFormData); the container's prefix `identity` matches neither
              // name, so the rows keep their keys (0…n-1 from the server, n1… for new ones), and without a
              // grip the posted order IS the shown order (numeric keys first, the new ones after). ?>
        <div class="be-form__section">Absender für E-Mails <small>(Anzeigename und Antwortadresse, wenn du E-Mails auslöst; die erste Zeile ist der Standard)</small></div>
        <div class="be-form__field" data-z77-field-wrapper>
            <div class="be-list__frame">
                <div class="be-list__table be-rows" style="--be-list-cols: minmax(8rem, 1fr) minmax(10rem, 1.3fr) 2rem" data-rows="identity">
                    <div class="be-list__head">
                        <span class="be-list__col">Anzeigename</span>
                        <span class="be-list__col">E-Mail</span>
                        <span class="be-list__col"></span>
                    </div>
                    <?php foreach ($identities as $i => $identity): ?>
                    <?= raw($identityRow((string) $i, $identity->name, $identity->email, $invalidIdentity === $i)) ?>
                    <?php endforeach; ?>
                    <template data-row-template="identity"><?= raw($identityRow('__i__', '', '', false)) ?></template>
                </div>
            </div>
            <div class="be-rows__adders">
                <button type="button" class="be-btn be-btn--add be-btn--sm" data-row-add="identity"><span aria-hidden="true">+</span> Absender</button>
            </div>
            <?php if ($identities === []): ?>
            <small class="be-list__cell--muted">Noch kein Absender — ohne ihn kannst du keine E-Mails auslösen (z.B. Belege per E-Mail).</small>
            <?php endif; ?>
            <?= raw($fieldError('sender_identities')) ?>
        </div>
        <div class="be-form__section">Rollen <small>(mindestens eine; die höchste bestimmt die Zugriffsstufe)</small></div>
        <div class="be-form__field" data-z77-field-wrapper>
            <div class="be-form__tag-grid">
                <?php $firstRole = true; foreach ($roleLabels as $roleKey => $roleLabel): ?>
                <label class="be-choice">
                    <input type="checkbox" class="be-choice__input"<?= $firstRole ? ' id="bu-roles"' : '' ?> name="roles" value="<?= e($roleKey) ?>"<?= in_array($roleKey, $entry->getRoles(), true) ? ' checked' : '' ?>>
                    <span class="be-choice__label"><?= e($roleLabel) ?></span>
                </label>
                <?php $firstRole = false; endforeach; ?>
            </div>
            <?= raw($fieldError('roles')) ?>
        </div>
    </div>
</form>
