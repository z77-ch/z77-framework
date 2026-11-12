<?php
/**
 * The device row's ⋮ context menu (core.js «context menu», MENU-001 — no header, no
 * «Schliessen»): «Öffnen» opens the device window; «Sperren …» opens the question in a window,
 * only while the device is live and the actor may block it (the actions ask again).
 *
 * @var \Z77\Module\Api\Entities\ApiDevice $device
 * @var bool $canRevoke
 * @var string $region      the list region a window's answer reloads
 * @var string $actionBase
 */
$ic      = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$id      = (int) $device->getId();
$open    = $actionBase . '/edit?id=' . $id;
$confirm = $actionBase . '/confirm-revoke?id=' . $id;
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($device->getLabel()) ?>»">
    <a class="be-menu__item" role="menuitem" href="<?= e($open) ?>" data-window-open="<?= e($open) ?>" data-origin="region:<?= e($region) ?>"><?= raw($ic('icon-eye')) ?> Öffnen</a>
    <?php if ($canRevoke): ?>
    <a class="be-menu__item be-menu__item--danger" role="menuitem" href="<?= e($confirm) ?>" data-window-open="<?= e($confirm) ?>" data-origin="region:<?= e($region) ?>"><?= raw($ic('icon-lock')) ?> Sperren …</a>
    <?php endif; ?>
</div>
