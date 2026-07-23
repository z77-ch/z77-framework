<?php
/**
 * Flash channel — the server decides IF and WHAT (controller → MessageService),
 * the stylesheet decides how it looks and how long it stays.
 *
 * An error interrupts, everything else waits its turn: a failed submit is the
 * one case where the visitor must not read on unaware, so the region switches
 * to assertive. Screen readers announce a polite region only at the next pause,
 * which is right for "saved" and wrong for "that did not work".
 *
 * @var array<array{type:string,text:string}> $_flashes
 */
$_flashes = $_flashes ?? [];

$hasError = false;
foreach ($_flashes as $f) {
    if (($f['type'] ?? '') === 'error') {
        $hasError = true;
        break;
    }
}
?>
<div id="flash-messages" class="flash-messages" role="<?= $hasError ? 'alert' : 'status' ?>"
     aria-live="<?= $hasError ? 'assertive' : 'polite' ?>" aria-atomic="false">
    <?php foreach ($_flashes as $f): ?>
    <div class="flash-msg flash-msg--<?= e($f['type']) ?>">
        <span class="flash-msg__text"><?= e($f['text']) ?></span>
        <button type="button" class="flash-msg__close" aria-label="<?= e(t('common.close')) ?>">×</button>
    </div>
    <?php endforeach; ?>
</div>
