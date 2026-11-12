<?php
/**
 * The app-user WINDOW (board «App-Benutzer», approved 2026-11-12; ADR-047, ADR-058) — new and
 * existing in one template:
 *
 *   - NEW: first the person — a GET form (`data-window-form="change"`, core.js «windows») whose
 *     choice re-renders this window for that person: the app e-mail proposed from the person's
 *     backend e-mail (editable), the function switches by the person's role. Persons with an app
 *     user or above the actor stay visible, disabled, the reason in the option. Without the
 *     script the form is a plain GET with its own «Wählen» button;
 *   - EXISTING: the person shown (fixed), the app e-mail, the functions, the devices with
 *     «Sperren …» each, «Deaktivieren …» (danger) / «Aktivieren …» in the action row — each loads
 *     its question into this window (`data-window-link`).
 *
 * The POST form comes first and carries the action row (ADR-049); the person form and the fields
 * follow — the fields belong to the POST form through `form="api-app-user-form"` (a GET form
 * cannot nest in it). A function the person's role (or the actor's) does not reach is a DISABLED
 * switch with its reason under it; the server keeps a disabled switch as it was
 * (ApiAppUserWindow::keep()). Read-only — person above the actor, person gone —: no save, no
 * buttons, inputs read-only, the reason on top.
 *
 * A plain form, no JavaScript of its own (Rule 7): `csrf_token` (`#[Csrf]`), an existing one also
 * the entity token. Help with a section per field (`edit.help`).
 *
 * @var bool   $window
 * @var string $origin
 * @var string $mask
 * @var bool   $isNew
 * @var \Z77\Module\Api\Entities\ApiAppUser $appUser   (new: a holder — linked id and e-mail)
 * @var \Z77\Shared\Entities\BackendUser|null $person
 * @var string $personName
 * @var string $personRole
 * @var list<array{id: int, label: string, disabled: bool}> $personChoices  (new only)
 * @var list<array{scope: string, label: string, hint: string, checked: bool, enabled: bool, reason: string}> $switches
 * @var array<string, string> $errors  field ('' = the window) => message
 * @var bool   $canEdit
 * @var string $readOnly
 * @var bool   $canDeactivate
 * @var bool   $canActivate
 * @var list<array{device: \Z77\Module\Api\Entities\ApiDevice, words: array{device: string, browser: string}, state: string, canRevoke: bool}> $devices
 * @var list<string> $facts
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var string $actionBase
 */
use Z77\Module\Api\Entities\ApiAppUser;
use Z77\Module\Api\Ui\ApiAppUserListing;

$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiAppUserLayout::BASE;
$window     = !empty($window);
$id         = (int) $appUser->getId();
$active     = $appUser->isActive();
$formId     = 'api-app-user-form';
$chosen     = $person !== null;
$showFields = !$isNew || $chosen;
$title      = $isNew ? 'Neuer App-Benutzer' : $personName . ' · App-Benutzer';
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . $id);
$link       = $window ? ' data-window-link' : '';
$winAttr    = $window
    ? ' data-window="' . e($mask) . '"' . ($isNew ? '' : ' data-window-entity="apiAppUser:' . $id . '"')
        . ' data-window-title="' . e($title) . '" data-window-width="40rem"'
    : '';

$fieldIds = ['linked_id' => 'api-app-user-linked_id', 'email' => 'api-app-user-email', 'scopes' => 'api-app-user-scope-0'];
$invalid  = array_values(array_filter(array_keys($fieldIds), static fn(string $f): bool => isset($errors[$f])));
$general  = $errors[''] ?? '';
$fieldError = static fn(string $name): string => isset($errors[$name])
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$name]) . '</small>'
    : '';
$ariaInvalid = static fn(string $name): string => isset($errors[$name]) ? 'true' : 'false';

