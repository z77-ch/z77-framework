<?php
/**
 * Member accounts list (B7 backend handgrip) — readable without prose, like
 * the email-settings list: state as a colored badge, the waiting decision
 * carries its actions as explicit buttons on the row. Confirmed accounts sort
 * first (the operator's queue), then registered, then active.
 *
 *   bestätigt   → amber badge + «Freischalten» / «Ablehnen»
 *   registriert → muted badge + «Ablehnen» (never confirmed; cleanup also
 *                 removes them after the grace period)
 *   aktiv       → green badge, tenant reference shown, no actions (B8/B10)
 *
 * @var list<\Z77\Module\Member\Entities\MemberAccount> $accounts
 */
$badge = static fn(string $state): array => match ($state) {
    'confirmed' => ['badge--warning', 'bestätigt — wartet'],
    'active'    => ['badge--success', 'aktiv'],
    default     => ['badge--muted', 'registriert'],
};
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Member-Konten</h2>
            <span class="be-list__section-badge"><?= count($accounts) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if (empty($accounts)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Registrierungen vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($accounts as $account): ?>
            <?php [$badgeClass, $badgeLabel] = $badge($account->getState()); ?>
            <div class="be-tree__node" style="--node-depth:0" data-account-id="<?= e((string)$account->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <span class="be-tree__name" data-field="email">
                        <?= e($account->getEmail()) ?>
                        <span style="font-size:.75rem;color:var(--be-muted,#94a3b8)">
                            <?= e(trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''))) ?>
                            <?= $account->getCompany() !== null ? '· ' . e($account->getCompany()) : '' ?>
                        </span>
                    </span>

                    <span class="be-tree__url" data-field="dates" style="font-size:.75rem;color:var(--be-muted,#94a3b8)">
                        registriert <?= e(substr((string)$account->getCreatedAt(), 0, 10)) ?>
                        <?php if ($account->getConfirmedAt() !== null): ?>
                        · bestätigt <?= e(substr((string)$account->getConfirmedAt(), 0, 10)) ?>
                        <?php endif; ?>
                        <?php if ($account->getTenantRef() !== null): ?>
                        · Mandant <?= e($account->getTenantRef()) ?>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__route" data-field="state" style="display:inline-flex;gap:.5rem;align-items:center">
                        <span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>
                        <?php if ($account->isConfirmed()): ?>
                        <button type="button" class="be-btn be-btn--sm"
                                title="Konto aktiv schalten — erzeugt die Projekt-Anbindung und sendet die Freischalt-Mail"
                                data-fetch-get="/backend/service/member-accounts/confirm-activate?id=<?= e(rawurlencode((string)$account->getId())) ?>">Freischalten</button>
                        <?php endif; ?>
                        <?php if (!$account->isActive()): ?>
                        <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                                title="Konto löschen — ohne automatische Mail"
                                data-fetch-get="/backend/service/member-accounts/confirm-reject?id=<?= e(rawurlencode((string)$account->getId())) ?>">Ablehnen</button>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
