<!DOCTYPE html>
<?php
/** Bare skeleton — a backend page WITHOUT chrome, made to be shown inside an
 *  iframe (the page editor on the website, ADR-045 §4: ContentController::slotAction).
 *  No topbar, no menu, no header band; backend CSS, the icon sprite, the csrf meta
 *  tag (head partial) and core.js stay, so `data-fetch-post` forms work as anywhere
 *  in the backend.
 *
 *  Selected by the ACTION (`LayoutManager::setSkeletonTemplate('html-bare-skeleton')`),
 *  not by a controller config: the other actions of the same controller keep the shell.
 *  The action also takes the chrome sections and shell scripts off; this template
 *  simply does not echo them.
 *
 *  The content column is the popup channel's root ([data-z77-popup] /
 *  [data-z77-popup-body], core.js): an HTML answer to a fetch — a form re-rendered
 *  after a failed save — replaces the page body in place, the way it would replace a
 *  modal's body in the shell. A <div> root has no showModal(), so nothing opens.
 *  Look: `.be-bare*` in content/editor.css (its only user today). */
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
<body class="be-bare">
    <?= $iconSprite ?? '' ?>
    <main class="be-bare__main" data-z77-popup>
        <div class="be-bare__body" data-z77-popup-body><?= $main ?? '' ?></div>
    </main>
    <?= $flash ?? '' ?>
    <?= $messages ?? '' ?>
    <?= $jsFooter ?? '' ?>
</body>
</html>
