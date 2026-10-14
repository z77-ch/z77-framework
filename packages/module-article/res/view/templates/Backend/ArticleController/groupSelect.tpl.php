<?php
/**
 * Artikel — the product-group TREE at the top of the rail (section `railSelect`, added by
 * `listAction()`; owner 2026-10-14: «the tree in the rail»). The shared CSS-only picker the
 * fiscal year uses (`.be-shell-add--select`, css-backend.md rail-top selection): `<details>` /
 * `<summary>`, every group a plain link to the list with `?group=<id>` — the list's extra `group`,
 * so the choice IS the address (bookmarkable, no session, no JavaScript). A group shows its
 * articles INCLUDING its sub-groups. «Alle Warengruppen» first, then the tree, each name
 * indented by its depth; the current one carries the check mark (`aria-current`).
 *
 * The other state of the list (search, sort) travels along (`ListState::query()`); the page
 * starts at 1 again.
 *
 * @var \Z77\Module\Article\Services\ProductGroupTree $tree
 * @var \Z77\Shared\Listing\ListState $state
 * @var int|null $group  the chosen group, null = all
 * @var string $actionBase
 */
if (!isset($tree, $state) || $tree->isEmpty()) { return; }
$listUrl = ($actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE) . '/list';
$href    = static fn(?int $id): string => rtrim($listUrl . '?' . $state->query(['group' => $id]), '?');
$current = $group !== null ? $tree->find($group) : null;
?>
<details class="be-shell-add be-shell-add--select">
    <summary class="be-btn be-btn--ghost" aria-label="Warengruppe wählen">
        <span class="be-btn__label"><?php if ($current === null): ?><span class="be-shell-select__value">Alle Warengruppen</span><?php else: ?>Warengruppe <span class="be-shell-select__value"><?= e($current->getName()) ?></span><?php endif; ?></span>
        <svg class="be-icon be-shell-add__chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </summary>
    <nav class="be-shell-add__panel" aria-label="Warengruppen">
        <a class="be-shell-add__item" href="<?= e($href(null)) ?>"<?= $current === null ? ' aria-current="true"' : '' ?>>Alle Warengruppen</a>
        <hr class="be-shell-add__sep">
        <?php foreach ($tree->rows() as $row): $id = (int) $row['group']->getId(); ?>
        <a class="be-shell-add__item" href="<?= e($href($id)) ?>"<?= $id === $group ? ' aria-current="true"' : '' ?> title="<?= e($tree->path($id)) ?>"><?= str_repeat("\u{00A0}\u{00A0}\u{00A0}", (int) $row['depth']) ?><?= e($row['group']->getName()) ?><?= $row['group']->isActive() ? '' : ' (inaktiv)' ?></a>
        <?php endforeach; ?>
    </nav>
</details>
