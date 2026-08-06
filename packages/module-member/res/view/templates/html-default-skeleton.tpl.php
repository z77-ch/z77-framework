<!DOCTYPE html>
<html lang="<?= e($language ?? 'de') ?>" class="me">
<head>
    <?= $head ?? '' ?>
    <title><?= e($pageTitle ?? 'Member') ?></title>
    <?= $css ?? '' ?>
    <?= $jsHead ?? '' ?>
</head>
<body class="me-body">
    <?= $flash ?? '' ?>
    <main class="me-main">
        <?= $main ?? '' ?>
    </main>
    <?= $jsFooter ?? '' ?>
</body>
</html>
