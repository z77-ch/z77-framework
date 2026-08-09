<!DOCTYPE html>
<?php
/** Shell skeleton. Selected via layoutConfig `documentTpl`.
 *  Grid: topbar (spanning) + column 1 (orientation/subnav) | column 2 (main).
 *
 *  Column 3 was REMOVED 2026-08-08. It had never been in use: `data-col3` was hard-coded to
 *  "off", no `*.hc3.tpl.php` ever existed, and its mobile right-drawer had no trigger
 *  anywhere in the repo. Everything inside the content area — including a detail pane — is
 *  owned by the workspace (`.z77-split`) from now on, so there is exactly one way to put
 *  detail beside a list instead of two.
 *
 *  Column 1 stays drag-resizable, now through the SHARED `z77-split` handle contract
 *  (kernel/shared `split.js`) instead of shell.js's own hard-wired block. `data-z77-split-dir`
 *  is explicit here because the resizer is a grid overlay, not a flex sibling of the column.
 *  On mobile column 1 becomes a sandwich drawer (still shell.js).
 *
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
    <?= $systemBanner ?? '' ?>
    <?= $noindexBanner ?? '' ?>
    <div class="be-shell" data-shell data-z77-split-root>
        <?= $shellTopbar ?? '' ?>
        <?php /* Header-Slots hc1 (über Spalte 1) + hc2 (über Spalte 2): Controller/Action-Partials
                 (Body-Sektionen `hc1`/`hc2`). Das Band ist eine Eigenschaft der SHELL, nicht des
                 Bildschirms — es rendert IMMER, auch wenn beide Slots leer sind. Vorher hing es an
                 `$hasHead = !empty($hc1) || !empty($hc2)`: die vier Bildschirme ohne Slots (backup,
                 job, import, member-accounts) bekamen gar kein Band, ihr Inhalt begann also 46px
                 höher als überall sonst — ein Sprung beim Wechseln zwischen Bildschirmen. Beide
                 Slots teilen `.be-shell-col__head` (feste Höhe 46px), damit das Band über die
                 Spalten hinweg auf einer Linie bleibt. */ ?>
        <div class="be-shell-col be-shell-col--1" data-shell-col="l">
            <div class="be-shell-col__head be-shell-col__head--sticky"><?= $hc1 ?? '' ?></div>
            <?= $subnav ?? '' ?>
        </div>
        <div class="be-shell-col be-shell-col--2">
            <div class="be-shell-col__head be-shell-col__head--sticky"><?= $hc2 ?? '' ?></div>
            <?= $main ?? '' ?>
        </div>
        <div class="be-shell__resizer z77-split__handle" title="Breite ziehen"
             data-z77-split="--shell-c1" data-z77-split-min="190" data-z77-split-max="460"
             data-z77-split-dir="1"></div>
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
