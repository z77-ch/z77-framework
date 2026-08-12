<?php
/**
 * Header, left cell — the name of the area one is standing in, and the switcher
 * that leaves it.
 *
 * The name is a LABEL, not a trigger: switching happens at exactly one place,
 * the four-square button (B10 v1.4.1, decision 5). Two triggers for one panel
 * would need two anchors and open in two directions.
 *
 * The button sits flush with the rail edge — the cell ends where the seam
 * begins, so `margin-left: auto` puts it exactly on that line.
 *
 * ⚠️ On the dashboard the rail itself carries the areas, so the switcher is
 * redundant there and the controller omits `$areas`. That is the rule: in an
 * area the rail carries DATA and the switcher navigates; on the dashboard the
 * rail carries the NAVIGATION and the switcher is not needed.
 *
 * @var string $areaName
 * @var array<int,array{name:string,url:string,active:bool}> $areas  empty = no switcher
 */
?>
<div class="me-shell__head-l">
    <span class="me-shell__area"><?= e($areaName) ?></span>

    <?php if (!empty($areas)): ?>
    <div class="me-switcher" data-member-areas>
        <button type="button" class="me-switcher__btn" data-member-areas-trigger
                aria-haspopup="true" aria-expanded="false" aria-controls="me-area-panel"
                title="Bereich wechseln">
            <span class="me-switcher__grid" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <span class="me-account__sr">Bereich wechseln</span>
        </button>

        <?php /* Anchored to the right of the switcher, i.e. flush with the seam:
                 the panel then hangs OVER the rail it belongs to and cannot
                 leave the screen — the same anchor the account panel uses at
                 the other edge, for the same reason. */ ?>
        <div class="me-account__panel" id="me-area-panel" hidden
             data-member-areas-panel aria-label="Bereiche">
            <?php foreach ($areas as $area): ?>
            <a class="me-account__row<?= $area['active'] ? ' me-account__row--active' : '' ?>"
               href="<?= e($area['url']) ?>"<?= $area['active'] ? ' aria-current="page"' : '' ?>><?= e($area['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
