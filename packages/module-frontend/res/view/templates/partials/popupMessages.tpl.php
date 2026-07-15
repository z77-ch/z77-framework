<?php
/** @var array<array{type:string,text:string}> $_messages */
$_messages = $_messages ?? [];
?>
<div id="messages" class="msg-popups" role="status" aria-live="polite" aria-atomic="false">
    <?php foreach ($_messages as $m): ?>
    <div class="msg-popup msg-popup--<?= e($m['type']) ?>">
        <span class="msg-popup__text"><?= e($m['text']) ?></span>
        <button type="button" class="msg-popup__close" aria-label="<?= e(t('common.close')) ?>">×</button>
    </div>
    <?php endforeach; ?>
</div>
