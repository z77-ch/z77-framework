<?php
/**
 * Backend users — a flat list, drag & drop order (`backend-user/list.js`), the ⋮ hub per row.
 * One row = `_row.tpl.php`: a save answers `replaceRow`, a create `insertRow` into
 * `data-entity-list="backend-user"`, a delete `removeRow` (ADR-047 addendum 2026-10-10).
 *
 * @var \Z77\Shared\Entities\BackendUser[] $users
 * @var array<string, string> $roleLabels
 * @var \Z77\Shared\Auth\AuthUser $authUser
 */
?>
<?php /* Content-header (add + title) moved to the shell header band:
         System/BackendUserController/list.hc1.tpl.php (auto-loaded). */ ?>
<div class="be-list" id="js-user-body">
    <section class="be-list__section">
        <div class="be-tree be-tree--hub" data-entity-list="backend-user">
            <?php if (empty($users)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Benutzer vorhanden.</p>
            <?php endif; ?>

            <?php foreach ($users as $u): ?>
            <?= $this->partial('System/BackendUserController/_row', [
                'user'       => $u,
                'isSelf'     => $u->getId() === $authUser->getId(),
                'roleLabels' => $roleLabels,
            ], 'Z77\Module\Backend') ?>
            <?php endforeach; ?>
        </div>
    </section>
</div>
