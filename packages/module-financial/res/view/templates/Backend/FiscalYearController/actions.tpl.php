<?php
/**
 * The fiscal year's ⋮ context menu (core.js «context menu», owner 2026-10-22; the year actions
 * moved from the year's header into the ⋮ on 2026-10-10): «Jahr abschliessen …», «Wieder
 * öffnen …», «Löschen …» — only those the rules allow now
 * ({@see FiscalYearControllerTrait::fiscalYearActions()}), and since P5 (2026-10-22)
 * «Eröffnung erstellen …» / «Eröffnung neu erstellen …». Each opens its confirm dialog.
 * Nothing allowed → a muted line instead of items.
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var list<string> $actions  'opening' | 'close' | 'reopen' | 'delete'
 * @var bool $openingExists  the year carries its generated opening — the entry reads «neu erstellen»
 * @var string $actionBase
 */
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$q  = '?id=' . (int) $year->getId();
?>
<div class="be-menu" role="menu" aria-label="Aktionen — Geschäftsjahr «<?= e($year->getCode()) ?>»">
    <?php if (in_array('opening', $actions, true)): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($actionBase) ?>/confirm-opening<?= $q ?>"><?= raw($ic('icon-file-text')) ?> <?= ($openingExists ?? false) ? 'Eröffnung neu erstellen …' : 'Eröffnung erstellen …' ?></button>
    <?php endif; ?>
    <?php if (in_array('close', $actions, true)): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($actionBase) ?>/confirm-close<?= $q ?>"><?= raw($ic('icon-lock')) ?> Jahr abschliessen …</button>
    <?php endif; ?>
    <?php if (in_array('reopen', $actions, true)): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($actionBase) ?>/confirm-reopen<?= $q ?>"><?= raw($ic('icon-edit')) ?> Wieder öffnen …</button>
    <?php endif; ?>
    <?php if (in_array('delete', $actions, true)): ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="<?= e($actionBase) ?>/confirm-delete<?= $q ?>"><?= raw($ic('icon-trash')) ?> Löschen …</button>
    <?php endif; ?>
    <?php if ($actions === []): ?>
    <p class="be-menu__note">Für dieses Geschäftsjahr ist gerade keine Aktion möglich.</p>
    <?php endif; ?>
</div>
