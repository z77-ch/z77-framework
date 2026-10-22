<?php
/**
 * Document-mail row context menu (⋮, core.js «context menu», MENU-001): «Bearbeiten» (a kind
 * a module declares) and «Zurücksetzen» (only when an operator text exists). Items only.
 *
 * @var string $kind
 * @var bool $declared
 * @var bool $hasEntity
 */
$k  = rawurlencode($kind);
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($kind) ?>»">
    <?php if ($declared): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/service/email-settings/document-edit?kind=<?= e($k) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
    <?php endif; ?>
    <?php if ($hasEntity): ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/service/email-settings/confirm-document-reset?kind=<?= e($k) ?>"><?= raw($ic('icon-trash')) ?> Zurücksetzen</button>
    <?php endif; ?>
</div>
