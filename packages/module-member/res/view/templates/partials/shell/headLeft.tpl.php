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
 * ⚠️ `$areas` empty means NO switcher, and that is for a page with no chrome
 * at all (the widget preview). A page that merely repeats the areas elsewhere
 * keeps it: the switcher is derived from the nav slot and can never miss an
 * area, while any hand-written list drifts the moment a new area appears.
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
            <?php /* Dasselbe Zeichen wie im Backend (Lucide grid, ADR-033
                     spricht von EINER Geste an beiden Orten): dort kommt es
                     aus dem Icon-Sprite, hier steht die Geometrie inline —
                     der Member-Bereich fuehrt kein Sprite, und ein
                     `<use href>` auf eine Datei, die es nicht gibt, zeichnet
                     nichts. Darstellung wie `.be-icon`: Kontur in der
                     Textfarbe, gefuellt wird nichts. */ ?>
            <svg class="me-icon" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
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
