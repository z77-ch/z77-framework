<?php
/**
 * BackendUser row context menu (⋮, core.js «context menu», owner 2026-10-22): edit + delete.
 * Only the items — each opens its dialog via data-fetch-get. Delete-protection is enforced by
 * the confirmDelete/remove actions, the 2FA reset's rule by its own two actions.
 *
 * @var \Z77\Shared\Entities\BackendUser $entry
 * @var bool $canManage          false: the account holds a role above the actor's; the actions refuse too
 * @var bool $canResetTwoFactor
 */
$id = $entry->getId();
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($entry->getUsername()) ?>»">
    <?php if ($canManage): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/system/backend-user/edit?id=<?= e($id) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
    <?php if ($canResetTwoFactor): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/system/backend-user/confirm-two-factor-reset?id=<?= e($id) ?>"><?= raw($ic('icon-lock')) ?> 2FA zurücksetzen</button>
    <?php endif; ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/system/backend-user/confirm-delete?id=<?= e($id) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
    <?php else: ?>
    <p class="be-menu__note">Dieses Konto hat eine höhere Rolle als du.</p>
    <?php endif; ?>
</div>
