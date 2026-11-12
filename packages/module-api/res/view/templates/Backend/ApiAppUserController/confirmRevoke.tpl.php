<?php
/**
 * «Sperren …» of one device — the question, loaded into the app-user window from the device row.
 * A confirm without any input field: its answers stand at the bottom (`end`, the owner's one
 * exception to ADR-049). «Abbrechen» leads back to the app user. Refused (already blocked, a person
 * above the actor): the reason and «Zurück».
 *
 * @var bool $window
 * @var string $origin
 * @var string $mask
 * @var \Z77\Module\Api\Entities\ApiAppUser $appUser
 * @var \Z77\Module\Api\Entities\ApiDevice $device
 * @var array{device: string, browser: string} $deviceWords
 * @var string $personName
 * @var string|null $blockReason
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiAppUserLayout::BASE;
$window     = !empty($window);
$id         = (int) $device->getId();
$back       = $actionBase . '/edit?id=' . (int) $appUser->getId();
$name       = $deviceWords['device'];
$winAttr    = $window
    ? ' data-window="' . e($mask) . '" data-window-entity="apiAppUser:' . (int) $appUser->getId() . '" data-window-title="' . e($name . ' sperren') . '" data-window-width="32rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <?php if ($blockReason !== null): ?>
    <div class="be-list__section">
        <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Zurück', 'cancelHref' => $back], 'Z77\\Shared') ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($blockReason) ?></div>
    </div>
    <?php else: ?>
    <form method="post" action="<?= e($actionBase) ?>/revoke" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <h2 class="be-list__section-title"><?= e($name) ?> von <?= e($personName) ?> sperren?</h2>
        <p>Das Gerät kann ab sofort nichts mehr senden; was es noch in der Warteschlange hat, wird abgewiesen. Was es bisher übermittelt hat, bleibt erhalten.</p>
        <p class="be-form__hint">Sperren ist endgültig. Soll das Handy wieder arbeiten, meldet es sich neu an — mit einem Code an die App-E-Mail.</p>
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Sperren',
            'kind'       => 'danger',
            'cancel'     => 'Abbrechen',
            'cancelHref' => $back,
            'end'        => true,
        ], 'Z77\\Shared') ?>
    </form>
    <?php endif; ?>
</div>
