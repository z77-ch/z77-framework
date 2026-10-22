<?php
/**
 * Form-mail settings row context menu (⋮, core.js «context menu», owner 2026-10-22): edit +
 * reset. Only the items — each opens its dialog via data-fetch-get. Reset only when a backend
 * override exists.
 *
 * @var string $formKey
 * @var bool $hasConfig
 * @var bool $hasEntity
 */
$k  = rawurlencode($formKey);
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($formKey) ?>»">
    <?php if ($hasConfig): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/service/email-settings/edit?key=<?= e($k) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
    <?php endif; ?>
    <?php if ($hasEntity): ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/service/email-settings/confirm-reset?key=<?= e($k) ?>"><?= raw($ic('icon-trash')) ?> Zurücksetzen</button>
    <?php endif; ?>
</div>
