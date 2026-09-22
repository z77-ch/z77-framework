<?php
/** @var \Z77\Shared\Entities\MetaData|null $metaData */
/** @var array{canonical: string, alternates: list<array{hreflang: string, url: string}>} $seo */
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
