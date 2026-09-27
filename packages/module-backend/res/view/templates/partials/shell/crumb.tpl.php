<?php
/**
 * The default crumb line (hc3, ADR-033): WHERE one is, derived from the
 * navigation — section › … › page. Renders on every screen so the crumb row
 * says something everywhere; a screen with its own `<action>.hc3.tpl.php`
 * (e.g. the Drive with its live breadcrumb pane) replaces it entirely.
 *
 * The trail comes from `BackendMenu::activeTrail()` — the same walk the
 * `<title>` uses, so tab and crumb always name the same screen. It uses the
 * UI cursor the subnav uses (NAV-SUBPAGE-001 sibling fallback included), so
 * those two agree as well. No cursor, no crumb — an empty line is honest, an
 * invented one is not.
 *
 * Walks the menu as the user may see it (BackendMenu, ADR-045): an entry the
 * user may not open is not linked, a hidden section yields no crumb.
 *
 * @var \Z77\Module\Backend\Ui\BackendMenu|null $backendMenu
 */
$nav   = $backendMenu ?? null;
$trail = $nav?->activeTrail() ?? [];
if ($trail === []) { return; }

$last = count($trail) - 1;
?>
<nav class="be-crumb" aria-label="Pfad">
    <?php foreach ($trail as $i => $entry): ?>
    <?php if ($i > 0): ?><span class="be-crumb__sep" aria-hidden="true">›</span><?php endif; ?>
    <?php if ($i === $last): ?>
    <span class="be-crumb__here"><?= e($entry->getName()) ?></span>
    <?php elseif ($i > 0 && ($url = $nav->href($entry)) !== ''): ?>
    <a href="<?= e($url) ?>"><?= e($entry->getName()) ?></a>
    <?php else: ?>
    <span><?= e($entry->getName()) ?></span>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
