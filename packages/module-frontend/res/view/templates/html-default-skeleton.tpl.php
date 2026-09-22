<!DOCTYPE html>
<html lang="<?= e($language ?? 'de') ?>" class="fe">
<head>
    <?= $head ?? '' ?>
    <?= $css ?? '' ?>
    <?= $jsHead ?? '' ?>
</head>
<body>
    <?= $header ?? '' ?>
    <main>
        <?= $main ?? '' ?>
    </main>
    <?= $footer ?? '' ?>
    <?= $flash ?? '' ?>
    <?= $messages ?? '' ?>
    <?php /* Content preview notice (ADR-044 addendum) — a project maps a partial to body level 'preview'. */ ?>
    <?= $preview ?? '' ?>
    <?= $adminOverlay ?? '' ?>
    <?= $jsFooter ?? '' ?>
</body>
</html>
