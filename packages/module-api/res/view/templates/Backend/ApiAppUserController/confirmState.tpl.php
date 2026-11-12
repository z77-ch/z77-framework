<?php
/**
 * «Deaktivieren …» / «Aktivieren …» — the question, in the app-user window (its action row) or as a
 * window of its own (the row's ⋮). A confirm without any input field: its answers stand at the
 * bottom (`end`, the owner's one exception to ADR-049). «Abbrechen» leads back to the app user.
 * Refused (already in that state, a person above the actor, the person gone): the reason and
 * «Zurück».
 *
 * Deactivating is final for the devices (ADR-058 §4 «Lifecycle», E13): all are signed out, pending
 * codes expire; activating brings none back — every phone signs in anew by code.
 *
 * @var bool $window
 * @var string $origin
 * @var string $mask
 * @var string $mode   'deactivate' | 'activate'
 * @var \Z77\Module\Api\Entities\ApiAppUser $appUser
 * @var string $personName
 * @var int $deviceCount  devices that can sign in now
 * @var string|null $blockReason
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiAppUserLayout::BASE;
$window     = !empty($window);
$id         = (int) $appUser->getId();
$off        = $mode === 'deactivate';
$back       = $actionBase . '/edit?id=' . $id;
$title      = $personName . ($off ? ' deaktivieren' : ' aktivieren');
$winAttr    = $window
    ? ' data-window="' . e($mask) . '" data-window-entity="apiAppUser:' . $id . '" data-window-title="' . e($title) . '" data-window-width="32rem"'
    : '';
$devices    = $deviceCount === 1 ? 'Das angemeldete Gerät ist' : ($deviceCount === 0 ? 'Angemeldete Geräte sind' : 'Alle ' . $deviceCount . ' angemeldeten Geräte sind');
?>
<div class="be-list" data-app-user-confirm="<?= $off ? 'deactivate' : 'activate' ?>"<?= $winAttr ?>>
    <?php if ($blockReason !== null): ?>
    <div class="be-list__section">
        <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Zurück', 'cancelHref' => $back], 'Z77\\Shared') ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($blockReason) ?></div>
    </div>
    <?php else: ?>
    <form method="post" action="<?= e($actionBase . ($off ? '/deactivate' : '/activate')) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <?php if ($off): ?>
        <h2 class="be-list__section-title"><?= e($personName) ?> als App-Benutzer deaktivieren?</h2>
        <p><?= e($devices) ?> sofort abgemeldet; offene Anmelde-Codes verfallen. Was bisher erfasst wurde, bleibt erhalten.</p>
        <p class="be-form__hint">Das ist für die Geräte endgültig: wird der App-Benutzer wieder aktiviert, meldet sich jedes Gerät neu per Code an.</p>
        <?php else: ?>
        <h2 class="be-list__section-title"><?= e($personName) ?> als App-Benutzer wieder aktivieren?</h2>
        <p>Die früheren Geräte bleiben abgemeldet. Auf dem Handy unter /app neu anmelden — der Code geht an <?= e($appUser->getEmail()) ?>.</p>
        <?php endif; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $off ? 'Deaktivieren' : 'Aktivieren',
            'kind'       => $off ? 'danger' : 'primary',
            'cancel'     => 'Abbrechen',
            'cancelHref' => $back,
            'end'        => true,
        ], 'Z77\\Shared') ?>
    </form>
    <?php endif; ?>
</div>
