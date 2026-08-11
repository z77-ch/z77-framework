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
    <?php /* Brand mark for EVERY member page (login, register, resend, profile, …) — it sits
             in the skeleton, not in the page templates, so a new member screen inherits it
             without touching anything. Content comes from the shared partial; a project
             overrides that one file. */ ?>
    <header class="me-header">
        <?= $this->partial('partials/brandMark', ['class' => 'me-brand'], 'Z77\\Shared') ?>
    </header>
    <main class="me-main">
        <?= $main ?? '' ?>
    </main>
    <?= $jsFooter ?? '' ?>
</body>
</html>
