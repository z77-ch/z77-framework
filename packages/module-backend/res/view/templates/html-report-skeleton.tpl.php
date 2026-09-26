<!DOCTYPE html>
<?php
/** Report skeleton — the statistics report behind its link (`/stats/report/{token}`,
 *  StatsController::reportAction, docs/topics/stats.md). A plain document for a
 *  client without a backend login: no shell, no backend CSS, no script, no csrf
 *  meta. The report template carries its own styles (also used inside the shell).
 *
 *  `noindex` twice on purpose — the action sends the header, this is the meta for
 *  whoever saves the page to disk. */
/** @var string $siteName */
?>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Besuchsstatistik<?= !empty($siteName) ? ' — ' . e($siteName) : '' ?></title>
    <style>
        body { margin: 0; background: #ffffff; }
        @media print { body { background: none; } }
    </style>
</head>
<body>
    <?= $main ?? '' ?>
</body>
</html>
