<?php
/** @var \Z77\Shared\Entities\MetaData|null $metaData */
/** @var array{canonical: string, alternates: list<array{hreflang: string, url: string}>} $seo */
?>
<title><?= e($metaData?->getTitle() ?: 'Z77 Framework') ?></title>
<meta name="description" content="<?= e($metaData?->getDescription() ?: '') ?>">
<link rel="canonical" href="<?= e($seo['canonical']) ?>">
<?php foreach ($seo['alternates'] as $alt): ?>
<link rel="alternate" hreflang="<?= e($alt['hreflang']) ?>" href="<?= e($alt['url']) ?>">
<?php endforeach; ?>
