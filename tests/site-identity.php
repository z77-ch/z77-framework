<?php

/**
 * SiteIdentity harness (CLI) — the module config block `site` for the document
 * head (author, og:site_name / <title> fallback, og:locale, preview image).
 *
 * What is load-bearing: a missing entry yields '' / 0 (the partials then leave
 * the tag out — no placeholder is ever printed), per-language values never
 * fall back to another language, and only a site-relative image path gets the
 * configured origin ('//host' is not site-relative).
 *
 * Run: php tests/site-identity.php
 */

require __DIR__ . '/../packages/kernel/core/src/Libraries/Seo/SiteIdentity.php';

use Z77\Core\Libraries\Seo\SiteIdentity;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$origin = 'https://kunde.ch';
$config = [
    'name'    => ' Zihl & See ',
    'author'  => 'sihlestate AG',
    'locales' => ['de' => 'de_CH', 'fr' => 'fr_CH'],
    'image'   => ['path' => '/assets/og.jpg', 'width' => 1200, 'height' => 630, 'alt' => ['de' => 'Luftbild', 'fr' => 'Vue aérienne']],
];

echo "Configured\n";
$de = SiteIdentity::fromConfig($config, 'de', $origin);
check('name trimmed', $de->name === 'Zihl & See', $de->name);
check('author', $de->author === 'sihlestate AG');
check('locale de', $de->locale === 'de_CH', $de->locale);
check('image made absolute', $de->imageUrl === 'https://kunde.ch/assets/og.jpg', $de->imageUrl);
check('image size', $de->imageWidth === 1200 && $de->imageHeight === 630);
check('alt de', $de->imageAlt === 'Luftbild');
$fr = SiteIdentity::fromConfig($config, 'fr', $origin);
check('locale fr', $fr->locale === 'fr_CH');
check('alt fr', $fr->imageAlt === 'Vue aérienne');
$en = SiteIdentity::fromConfig($config, 'en', $origin);
check('unmapped language: no locale, no alt (no fallback)', $en->locale === '' && $en->imageAlt === '');

echo "Image paths\n";
check('absolute URL kept', SiteIdentity::fromConfig(['image' => ['path' => 'https://cdn.x/og.jpg']], 'de', $origin)->imageUrl === 'https://cdn.x/og.jpg');
check('//host is not site-relative', SiteIdentity::fromConfig(['image' => ['path' => '//evil.x/og.jpg']], 'de', $origin)->imageUrl === '//evil.x/og.jpg');
check('single alt string for every language', SiteIdentity::fromConfig(['image' => ['path' => '/a.jpg', 'alt' => 'Bild']], 'fr', $origin)->imageAlt === 'Bild');

echo "Absent\n";
$none = SiteIdentity::empty();
check('empty(): nothing set', $none->name === '' && $none->author === '' && $none->locale === '' && $none->imageUrl === '' && $none->imageWidth === 0);
$partial = SiteIdentity::fromConfig(['name' => 'X'], 'de', $origin);
check('partial block: only name', $partial->name === 'X' && $partial->author === '' && $partial->imageUrl === '');
check('non-scalar values ignored', SiteIdentity::fromConfig(['name' => ['x'], 'author' => null], 'de', $origin)->name === '');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
