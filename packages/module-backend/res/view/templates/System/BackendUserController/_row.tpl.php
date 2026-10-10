<?php
/**
 * One backend user row. Rendered by the list and by BackendUserController as the in-place
 * answer of a save (`replaceRow('backend-user', id)`) and a create (`insertRow` into
 * `[data-entity-list="backend-user"]`), ADR-047 addendum 2026-10-10; core.js wires the ⋮
 * (FETCH-ROW-001). `data-user-id` + `draggable` serve the drag & drop of `backend-user/list.js`,
 * whose listeners sit on the list body — a row brought in later takes part without re-binding.
 *
 * @var \Z77\Shared\Entities\BackendUser $user
 * @var bool   $isSelf
 * @var array<string, string> $roleLabels
 */
$labels = array_map(fn(string $r) => $roleLabels[$r] ?? $r, $user->getRoles());
?>
<div class="be-tree__node" style="--node-depth:0" data-user-id="<?= e($user->getId()) ?>"
     data-entity="backend-user:<?= e($user->getId()) ?>">
    <div class="be-tree__row" draggable="true">
        <span class="be-tree__toggle" aria-hidden="true"></span>
        <button type="button" class="be-tree__menu" title="Aktionen"
                data-fetch-get="/backend/system/backend-user/actions?id=<?= e($user->getId()) ?>">⋮</button>
        <span class="be-tree__name"><?= e($user->getUsername()) ?><?php if ($isSelf): ?> <small style="color:var(--be-muted,#94a3b8)">(du)</small><?php endif; ?></span>
        <span class="be-tree__url"><?= e($labels ? implode(', ', $labels) : '—') ?></span>
        <span class="be-tree__route"></span>
    </div>
</div>
