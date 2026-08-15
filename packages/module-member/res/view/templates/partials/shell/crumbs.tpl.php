<?php
/**
 * The breadcrumb — a POSITION, and only that (ADR-033 revision 2026-08-15:
 * until then a crumb could carry a level's switch; a switch is an OPERATION
 * and lives in the toolbar now, as a labelled tool — partials/shell/tools).
 *
 * The counts that used to sit in the rail moved under the detail's title, not
 * into this line: a breadcrumb one has to read instead of scan has stopped
 * being one, and this row grows with every long property name as it is.
 *
 * @var array<int,array{label:string, url?:string, here?:bool}> $crumbs
 */
?>
<nav class="me-crumb" aria-label="Pfad">
    <?php foreach ($crumbs as $i => $crumb): ?>
    <?php if ($i > 0): ?><span class="me-crumb__sep" aria-hidden="true">›</span><?php endif; ?>

    <?php if (!empty($crumb['url'])): ?>
    <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
    <?php else: ?>
    <span class="<?= !empty($crumb['here']) ? 'me-crumb__here' : '' ?>"><?= e($crumb['label']) ?></span>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