$extra = '';
if ($canDeactivate) {
    $extra .= '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-deactivate?id=' . $id) . '"' . $link . '>Deaktivieren …</a>';
}
if ($canActivate) {
    $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/confirm-activate?id=' . $id) . '"' . $link . '>Aktivieren …</a>';
}
$stateBadge = ['live' => ['badge--success', 'aktiv'], 'expired' => ['badge--warning', 'abgelaufen'], 'revoked' => ['badge--muted', 'gesperrt']];
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="<?= $formId ?>" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if (!$isNew && $canEdit): ?><input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>"><?php endif; ?>
        <?php if ($isNew && $chosen): ?><input type="hidden" name="linked_id" value="<?= (int) $person->getId() ?>"><?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'     => $canEdit && $showFields ? 'Speichern' : '',
            'extra'      => $extra,
            'cancelHref' => $window ? '' : $actionBase . '/list',
            'errors'     => ['count' => count($invalid), 'target' => $fieldIds[$invalid[0] ?? ''] ?? ''],
        ], 'Z77\\Shared') ?>

        <div class="be-list__section-header">
            <?php if (!$window): ?><h2 class="be-list__section-title"><?= e($title) ?></h2><?php endif; ?>
            <?php if (!$isNew): ?>
            <span class="badge <?= $active ? 'badge--success' : 'badge--muted' ?>" data-app-user-state="<?= $active ? 'active' : 'inactive' ?>"><?= $active ? 'aktiv' : 'deaktiviert' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($general !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($general) ?></div>
        <?php endif; ?>
        <?php if ($readOnly !== ''): ?>
        <p class="be-form__hint be-form__hint--warn" data-app-user-readonly><?= e($readOnly) ?></p>
        <?php elseif (!$isNew && !$active): ?>
        <p class="be-form__hint">Deaktiviert: kein Gerät ist angemeldet. «Aktivieren …» lässt die Person sich auf dem Handy wieder per Code anmelden.</p>
        <?php endif; ?>
    </form>

    <?php if ($isNew): ?>
    <form method="get" action="<?= e($actionBase . '/add') ?>" class="be-list__section" data-window-form="change">
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="<?= $fieldIds['linked_id'] ?>">Person</label>
                <select id="<?= $fieldIds['linked_id'] ?>" name="linked_id" required aria-invalid="<?= $ariaInvalid('linked_id') ?>">
                    <option value=""<?= $chosen ? '' : ' selected' ?>>— Person wählen —</option>
                    <?php foreach ($personChoices as $choice): ?>
                    <option value="<?= (int) $choice['id'] ?>"<?= $chosen && (int) $person->getId() === (int) $choice['id'] ? ' selected' : '' ?><?= $choice['disabled'] ? ' disabled' : '' ?>><?= e($choice['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="be-list__cell--muted">Backend-Benutzer · Mitglieder folgen später</small>
                <?= raw($fieldError('linked_id')) ?>
            </div>
            <?php if (!$window): ?>
            <div class="be-form__field">
                <button type="submit" class="be-btn be-btn--ghost">Wählen</button>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($showFields): ?>
    <div class="be-list__section">
        <div class="be-form__grid">
            <?php if (!$isNew): ?>
            <div class="be-form__field">
                <label for="api-app-user-person">Person</label>
                <input type="text" id="api-app-user-person" value="<?= e($personName . ($personRole !== '' ? ' · ' . $personRole : '')) ?>" readonly data-help-key="linked_id">
                <small class="be-list__cell--muted"><?= $appUser->getLinkedRealm() === ApiAppUser::REALM_MEMBER ? 'Mitglied' : 'Backend-Benutzer' ?> · Mitglieder folgen später</small>
            </div>
            <?php endif; ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="<?= $fieldIds['email'] ?>">App-E-Mail</label>
                <input type="email" id="<?= $fieldIds['email'] ?>" name="email" form="<?= $formId ?>" value="<?= e($appUser->getEmail()) ?>" maxlength="<?= ApiAppUser::EMAIL_LENGTH ?>" spellcheck="false" aria-invalid="<?= $ariaInvalid('email') ?>"<?= $canEdit ? ' required' : ' readonly' ?>>
                <?= raw($fieldError('email')) ?>
            </div>
        </div>

        <div class="be-form__section">Funktionen <small>(Kacheln in der App)</small></div>
        <?php if ($switches === []): ?>
        <p class="be-form__hint">Kein Modul bietet der App eine Funktion an.</p>
        <?php endif; ?>
        <?php foreach ($switches as $i => $switch): ?>
        <div class="be-form__field" data-z77-field-wrapper data-app-user-scope="<?= e($switch['scope']) ?>">
            <label class="be-switch">
                <input type="checkbox" class="be-switch__input" id="api-app-user-scope-<?= (int) $i ?>" name="scopes[]" form="<?= $formId ?>" value="<?= e($switch['scope']) ?>"<?= $switch['checked'] ? ' checked' : '' ?><?= $switch['enabled'] ? '' : ' disabled' ?> data-help-key="scopes">
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                <span class="be-switch__label"><?= e($switch['label']) ?><?php if ($switch['hint'] !== ''): ?> <small class="be-list__cell--muted"><?= e($switch['hint']) ?></small><?php endif; ?></span>
            </label>
            <?php if ($switch['reason'] !== ''): ?><p class="be-form__hint be-form__hint--warn" data-app-user-scope-reason><?= e($switch['reason']) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?= raw($fieldError('scopes')) ?>
        <p class="be-form__hint">Wählbar ist, was die Rolle der Person erlaubt und was du selbst darfst. Fällt ihre Rolle später tiefer, gilt die Funktion ab der nächsten Anfrage nicht mehr.</p>
    </div>
    <?php endif; ?>

    <?php if (!$isNew): ?>
    <div class="be-list__section" data-app-user-devices>
        <div class="be-list__section-header">
            <h3 class="be-list__section-title">Geräte</h3>
            <span class="be-list__section-badge" title="Geräte"><?= count($devices) ?></span>
        </div>
        <?php if ($devices === []): ?>
        <p class="be-form__hint">Noch kein Gerät. Meldet sich das Handy unter /app mit einem Code an, erscheint es hier.</p>
        <?php else: ?>
        <div class="be-list__table" style="--be-list-cols: minmax(6rem, 1fr) minmax(6rem, 1fr) 8rem 6rem 6.5rem">
            <div class="be-list__head">
                <span class="be-list__col">Gerät</span>
                <span class="be-list__col">Browser</span>
                <span class="be-list__col">Zuletzt benutzt</span>
                <span class="be-list__col">Status</span>
                <span class="be-list__col" aria-label="Sperren"></span>
            </div>
            <?php foreach ($devices as $row): $device = $row['device']; [$badge, $word] = $stateBadge[$row['state']] ?? $stateBadge['live']; ?>
            <div class="be-list__item" data-entity="apiDevice:<?= (int) $device->getId() ?>">
                <div class="be-list__row<?= $row['state'] === 'live' ? '' : ' be-list__row--inactive' ?>">
                    <span class="be-list__cell"><?= e($row['words']['device']) ?></span>
                    <span class="be-list__cell be-list__cell--muted"><?= e($row['words']['browser']) ?></span>
                    <span class="be-list__cell be-list__cell--mono" title="angemeldet <?= e(ApiAppUserListing::dateTime($device->getCreatedAt())) ?>"><?= e(ApiAppUserListing::when($device->getLastUsedAt())) ?></span>
                    <span class="be-list__cell"><span class="badge <?= $badge ?>"<?= $device->isRevoked() ? ' title="gesperrt ' . e(ApiAppUserListing::dateTime($device->getRevokedAt())) . '"' : '' ?>><?= $word ?></span></span>
                    <span class="be-list__cell be-list__cell--actions"><?php if ($row['canRevoke']): ?><a class="be-btn be-btn--danger be-btn--sm" href="<?= e($actionBase . '/confirm-revoke?id=' . (int) $device->getId()) ?>"<?= $link ?>>Sperren …</a><?php endif; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($facts !== []): ?>
        <p class="be-form__hint be-list__cell--mono"><?= e(implode('  |  ', $facts)) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
