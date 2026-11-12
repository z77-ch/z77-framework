<?php
/**
 * «Sperren …» — the question, in the device window (from its «Sperren …») or as a window of
 * its own (from the row's ⋮). A confirm without any input field: its answers stand at the
 * bottom (`end`, the owner's one exception to ADR-049). «Abbrechen» leads back to the device.
 * Refused (already blocked, an owner above the actor): the reason and «Schliessen».
 *
 * @var bool $window
 * @var string $origin
 * @var \Z77\Module\Api\Entities\ApiDevice $device
 * @var string $ownerName
 * @var string|null $blockReason
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiDeviceLayout::BASE;
$window     = !empty($window);
$id         = (int) $device->getId();
$back       = $actionBase . '/edit?id=' . $id;
$winAttr    = $window
    ? ' data-window="api-device" data-window-entity="apiDevice:' . $id . '" data-window-title="' . e($device->getLabel() . ' sperren') . '" data-window-width="32rem"'
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
        <h2 class="be-list__section-title">«<?= e($device->getLabel()) ?>» von <?= e($ownerName) ?> sperren?</h2>
        <p>Das Gerät kann ab sofort nichts mehr senden; was es noch in der Warteschlange hat, wird abgewiesen. Was es bisher übermittelt hat, bleibt erhalten.</p>
        <p class="be-form__hint">Sperren ist endgültig. Soll das Handy wieder arbeiten, wird es neu gekoppelt.</p>
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
