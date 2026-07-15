<?php
/** @var \Z77\Shared\Entities\NavigationAlias[] $aliases */
/** @var array<int, \Z77\Shared\Entities\Navigation> $navById */
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-tree be-tree--hub">
            <?php if (empty($aliases)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Aliase vorhanden.</p>
            <?php endif; ?>

            <?php foreach ($aliases as $alias):
                $nav      = $navById[$alias->getNavigationId()] ?? null;
                $navLabel = $nav ? $nav->getName() . ' (' . $nav->getCanonicalPath() . ')' : '#' . $alias->getNavigationId() . ' — fehlt';
            ?>
            <div class="be-tree__node<?= $alias->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-alias-id="<?= e($alias->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <label class="be-switch be-switch--sm be-tree__switch" title="Aktiv schalten">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="/backend/content/navigation-alias/toggle-active?id=<?= e($alias->getId()) ?>"<?= $alias->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/navigation-alias/actions?id=<?= e($alias->getId()) ?>">⋮</button>
                    <span class="be-tree__name"><code><?= e($alias->getPath()) ?></code></span>
                    <span class="be-tree__url">→ <?= e($navLabel) ?></span>
                    <span class="be-tree__route">
                        <?php if ($alias->isCanonical()): ?><span class="be-tree__ref-label">canonical</span><?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
