<?php
use Z77\Shared\Entities\Navigation;

/** @var \Z77\Core\Services\NavigationService $navigationService */
/** @var \Z77\Shared\Entities\Navigation|null $navigation */
/** @var string $navSlot */

$activeSectionEntry = $navigationService->getActiveSectionBySlot($navSlot);
$items = $activeSectionEntry ? $navigationService->getChildren($activeSectionEntry) : [];

if (empty($items)) return;

$sectionLabel = $activeSectionEntry->getName();

// Resolve an entry's href: ref → target URL + ?via=<refId>, otherwise its own
// URL (built from module/group/controller/action or the friendly url). Empty
// string when nothing is reachable — the caller renders such a node inert.
$hrefOf = function (Navigation $entry) use ($navigationService): string {
    if ($entry->getRef() !== null) {
        $target = $navigationService->findById($entry->getRef());
        return $target ? $navigationService->urlForVia($target, $entry->getId()) : '';
    }
    return $navigationService->urlFor($entry);
};

// True when the entry or any descendant is the current UI cursor — drives the
// open/trail state of opener nodes. Refs are leaves (never expanded).
$subtreeActive = function (Navigation $entry) use (&$subtreeActive, $navigationService): bool {
    if ($navigationService->isActive($entry)) return true;
    if ($entry->getRef() !== null) return false;
    foreach ($navigationService->getChildren($entry) as $child) {
        if ($subtreeActive($child)) return true;
    }
    return false;
};

// Recursive node renderer. Rule: an entry WITH children is an opener
// (<details>), regardless of whether it can produce a link of its own — to keep
// such a node's own page reachable, add a ref-to-self child. Leaves render as a
// link when they resolve to a URL, inert otherwise (no href="" → page reload).
$renderNode = function (Navigation $entry, int $depth) use (&$renderNode, $navigationService, $hrefOf, $subtreeActive): void {
    if ($depth > 20) return; // defensive guard against hand-edited parent cycles

    $isRef    = $entry->getRef() !== null;
    $children = $isRef ? [] : $navigationService->getChildren($entry);
    $name     = $entry->getName();

    if (!empty($children)) {
        $open = $subtreeActive($entry);
        ?>
        <details class="backend-tree-opener"<?= $open ? ' open' : '' ?>>
            <summary class="backend-tree-node backend-tree-node--opener backend-tree-node--has-children<?= $open ? ' backend-tree-node--has-active-child' : '' ?>">
                <span class="backend-tree-node__toggle" aria-hidden="true">
                    <svg class="be-icon" width="8" height="8" aria-hidden="true"><use href="#icon-chevron-right"/></svg>
                </span>
                <span class="backend-tree-node__label"><?= e($name) ?></span>
                <span class="backend-tree-node__count"><?= count($children) ?></span>
            </summary>
            <div class="backend-tree-children" style="padding-left:1rem">
                <?php foreach ($children as $child) { $renderNode($child, $depth + 1); } ?>
            </div>
        </details>
        <?php
        return;
    }

    $url = $hrefOf($entry);
    $cls = 'backend-tree-node'
        . ($navigationService->isActive($entry) ? ' backend-tree-node--active' : '')
        . ($isRef ? ' backend-tree-node--ref' : '');

    if ($url === ''):
        ?>
        <span class="<?= e($cls) ?> backend-tree-node--inert" aria-disabled="true">
            <span class="backend-tree-node__toggle" aria-hidden="true"></span>
            <span class="backend-tree-node__label"><?= e($name) ?></span>
        </span>
        <?php
    else:
        ?>
        <a href="<?= e($url) ?>" class="<?= e($cls) ?>">
            <span class="backend-tree-node__toggle" aria-hidden="true"></span>
            <span class="backend-tree-node__label"><?= e($name) ?></span>
        </a>
        <?php
    endif;
};
?>
<nav class="backend-subnav" aria-label="<?= e($sectionLabel) ?>">

    <div class="backend-subnav__header">
        <span class="backend-subnav__title"><?= e($sectionLabel) ?></span>
    </div>

    <div class="backend-subnav__tree">
        <?php foreach ($items as $item) { $renderNode($item, 0); } ?>
    </div>

</nav>
