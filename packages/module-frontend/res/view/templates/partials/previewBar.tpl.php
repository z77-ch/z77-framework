<?php
/**
 * Content preview notice (ADR-044 addendum "Variants"). Renders only while the
 * request carries ?preview=<key> — the same condition under which
 * PageContent::view() reads the variant set. It says that this is NOT the live
 * text and links the same page without the parameter.
 *
 * Wired as body level element `preview` (layoutConfig) into the skeleton slot
 * `$preview`. Markup and class names are the framework's; the look belongs to
 * the project (framework default in components/_preview-bar.scss, loaded with
 * the `messages` sheet) — same split as the flash messages.
 *
 * Editor chrome, not site content: German on every language, like the backend.
 */
use Z77\Shared\Content\ContentPreview;

$previewKey = ContentPreview::key();
if ($previewKey === null) {
    return;
}
$liveUrl = ContentPreview::withoutPreview((string)($_SERVER['REQUEST_URI'] ?? '/'));
?>
<aside class="preview-bar" role="status">
    <span class="preview-bar__label">Vorschau «<?= e($previewKey) ?>»</span>
    <a class="preview-bar__live" href="<?= e($liveUrl) ?>">Live ansehen</a>
</aside>
