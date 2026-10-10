<?php
/**
 * The fiscal year's ⋮ hub (owner 2026-10-10): the year actions that used to stand as buttons
 * at the far right of the year's header — «Jahr abschliessen …», «Wieder öffnen …»,
 * «Löschen …» — only those the rules allow now ({@see FiscalYearControllerTrait::fiscalYearActions()}).
 * Each opens its confirm (a click replaces this hub). Mirrors every backend ⋮ hub
 * (LIST-ACTIONS-HUB-001).
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var list<string> $actions  'close' | 'reopen' | 'delete'
 * @var string $actionBase
 */
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$q  = '?id=' . (int) $year->getId();
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — Geschäftsjahr «<?= e($year->getCode()) ?>»</h2></div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <?php if (in_array('close', $actions, true)): ?>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="<?= e($actionBase) ?>/confirm-close<?= $q ?>"><?= raw($ic('icon-lock')) ?> Jahr abschliessen …</button>
            <?php endif; ?>
            <?php if (in_array('reopen', $actions, true)): ?>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="<?= e($actionBase) ?>/confirm-reopen<?= $q ?>"><?= raw($ic('icon-edit')) ?> Wieder öffnen …</button>
            <?php endif; ?>
            <?php if (in_array('delete', $actions, true)): ?>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="<?= e($actionBase) ?>/confirm-delete<?= $q ?>"><?= raw($ic('icon-trash')) ?> Löschen …</button>
            <?php endif; ?>
            <?php if ($actions === []): ?>
            <p class="be-list__empty">Für dieses Geschäftsjahr ist gerade keine Aktion möglich.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
