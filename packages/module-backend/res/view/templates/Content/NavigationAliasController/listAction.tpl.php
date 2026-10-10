<?php
/** @var array<int, array{id:?int, path:string, navLabel:string, flags:string, active:bool}> $rows  NavigationAliasController::aliasDisplay() */

// Rows render through `_row` — the same partial the in-place answer of a create renders
// (ADR-047 addendum 2026-10-10). The container is `data-entity-list="navigationAlias"`: a
// create appends to it; the empty notice carries `data-entity-empty` so the first insert
// can remove it.
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-tree be-tree--hub" data-entity-list="navigationAlias">
            <?php if (empty($rows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem" data-entity-empty="navigationAlias">Keine Aliase vorhanden.</p>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
            <?= $this->partial('Content/NavigationAliasController/_row', ['row' => $row], 'Z77\\Module\\Backend') ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
