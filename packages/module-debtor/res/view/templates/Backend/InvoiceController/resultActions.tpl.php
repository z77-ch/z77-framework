<?php
/**
 * The booking print result list's row ⋮ (core.js «context menu», owner 2026-10-23: two row actions
 * are a menu): «PDF öffnen» and, once the document's mail is covered, «Erneut senden …».
 *
 * @var \Z77\Module\Debtor\Entities\Invoice $document
 * @var string $actionBase
 * @var string $query       `ids` (+ `back`) of the result list
 * @var bool   $resendable
 */
$id = (int) $document->getId();
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($document->documentName()) ?>»">
    <a class="be-menu__item" role="menuitem" href="<?= e($actionBase . '/pdf?id=' . $id) ?>" target="_blank" rel="noopener"><?= raw($ic('icon-file-text')) ?> PDF öffnen</a>
    <?php if ($resendable): ?>
    <a class="be-menu__item" role="menuitem" href="<?= e($actionBase . '/confirm-mail?' . $query . '&resend=' . $id) ?>"><?= raw($ic('icon-mail')) ?> Erneut senden …</a>
    <?php endif; ?>
</div>
