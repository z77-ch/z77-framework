<?php
/**
 * Link previews (Open Graph + Twitter card). Everything comes from the page or
 * the module config — no placeholder is printed (the starter used to ship
 * "Portfolio von Max Mustermann" / example.com on every page):
 *   - title / description: the page's SEO metadata (per language); the title
 *     falls back to `site.name`, a missing description leaves its tags out
 *   - url: the page's canonical, so a shared link previews the indexed address
 *   - site_name, locale, image (+ size, alt): module config `site`
 *     (see Z77\Core\Libraries\Seo\SiteIdentity); a missing entry leaves its tag out
 *
 * @var \Z77\Shared\Entities\MetaData|null $metaData
 * @var \Z77\Core\Libraries\Seo\SiteIdentity $site
 * @var array{canonical: string, alternates: list<array{hreflang: string, url: string}>} $seo
 */
$ogTitle       = $metaData?->getTitle() ?: $site->name;
$ogDescription = $metaData?->getDescription() ?: '';
?>
<!-- Open Graph -->
<meta property="og:type" content="website">
<?php if ($site->name !== ''): ?>
<meta property="og:site_name" content="<?= e($site->name) ?>">
<?php endif; ?>
<?php if ($site->locale !== ''): ?>
<meta property="og:locale" content="<?= e($site->locale) ?>">
<?php endif; ?>
<?php if ($ogTitle !== ''): ?>
<meta property="og:title" content="<?= e($ogTitle) ?>">
<?php endif; ?>
<?php if ($ogDescription !== ''): ?>
<meta property="og:description" content="<?= e($ogDescription) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= e($seo['canonical']) ?>">
<?php if ($site->imageUrl !== ''): ?>
<meta property="og:image" content="<?= e($site->imageUrl) ?>">
<?php if ($site->imageWidth > 0 && $site->imageHeight > 0): ?>
<meta property="og:image:width" content="<?= $site->imageWidth ?>">
<meta property="og:image:height" content="<?= $site->imageHeight ?>">
<?php endif; ?>
<?php if ($site->imageAlt !== ''): ?>
<meta property="og:image:alt" content="<?= e($site->imageAlt) ?>">
<?php endif; ?>
<?php endif; ?>

<!-- Twitter Cards -->
<meta name="twitter:card" content="<?= $site->imageUrl !== '' ? 'summary_large_image' : 'summary' ?>">
<?php if ($ogTitle !== ''): ?>
<meta name="twitter:title" content="<?= e($ogTitle) ?>">
<?php endif; ?>
<?php if ($ogDescription !== ''): ?>
<meta name="twitter:description" content="<?= e($ogDescription) ?>">
<?php endif; ?>
<?php if ($site->imageUrl !== ''): ?>
<meta name="twitter:image" content="<?= e($site->imageUrl) ?>">
<?php endif; ?>
