<?php
/**
 * The outbox row's ⋮ context menu (core.js «context menu», MENU-001):
 * «Anzeigen» opens the mail window; «Erneut senden» for a failed mail and
 * «Abbrechen» for a waiting one are small data-fetch-post forms (the session
 * CSRF rides in the header) whose answer reloads the list region. A mail being
 * sent, a sent or a cancelled one offers only «Anzeigen».
 *
 * @var \Z77\Module\Mailing\Entities\OutboxMail $mail
 * @var string $actionBase
 */
$ic     = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$id     = (string) $mail->getId();
$detail = $actionBase . '/detail?id=' . $id;
?>
<div class="be-menu" role="menu" aria-label="Aktionen — E-Mail an «<?= e($mail->getRecipientEmail()) ?>»">
    <a class="be-menu__item" role="menuitem" href="<?= e($detail) ?>" data-window-open="<?= e($detail) ?>"><?= raw($ic('icon-eye')) ?> Anzeigen</a>
    <?php if ($mail->getState() === \Z77\Module\Mailing\Entities\OutboxMail::STATE_FAILED): ?>
    <form class="be-menu__form" role="none" data-fetch-post="<?= e($actionBase) ?>/retry">
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <button type="submit" class="be-menu__item" role="menuitem"><?= raw($ic('icon-play')) ?> Erneut senden</button>
    </form>
    <?php endif; ?>
    <?php if ($mail->getState() === \Z77\Module\Mailing\Entities\OutboxMail::STATE_QUEUED): ?>
    <form class="be-menu__form" role="none" data-fetch-post="<?= e($actionBase) ?>/cancel">
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <button type="submit" class="be-menu__item be-menu__item--danger" role="menuitem"><?= raw($ic('icon-x')) ?> Abbrechen</button>
    </form>
    <?php endif; ?>
</div>
