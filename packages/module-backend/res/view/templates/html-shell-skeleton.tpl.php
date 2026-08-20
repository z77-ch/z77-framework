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
    <?php /* ⚠️ Die Klasse sagt, DASS es eine Reiter-Zeile gibt. Auf dem Telefon rechnet
             sich der Navigations-Schub unter Band und Krume — mit Reitern eine Zeile
             tiefer, und `calc()` kann eine Zeile, die es nur manchmal gibt, nicht blind
             mitzaehlen. Derselbe Ausdruck wie unten, damit beide nie auseinanderlaufen. */ ?>
    <?php $hasTabs = trim((string)($tabs ?? '')) !== ''; ?>
    <div class="be-shell<?= $hasTabs ? ' be-shell--tabs' : '' ?>" data-shell data-z77-split-root>
        <?= $shellTopbar ?? '' ?>
        <?php /* Header-Band mit den Slots hc1 (über Spalte 1) + hc2 (über Spalte 2):
                 Controller/Action-Partials (Body-Sektionen `hc1`/`hc2`).

                 Das Band ist eine EIGENE Gitterzeile über beide Spalten, nicht je ein Kind seiner
                 Spalte. Es gehört zur Shell, nicht zum Bildschirm — und deshalb rendert es IMMER,
                 auch wenn beide Slots leer sind. (Vorher hing es an `$hasHead`: die vier
                 Bildschirme ohne Slots begannen 46px höher als alle anderen, ein Sprung beim
                 Wechseln.)

                 Warum eine eigene Zeile und nicht in den Spalten: hc1 trägt auf allen sieben
                 Bildschirmen, die ihn nutzen, die PRIMÄRE Aktion. Als Kind von Spalte 1 verschwand
                 die unter 767px mit der Spalte im Navigations-Drawer — hinter dem Burger, wo
                 niemand «Neu anlegen» sucht. Per CSS war das nicht zu retten: die Spalte trägt dort
                 ein `transform`, und ein transformierter Vorfahre ist auch für `position: fixed`
                 der Bezugsrahmen. */ ?>
        <div class="be-shell-band">
            <div class="be-shell-band__slot be-shell-band__slot--1"><?= $hc1 ?? '' ?></div>
            <div class="be-shell-band__slot be-shell-band__slot--2"><?= $hc2 ?? '' ?></div>
        </div>
        <?php /* Reiter-Zeile (Slot `tabs`, B10 v1.17.0): WELCHE Ansicht eines Gegenstands man
                 sieht — eine Ebene ueber hc2, das die Werkzeuge der gewaehlten Ansicht traegt.

                 ⚠️ Anders als Band und Krumen-Zeile rendert diese Zeile NICHT immer. Sie gehoert
                 zum Bildschirm, nicht zur Schale: ein Bildschirm ohne Reiter soll keinen Rahmen
                 fuer eine Zeile bezahlen, die er nicht braucht. Ohne Zelle faellt die `auto`-Spur
                 auf null zusammen; leer gerendert zoege ihr `border-bottom` einen Strich unter
                 jeden Backend-Bildschirm. Alle anderen Zellen nennen ihre Gitterzeile selbst,
                 darum verschiebt das nichts. */ ?>
        <?php /* trim(): eine Reiter-Vorlage, die sich selbst abschaltet, liefert Leerraum —
                 und Leerraum ist nicht empty(). Ungetrimmt entstuende eine leere Zeile mit Rahmen. */ ?>
        <?php if ($hasTabs): ?>
        <div class="be-shell-tabs">
            <div class="be-shell-tabs__slot be-shell-tabs__slot--1"></div>
            <div class="be-shell-tabs__slot be-shell-tabs__slot--2"><?= $tabs ?></div>
        </div>
        <?php endif; ?>
        <?php /* Crumb line (hc3, ADR-033): position and state, its own slim row —
                 renders ALWAYS for the same reason the band does (no height jump
                 between screens). A screen without an own hc3 template gets the
                 navigation-derived default; slot 1 is a bare cell capping the dark
                 island. */ ?>
        <div class="be-shell-crumb">
            <div class="be-shell-crumb__slot be-shell-crumb__slot--1"></div>
            <div class="be-shell-crumb__slot be-shell-crumb__slot--2"><?= $hc3 ?? $this->partial('partials/shell/crumb', [
                'navigationService' => $navigationService ?? null,
                'navSlot'           => $navSlot ?? 'backend-main',
            ]) ?></div>
        </div>
        <div class="be-shell-col be-shell-col--1" data-shell-col="l">
            <?= $subnav ?? '' ?>
        </div>
        <div class="be-shell-col be-shell-col--2">
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
