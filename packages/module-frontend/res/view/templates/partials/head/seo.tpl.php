<?php
/** @var \Z77\Shared\Entities\MetaData|null $metaData */
/** @var \Z77\Core\Libraries\Seo\SeoLinks $seo  canonical/hreflang, built on first read (reads as an array) */
/** @var \Z77\Core\Libraries\Seo\SiteIdentity $site */
// Title: the page's SEO title, else the site name (module config `site.name`).
// No framework name as fallback — it used to show "Z77 Framework" in the tab of
// every page without metadata (e.g. a PRG target with no navigation entry).
?>
<title><?= e($metaData?->getTitle() ?: $site->name) ?></title>
<meta name="description" content="<?= e($metaData?->getDescription() ?: '') ?>">
<link rel="canonical" href="<?= e($seo['canonical']) ?>">
<?php foreach ($seo['alternates'] as $alt): ?>
<link rel="alternate" hreflang="<?= e($alt['hreflang']) ?>" href="<?= e($alt['url']) ?>">
<?php endforeach; ?>
