<?php
/**
 * The app-user row's ⋮ context menu (core.js «context menu», MENU-001 — no header, no
 * «Schliessen»): «Öffnen» opens the window; «Deaktivieren …» / «Aktivieren …» open the question
 * in a window, only where the actor may (the actions ask again). The board's «Belege dieses
 * App-Benutzers» is not here: module-api does not know the receipt journal (api.md API-010 a).
 *
 * @var \Z77\Module\Api\Entities\ApiAppUser $appUser
 * @var string $personName
 * @var bool $canDeactivate
 * @var bool $canActivate
 * @var string $region      the list region a window's answer reloads
 * @var string $actionBase
 */
$ic   = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$id   = (int) $appUser->getId();
$open = $actionBase . '/edit?id=' . $id;
$off  = $actionBase . '/confirm-deactivate?id=' . $id;
$on   = $actionBase . '/confirm-activate?id=' . $id;
?>
<div class="be-menu" role="menu" aria-label="Aktionen — <?= e($personName) ?>">
    <a class="be-menu__item" role="menuitem" href="<?= e($open) ?>" data-window-open="<?= e($open) ?>" data-origin="region:<?= e($region) ?>"><?= raw($ic('icon-eye')) ?> Öffnen</a>
    <?php if ($canDeactivate): ?>
    <a class="be-menu__item be-menu__item--danger" role="menuitem" href="<?= e($off) ?>" data-window-open="<?= e($off) ?>" data-origin="region:<?= e($region) ?>"><?= raw($ic('icon-lock')) ?> Deaktivieren …</a>
    <?php endif; ?>
    <?php if ($canActivate): ?>
    <a class="be-menu__item" role="menuitem" href="<?= e($on) ?>" data-window-open="<?= e($on) ?>" data-origin="region:<?= e($region) ?>"><?= raw($ic('icon-check')) ?> Aktivieren …</a>
    <?php endif; ?>
</div>
