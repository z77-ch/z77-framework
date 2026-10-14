<?php
/**
 * The `<option>`s of a product-group picker — the tree in tree order, each name indented by its
 * depth (two no-break spaces per level, so the indent survives the browser's whitespace rule in
 * a native `<select>`). A shared building block (Rule 9): the article window's «Warengruppe» and
 * the group window's «Übergeordnete Gruppe» render the same options. The rows come from
 * `ProductGroupTree::selectable()` — WHICH groups are offered (active, the kept one, a group's own
 * subtree left out) is decided there, not here. A kept but inactive group says «(inaktiv)».
 *
 * Rendered with `$this->partial('partials/productGroupOptions', […], 'Z77\\Module\\Article')`
 * inside the caller's `<select>` (the caller renders the «none» option it needs).
 *
 * @var list<array{group: \Z77\Module\Article\Entities\ProductGroup, depth: int}> $rows
 * @var int $selected the chosen group id, 0 = none
 */
$selected = (int) ($selected ?? 0);
?>
<?php foreach ($rows as $row): $group = $row['group']; $id = (int) $group->getId(); ?>
<option value="<?= $id ?>"<?= $id === $selected ? ' selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}\u{00A0}", (int) $row['depth']) ?><?= e($group->getName()) ?><?= $group->isActive() ? '' : ' (inaktiv)' ?></option>
<?php endforeach; ?>
