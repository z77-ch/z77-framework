<?php
use Z77\Shared\Entities\Navigation;

/**
 * The default crumb line (hc3, ADR-033): WHERE one is, derived from the
 * navigation — section › … › page. Renders on every screen so the crumb row
 * says something everywhere; a screen with its own `<action>.hc3.tpl.php`
 * (e.g. the Drive with its live breadcrumb pane) replaces it entirely.
 *
 * Uses the same UI cursor the subnav uses (NAV-SUBPAGE-001 sibling fallback
 * included), so the two always agree about where one stands. No cursor, no
 * crumb — an empty line is honest, an invented one is not.
 *
 * @var \Z77\Core\Services\NavigationService|null $navigationService
 * @var string $navSlot
 */
$nav  = $navigationService ?? null;
$slot = $navSlot ?? 'backend-main';
if ($nav === null) { return; }

$section = $nav->getActiveSectionBySlot($slot);
if ($section === null) { return; }

// True when the entry or any descendant carries the UI cursor (refs are leaves).
$subtreeActive = function (Navigation $entry) use (&$subtreeActive, $nav): bool {
    if ($nav->isActive($entry)) { return true; }
    if ($entry->getRef() !== null) { return false; }
    foreach ($nav->getChildren($entry) as $child) {
        if ($subtreeActive($child)) { return true; }
    }
    return false;
};

// The trail: from the section down along the active branch to the cursor.
$trail = [$section];
$node  = $section;
for ($depth = 0; $depth < 20; $depth++) {
    $next = null;
    foreach ($nav->getChildren($node) as $child) {
        if ($subtreeActive($child)) { $next = $child; break; }
    }
    if ($next === null) { break; }
    $trail[] = $next;
    $node    = $next;
    if ($nav->isActive($next) || $next->getRef() !== null) { break; }
}

$last = count($trail) - 1;
?>
<nav class="be-crumb" aria-label="Pfad">
    <?php foreach ($trail as $i => $entry): ?>
    <?php if ($i > 0): ?><span class="be-crumb__sep" aria-hidden="true">›</span><?php endif; ?>
    <?php if ($i === $last): ?>
    <span class="be-crumb__here"><?= e($entry->getName()) ?></span>
    <?php elseif ($i > 0 && ($url = $nav->urlFor($entry)) !== ''): ?>
    <a href="<?= e($url) ?>"><?= e($entry->getName()) ?></a>
    <?php else: ?>
    <span><?= e($entry->getName()) ?></span>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
