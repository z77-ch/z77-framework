<?php
// Default Bootstrap Config
return [
    'cacheDebug' => false,
    'htmlRoot' => 'public',
    'cacheDir' => 'lib/cache/',
    'apcuCachePrefix' => 'z77',
    'overrideDir' => 'override',
    'moduleDir' => 'module',
    'assetDir' => 'assets',
    'tplDir' => 'res/view/templates',
    'timeZone' => 'Europe/Zurich',

    // Absolute origin of this installation, e.g. 'https://kunde.ch' — the source for
    // every link generated to leave the request (mail links above all). Empty falls
    // back to the request's Host header, which the CLIENT controls: under a catch-all
    // vhost a forged Host puts an attacker's domain into a genuine mail and hands over
    // the one-time token it carries (SEC-005). Set it per project in composer.json
    // under extra.core-bootstrap — config/bootstrap.inc.php is regenerated on every
    // install, so a value written there directly would be lost.
    'canonicalBaseUrl' => '',
];
