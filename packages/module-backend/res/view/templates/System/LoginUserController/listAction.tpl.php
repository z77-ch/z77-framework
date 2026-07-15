<?php
/** @var \Z77\Shared\Entities\LoginUser[] $users */
/** @var array<string, string> $roleLabels */
/** @var \Z77\Shared\Auth\AuthUser $authUser */

$roleText = function (\Z77\Shared\Entities\LoginUser $u) use ($roleLabels): string {
    $labels = array_map(fn(string $r) => $roleLabels[$r] ?? $r, $u->getRoles());
    return $labels ? implode(', ', $labels) : '—';
};
?>
<?php /* Content-header (add + title) moved to the shell header band:
         System/LoginUserController/list.hc1.tpl.php (auto-loaded). */ ?>
<div class="be-list" id="js-user-body">
    <section class="be-list__section">
        <div class="be-tree be-tree--hub">
            <?php if (empty($users)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Benutzer vorhanden.</p>
            <?php endif; ?>

            <?php foreach ($users as $u):
                $isSelf = $u->getId() === $authUser->getId();
            ?>
            <div class="be-tree__node" style="--node-depth:0" data-user-id="<?= e($u->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/system/login-user/actions?id=<?= e($u->getId()) ?>">⋮</button>
                    <span class="be-tree__name"><?= e($u->getUsername()) ?><?php if ($isSelf): ?> <small style="color:var(--be-muted,#94a3b8)">(du)</small><?php endif; ?></span>
                    <span class="be-tree__url"><?= e($roleText($u)) ?></span>
                    <span class="be-tree__route"></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
