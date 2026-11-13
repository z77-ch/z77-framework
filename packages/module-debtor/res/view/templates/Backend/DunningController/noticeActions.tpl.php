<?php
/**
 * A dunning notice row's ⋮ (core.js «context menu», MENU-001; owner 2026-11-13 — the booking
 * print result list's row menu, `InvoiceController/resultActions`): «PDF öffnen» and, with
 * module-mailing, «Per E-Mail senden …» — «Erneut senden …» once a mail of the notice waits or
 * went out. Both lead to the shared confirmation page.
 *
 * @var \Z77\Module\Debtor\Entities\DunningNotice $notice
 * @var string $actionBase
 * @var bool   $mailable
 * @var bool   $again
 */
$id = (int) $notice->getId();
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — Mahnung zu «<?= e($notice->getInvoice()->documentName()) ?>»">
    <a class="be-menu__item" role="menuitem" href="<?= e($actionBase . '/notice-pdf?id=' . $id) ?>" target="_blank" rel="noopener"><?= raw($ic('icon-file-text')) ?> PDF öffnen</a>
    <?php if ($mailable): ?>
    <a class="be-menu__item" role="menuitem" href="<?= e($actionBase . '/confirm-notice-mail?id=' . $id) ?>"><?= raw($ic('icon-mail')) ?> <?= $again ? 'Erneut senden …' : 'Per E-Mail senden …' ?></a>
    <?php endif; ?>
</div>
