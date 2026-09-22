<!-- Character Encoding & Viewport -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php /* Site-wide crawl block (SEO_NOINDEX flag, see bootstrap.md / metadata.md SEO-NOINDEX-001),
         and every content preview (?preview=, ADR-044 addendum) — variant text is never indexed. */ ?>
<?php if (SEO_NOINDEX || \Z77\Shared\Content\ContentPreview::key() !== null): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>

<?php /** @var \Z77\Shared\Entities\MetaData|null $metaData */ ?>
<!-- Author & Theme Color -->
<meta name="author" content="Max Mustermann">
<meta name="theme-color" content="<?= e($metaData?->getThemeColor() ?: '#ffffff') ?>">
