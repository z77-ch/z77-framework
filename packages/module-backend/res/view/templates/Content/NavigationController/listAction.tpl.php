<?php
/** @var array<int, array{key: string, label: string, slots: array<int, array{slug: string, label: string, entries: array<int, array<string,mixed>>}>, total: int}> $areas */
/** @var array<int, array<string,mixed>> $ungrouped */

// Pure renderer over the node display model built in NavigationController::nodeTree()
// (name / urlDisplay / route / ref / active / children). No logic, no service calls,
// no escaping decisions: `urlDisplay` is pre-escaped HTML (raw), the rest is text (e()).
$renderNode = function(array $node, int $depth = 0) use (&$renderNode): void {
    $hasChildren = $node['hasChildren'];
    $isRef       = $node['isRef'];
    $active      = $node['active'];
    $nodeId      = $node['id'];
    ?>
    <div class="be-tree__node<?= $hasChildren ? ' be-tree__node--has-children' : '' ?><?= $isRef ? ' be-tree__node--ref' : '' ?><?= $active ? '' : ' be-tree__node--inactive' ?>"
         style="--node-depth:<?= $depth ?>"
         data-nav-id="<?= e($nodeId) ?>"
         data-nav-active="<?= $active ? '1' : '0' ?>">
        <div class="be-tree__row">
            <span class="be-tree__toggle" aria-hidden="true">
                <?php if ($hasChildren): ?>
                <svg class="be-icon" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-right"/></svg>
                <?php elseif ($isRef): ?>
                <svg class="be-icon" width="10" height="10" aria-label="Verweis"><use href="#icon-arrow-up-right"/></svg>
                <?php endif; ?>
            </span>
            <?php if ($nodeId): ?>
            <label class="be-switch be-switch--sm be-tree__switch" title="Aktiv schalten">
                <input type="checkbox" class="be-switch__input"
                       data-fetch-toggle="/backend/content/navigation/toggle-active?id=<?= e($nodeId) ?>"<?= $active ? ' checked' : '' ?>>
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
            </label>
            <button type="button" class="be-tree__menu" title="Aktionen"
                    data-fetch-get="/backend/content/navigation/actions?id=<?= e($nodeId) ?>">⋮</button>
            <?php endif; ?>
            <span class="be-tree__name" data-field="name"><?= e($node['name']) ?></span>
            <span class="be-tree__url" data-field="url_display"><?= raw($node['urlDisplay']) ?></span>
            <span class="be-tree__route" data-field="route"><?= e($node['route']) ?></span>
        </div>
        <?php if ($hasChildren): ?>
        <div class="be-tree__children">
            <?php foreach ($node['children'] as $child): $renderNode($child, $depth + 1); endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
};
?>

<?php /* Content-header (breadcrumb/title + add + filter/print/aliases) moved to the shell header
         band: Content/NavigationController/list.hc1.tpl.php + list.hc2.tpl.php (auto-loaded). The
         view-area tabs stay in the body — they filter the list below. Render-slots + view areas
         are config (ADR-022): the list mirrors the config, there is no group CRUD here. */ ?>
<div class="be-tabs" role="tablist" style="padding:14px 32px 0">
    <button class="be-tabs__tab be-tabs__tab--active" data-group="*" role="tab" aria-selected="true">Alle</button>
    <?php foreach ($areas as $area): ?>
    <button class="be-tabs__tab" data-group="<?= e($area['key']) ?>" role="tab" aria-selected="false">
        <?= e($area['label']) ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- ── Content body ──────────────────────────────────────────────────────── -->
<div class="be-list" id="js-nav-body">

    <?php foreach ($areas as $area): ?>
    <section class="be-list__section" data-group="<?= e($area['key']) ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($area['label']) ?></h2>
            <span class="be-list__section-badge"><?= $area['total'] ?></span>
        </div>

        <?php foreach ($area['slots'] as $slot): ?>
        <div class="be-list__subsection" data-slot="<?= e($slot['slug']) ?>">
            <div class="be-list__subsection-header">
                <h3 class="be-list__subsection-title"><?= e($slot['label']) ?> <small style="color:var(--be-muted,#94a3b8);font-weight:400"><?= e($slot['slug']) ?></small></h3>
                <span class="be-list__section-badge"><?= count($slot['entries']) ?></span>
                <div class="be-list__section-actions">
                    <button type="button" class="be-icon-btn"
                            data-fetch-get="/backend/content/navigation/add?slot=<?= e($slot['slug']) ?>"
                            title="Eintrag in diesem Slot hinzufügen">
                        <svg class="be-icon" width="12" height="12" aria-hidden="true"><use href="#icon-plus"/></svg>
                    </button>
                </div>
            </div>
            <div class="be-tree be-tree--hub">
                <?php if (empty($slot['entries'])): ?>
                <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem .5rem">Keine Einträge</p>
                <?php else: foreach ($slot['entries'] as $entry): $renderNode($entry, 0); endforeach; endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>

    <?php if (!empty($ungrouped)): ?>
    <section class="be-list__section" data-group="__ungrouped">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Nicht zugeordnet</h2>
            <span class="be-list__section-badge"><?= count($ungrouped) ?></span>
        </div>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:0 .5rem .5rem">
            Tree-Roots ohne gültigen Render-Slot (kein Slot, oder ein unbekannter/entfernter Slug).
            Über «Bearbeiten» einen Render-Slot zuweisen oder den Eintrag löschen.
        </p>
        <div class="be-tree be-tree--hub">
            <?php foreach ($ungrouped as $entry): $renderNode($entry, 0); endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div>
