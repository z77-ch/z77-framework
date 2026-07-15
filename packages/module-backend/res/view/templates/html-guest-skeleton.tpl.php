<!DOCTYPE html>
<?php
/** Guest skeleton — full-page GUEST screens (login, setup). No backend chrome:
 *  no topbar, subnav, preview column, header slots or footer. The page content
 *  (`.login`) centers itself in the self-contained `.be-guest` wrapper (see
 *  `components/_guest.scss`), independent of the authenticated shell + the
 *  responsive `layout/*.scss` files. Selected per controller via `documentTpl`
 *  in `Ui/Config/System/{login,setup}ControllerConfig.inc.php` (LAYOUT-B001).
 *
 *  The module `layoutConfig` still registers the chrome partials for every
 *  backend controller (config is append-only); they render to empty output for
 *  a GUEST (topbar self-skips on absent `headerUser`) and are simply not echoed
 *  here — the topbar's `if (empty($headerUser)) return;` guard stays as the
 *  data-presence gate. */
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
<body class="be-guest">
    <?= $iconSprite ?? '' ?>
    <main class="be-guest__main">
        <?= $main ?? '' ?>
    </main>
    <?= $flash ?? '' ?>
    <?= $messages ?? '' ?>
    <?= $jsFooter ?? '' ?>
</body>
</html>
