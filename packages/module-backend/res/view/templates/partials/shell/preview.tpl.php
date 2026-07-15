<?php
/**
 * Shell column 3 — preview / info pane (shell rebuild Phase 1). Optional per application:
 * a controller opts in by populating this (Phase 3) and switching the shell to `data-col3="on"`.
 * Phase 1 ships the empty state only; the column stays hidden (`data-col3="off"` on `.be-shell`)
 * until a host wires content. Own header slot + body mirror the other columns.
 *
 * @var string|null $previewContent  optional pre-rendered inner HTML (Phase 3); null = empty state
 */
?>
<div class="be-shell-col__head">
    <span class="be-shell-col__slot">Vorschau</span>
    <button type="button" class="be-shell-iconbtn" data-shell-drawer-close="r" aria-label="Schliessen">
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-x"/></svg>
    </button>
</div>
<div class="be-shell-col__body">
    <?php if (!empty($previewContent)): ?>
        <?= $previewContent ?>
    <?php else: ?>
    <div class="be-shell-preview__empty">
        <svg class="be-icon" width="22" height="22" aria-hidden="true"><use href="#icon-eye"/></svg>
        <span>Auswählen für die Vorschau.</span>
    </div>
    <?php endif; ?>
</div>
