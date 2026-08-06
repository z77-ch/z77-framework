<?php
/**
 * Flash channel of the member pages — same contract as the frontend partial
 * (server decides IF and WHAT, CSS decides the look; error = assertive).
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
    </div>
    <?php endforeach; ?>
</div>
