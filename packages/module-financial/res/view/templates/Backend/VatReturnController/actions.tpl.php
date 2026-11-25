<?php
/**
 * The ⋮ of a row of the returns list — a context menu (core.js «context menu», backend-screen.md
 * §4): «Aufheben …» on the latest posted return for who may lift (owner decision 2 of 2026-11-25,
 * the emergency brake — never in the action cell). It opens the window `confirm-lift`. Nothing
 * allowed → a muted line instead of items.
 *
 * @var \Z77\Module\Financial\Entities\VatReturn $return
 * @var string $label
 * @var bool   $canLift
 * @var string $base
 */
?>
<div class="be-menu" role="menu" aria-label="Aktionen — MWST-Abrechnung <?= e($label) ?>">
    <?php if ($canLift): ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="<?= e($base) ?>/confirm-lift?id=<?= (int) $return->getId() ?>"><svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-x"/></svg> Aufheben …</button>
    <?php else: ?>
    <p class="be-menu__note">Für diese MWST-Abrechnung ist gerade keine Aktion möglich.</p>
    <?php endif; ?>
</div>
