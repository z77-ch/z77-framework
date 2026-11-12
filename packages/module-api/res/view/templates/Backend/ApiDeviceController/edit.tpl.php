<?php
/**
 * The device WINDOW (board «Geräte», 2026-11-12; ADR-047): only the name and the scopes can be
 * changed, the facts stand in one muted line below. «Sperren …» loads the question into this
 * window (`data-window-link`). A blocked device — or one of an owner above the actor — is
 * read-only: no save, no «Sperren …», the switches disabled. There is no «aktiv» switch:
 * blocking is final (E13), the way back is a new pairing.
 *
 * A plain form, no JavaScript of its own (Rule 7): `csrf_token` (`#[Csrf]`) plus the entity
 * token. Help with a section per field (`edit.help`).
 *
 * @var bool $window
 * @var string $origin
 * @var \Z77\Module\Api\Entities\ApiDevice $device
 * @var string $ownerName
 * @var bool $canEdit
 * @var array<string, array{label: string, hint: string}> $scopes  declared ones + those the device holds beyond them
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var string $actionBase
 */
use Z77\Module\Api\Ui\ApiDeviceListing;

$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiDeviceLayout::BASE;
$window     = !empty($window);
$id         = (int) $device->getId();
$revoked    = $device->isRevoked();
$title      = $device->getLabel() . ' · ' . $ownerName;
$winAttr    = $window
    ? ' data-window="api-device" data-window-entity="apiDevice:' . $id . '" data-window-title="' . e($title) . '" data-window-width="38rem"'
    : '';
$confirm    = $actionBase . '/confirm-revoke?id=' . $id;
$revokeBtn  = $canEdit ? '<a class="be-btn be-btn--danger" href="' . e($confirm) . '"' . ($window ? ' data-window-link' : '') . '>Sperren …</a>' : '';
$facts      = array_filter([
    $device->getAgent(),
    'gekoppelt ' . ApiDeviceListing::dateTime($device->getCreatedAt()),
    'zuletzt benutzt ' . ApiDeviceListing::when($device->getLastUsedAt()),
    $revoked ? 'gesperrt ' . ApiDeviceListing::dateTime($device->getRevokedAt()) : '',
]);
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase) ?>/edit?id=<?= $id ?>" class="be-list__section" id="api-device-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if ($canEdit): ?><input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>"><?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'     => $canEdit ? 'Speichern' : '',
            'extra'      => $revokeBtn,
            'cancelHref' => $window ? '' : $actionBase . '/list',
        ], 'Z77\\Shared') ?>

        <div class="be-list__section-header">
            <?php if (!$window): ?><h2 class="be-list__section-title"><?= e($title) ?></h2><?php endif; ?>
            <?php if ($revoked): ?>
            <span class="badge badge--muted">gesperrt</span>
            <?php else: ?>
            <span class="badge badge--success">aktiv</span>
            <?php endif; ?>
        </div>

        <?php if ($revoked): ?>
        <p class="be-form__hint">Gesperrt ist endgültig: das Gerät kann nichts mehr senden. Wieder benutzen heisst neu koppeln («+ Gerät koppeln»).</p>
        <?php elseif (!$canEdit): ?>
        <p class="be-form__hint">Der Besitzer dieses Geräts hat eine höhere Rolle als du — du kannst es ansehen, nicht ändern.</p>
        <?php endif; ?>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="api-device-edit-label">Gerätename</label>
                <input type="text" id="api-device-edit-label" name="label" value="<?= e($device->getLabel()) ?>" maxlength="<?= \Z77\Module\Api\Services\ApiDevices::LABEL_LENGTH ?>"<?= $canEdit ? ' required' : ' readonly' ?>>
            </div>
            <div class="be-form__section">Berechtigungen</div>
            <?php if ($scopes === []): ?>
            <p class="be-form__hint">Kein Modul bietet Geräten etwas an.</p>
            <?php endif; ?>
            <?php foreach ($scopes as $scope => $words): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" name="scopes[]" value="<?= e($scope) ?>"<?= in_array($scope, $device->getScopes(), true) ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?> data-help-key="scopes">
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label"><?= e($words['label']) ?><?php if ($words['hint'] !== ''): ?> <small class="be-list__cell--muted"><?= e($words['hint']) ?></small><?php endif; ?></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="be-form__hint be-list__cell--mono"><?= e(implode('  |  ', $facts)) ?></p>
    </form>
</div>
