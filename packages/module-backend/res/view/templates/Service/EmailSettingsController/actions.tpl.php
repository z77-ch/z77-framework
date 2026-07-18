<?php
/**
 * Form-mail settings row action hub (⋮): edit + reset. Launches the specific
 * modals via data-fetch-get (a click replaces this hub). Reset only when a
 * backend override exists. Mirrors the login-user / navigation actions hub.
 *
 * @var string $formKey
 * @var bool $hasConfig
 * @var bool $hasEntity
 */
$k  = rawurlencode($formKey);
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($formKey) ?>»</h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <?php if ($hasConfig): ?>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/service/email-settings/edit?key=<?= e($k) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
            <?php endif; ?>
            <?php if ($hasEntity): ?>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/service/email-settings/confirm-reset?key=<?= e($k) ?>"><?= raw($ic('icon-trash')) ?> Zurücksetzen</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
