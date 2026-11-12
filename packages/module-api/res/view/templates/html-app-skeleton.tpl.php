<!DOCTYPE html>
<?php
/** App shell skeleton — `/app` (plan A6 / T6, AppController). A phone page under an ENFORCED CSP:
 *  no inline script, no `style=` attribute, nothing from another origin. Holds the bar (title and
 *  device, filled by `_Z77.app.shell` with textContent) and the shell's icon sprite; the page's
 *  content is `$main`.
 *
 *  The icons of the installation itself (`/web-app-manifest-*.png`, `/apple-touch-icon.png`,
 *  `/favicon.ico`) are the files every z77 project serves from public/. */
/** @var string $page start|pair */
?>
<html lang="de" class="za">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#211d45">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="z77 App">
    <title>z77 App</title>
    <link rel="manifest" href="/app/manifest.webmanifest">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <?= $css ?? '' ?>
    <?= $jsHead ?? '' ?>
</head>
<body class="za-body" data-app-page="<?= e($page ?? '') ?>">
    <svg class="za-sprite" aria-hidden="true" focusable="false">
        <symbol id="za-i-offline" viewBox="0 0 24 24"><path d="M2 8.5a15 15 0 0 1 20 0M5.5 12a10 10 0 0 1 13 0M9 15.5a5 5 0 0 1 6 0M12 19h.01M3 3l18 18"/></symbol>
        <symbol id="za-i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="za-i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
        <symbol id="za-i-back" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
        <symbol id="za-i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16.5h.01"/></symbol>
        <symbol id="za-i-camera" viewBox="0 0 24 24"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></symbol>
    </svg>
    <header class="za-bar">
        <span class="za-bar-title" data-app-title>z77 App</span>
        <small class="za-bar-device" data-app-device></small>
    </header>
    <main class="za-main">
        <?= $main ?? '' ?>
    </main>
    <?= $jsFooter ?? '' ?>
</body>
</html>
