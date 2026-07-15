<!DOCTYPE html>
<?php
/** Shell skeleton (3-column rebuild, Phase 1). Selected via layoutConfig `documentTpl`.
 *  Grid: topbar (spanning) + column 1 (orientation/subnav) | column 2 (main) | column 3
 *  (preview, optional). Columns 1 + 3 are drag-resizable; on mobile column 1 becomes a
 *  sandwich drawer and column 3 a right drawer — all driven by shell.js.
 *  The legacy `html-default-skeleton.tpl.php` is kept intact for one-line revert. */
/** @var string $bePalette */
/** @var string $beTheme */
/** @var float  $beFontScale */
?>
<html lang="de" class="be" data-be-palette="<?= e($bePalette) ?>" data-be-theme="<?= e($beTheme) ?>" style="--be-font-scale: <?= e($beFontScale) ?>">
<head>
    <?= $head ?? '' ?>
    <?= $css ?? '' ?>
    <?= $jsHead ?? '' ?>
</head>
<body class="backend">
    <?= $iconSprite ?? '' ?>
    <?= $noindexBanner ?? '' ?>
    <div class="be-shell" data-col3="off" data-shell>
        <?= $shellTopbar ?? '' ?>
        <?php /* Header-Slots hc1 (über Spalte 1) + hc2 (über Spalte 2): Controller/Action-Partials
                 (Body-Sektionen `hc1`/`hc2`). Gleich hoch (`.be-shell-col__head`); ist EINE gesetzt,
                 wird die andere leer mitgerendert, damit das Header-Band über beide Spalten ausgerichtet bleibt. */ ?>
        <?php $hasHead = !empty($hc1) || !empty($hc2); ?>
        <div class="be-shell-col be-shell-col--1" data-shell-col="l">
            <?php if ($hasHead): ?><div class="be-shell-col__head be-shell-col__head--sticky"><?= $hc1 ?? '' ?></div><?php endif; ?>
            <?= $subnav ?? '' ?>
        </div>
        <div class="be-shell-col be-shell-col--2">
            <?php if ($hasHead): ?><div class="be-shell-col__head be-shell-col__head--sticky"><?= $hc2 ?? '' ?></div><?php endif; ?>
            <?= $main ?? '' ?>
        </div>
        <div class="be-shell-col be-shell-col--3" data-shell-col="r"><?= $preview ?? '' ?></div>
        <div class="be-shell__resizer be-shell__resizer--l" data-shell-resize="l" title="Breite ziehen"></div>
        <div class="be-shell__resizer be-shell__resizer--r" data-shell-resize="r" title="Breite ziehen"></div>
        <div class="be-shell__backdrop" data-shell-backdrop></div>
    </div>
    <?= $flash ?? '' ?>
    <?= $messages ?? '' ?>
    <dialog id="z77-popup" class="be-modal" data-z77-popup>
        <div class="be-modal__inner">
            <button type="button" class="be-modal__fullscreen" data-popup-fullscreen aria-label="Vollbild umschalten" title="Vollbild">
                <svg class="be-icon be-modal__fs-icon be-modal__fs-icon--expand" width="15" height="15" aria-hidden="true"><use href="#icon-maximize"/></svg>
                <svg class="be-icon be-modal__fs-icon be-modal__fs-icon--compress" width="15" height="15" aria-hidden="true"><use href="#icon-minimize"/></svg>
            </button>
            <div class="z77-popup__body" data-z77-popup-body></div>
        </div>
    </dialog>
    <?= $jsFooter ?? '' ?>
</body>
</html>
