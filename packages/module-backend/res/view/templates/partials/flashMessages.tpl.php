<?php
/** @var array<array{type:string,text:string}> $_flashes */
$_flashes = $_flashes ?? [];
?>
<div id="flash-messages" class="flash-messages" role="status" aria-live="polite" aria-atomic="false">
    <?php foreach ($_flashes as $f): ?>
    <div class="flash-msg flash-msg--<?= e($f['type']) ?>">
        <span class="flash-msg__text"><?= e($f['text']) ?></span>
        <button type="button" class="flash-msg__close" aria-label="Schliessen">×</button>
    </div>
    <?php endforeach; ?>
</div>
