<?php
/**
 * Lottie animation — a thin, reusable wrapper around the <lottie-figure> web component
 * (res/assets/js/lottie-figure.js). The two scripts (player `lottie` + component
 * `lottie-figure`) are NOT loaded by default — the player weighs ~300 KB; a project that uses
 * Lottie lists both in its layoutConfig `javascripts` (see docs/topics/lottie.md). Renders
 * NOTHING when $src is empty (e.g. an unresolved DMS document), so a caller can pass
 * mediaUrl(...) straight through and handle the empty case itself.
 *
 * @var string  $src       Lottie JSON URL (required; '' → renders nothing)
 * @var ?string $ratio     CSS aspect-ratio ("1/1", "949/650"); default "1/1" (avoids layout shift)
 * @var ?string $poster    static fallback image URL (shown until ready / kept on reduced-motion)
 * @var ?bool   $loop       endless loop (default true)
 * @var ?int    $maxLoops   stop after N loops (optional)
 * @var ?string $trigger    'hover' → play on hover (touch falls back to autoplay); null → autoplay
 * @var ?string $class      extra class(es) on the element (optional, for layout targeting)
 * @var ?array  $hideLayers top-level Lottie layer names to drop before rendering (optional)
 */
$src = $src ?? '';
if ($src === '') {
    return;
}
$ratio    = $ratio ?? '1/1';
$loop     = ($loop ?? true) ? 'loop' : '';
$maxLoops = $maxLoops ?? null;
$trigger  = $trigger ?? '';
$class    = $class ?? '';
$hide     = implode('|', $hideLayers ?? []);
// The poster renders as a child <img> in the LIGHT DOM: with width/height attributes (the
// ratio's terms — only their quotient matters) it reserves the box at HTML parse time,
// before any JS runs, and stays visible without JS. The component overlays the animation
// and cross-fades once ready.
$ratioParts = preg_split('#\s*/\s*#', $ratio) ?: [];
$ratioW     = max(1, (int) ($ratioParts[0] ?? 1));
$ratioH     = max(1, (int) ($ratioParts[1] ?? 1));
?>
<lottie-figure
    <?php if ($class !== ''): ?>class="<?= e($class) ?>" <?php endif; ?>src="<?= e($src) ?>"
    ratio="<?= e($ratio) ?>"
    <?php if ($maxLoops !== null): ?>max-loops="<?= e((string) $maxLoops) ?>" <?php endif; ?><?php if ($trigger !== ''): ?>trigger="<?= e($trigger) ?>" <?php endif; ?><?php if ($hide !== ''): ?>hide-layers="<?= e($hide) ?>" <?php endif; ?><?= $loop ?>>
    <?php if (!empty($poster)): ?>
    <img src="<?= e($poster) ?>" alt="" width="<?= $ratioW ?>" height="<?= $ratioH ?>" loading="lazy" decoding="async">
    <?php endif; ?>
</lottie-figure>
