<!-- Character Encoding & Viewport -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php /* Site-wide crawl block (SEO_NOINDEX flag, see bootstrap.md / metadata.md SEO-NOINDEX-001),
         and every content preview (?preview=, ADR-044 addendum) — variant text is never indexed. */ ?>
<?php if (SEO_NOINDEX || \Z77\Shared\Content\ContentPreview::key() !== null): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>

<?php
/**
 * @var \Z77\Shared\Entities\MetaData|null $metaData
 * @var \Z77\Core\Libraries\Seo\SiteIdentity $site   module config `site` (author: 'site.author')
 */
?>
<!-- Author & Theme Color -->
<?php if ($site->author !== ''): ?>
<meta name="author" content="<?= e($site->author) ?>">
<?php endif; ?>
<meta name="theme-color" content="<?= e($metaData?->getThemeColor() ?: '#ffffff') ?>">
