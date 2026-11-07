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
        <?php /* The guest's language switch (ADR-056): the module's `languages`, built by
                 the kernel (`$languageSwitch`, empty after sign-in where the account
                 decides). The query travels along — an invitation or a login link keeps
                 its token when the guest changes the language. */ ?>
        <?php if (!empty($languageSwitch)): ?>
        <nav class="me-lang" aria-label="<?= e(t('nav.aria.language')) ?>">
            <?php foreach ($languageSwitch as $lang): ?>
            <a href="<?= e($lang['url'] . ($memberQuery ?? '')) ?>" lang="<?= e($lang['code']) ?>"
               class="me-lang__link<?= $lang['active'] ? ' me-lang__link--active' : '' ?>"
               <?= $lang['active'] ? 'aria-current="true"' : '' ?>><?= e(t('lang.' . $lang['code'], [], $lang['code'])) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </header>
    <main class="me-main">
        <?= $main ?? '' ?>
    </main>
    <?= $jsFooter ?? '' ?>
</body>
</html>
