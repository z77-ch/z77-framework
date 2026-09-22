<?php
/**
 * The chart of accounts (plan §5.1), in number order and indented by its
 * groups. One row per account: the inline switch is `active` (deactivate,
 * never delete — journal lines reference the account), the ⋮ button opens
 * the edit form. A group (not postable) is marked; a postable account shows
 * its type.
 *
 * While the chart is EMPTY the list offers «KMU-Kontenrahmen übernehmen»
 * (owner, 2026-09-22) — and only then; the service refuses it otherwise.
 *
 * Styling: the shared backend list/tree classes only (`.be-tree--hub` row
 * anatomy with `--node-depth` for the indentation, `.be-list__cell--muted`,
 * `.be-list__empty`, badges) — no CSS of its own.
 *
 * @var list<\Z77\Module\Financial\Entities\Account> $accounts  number order
 * @var array<int,int> $depths  account id → depth in the group chain
 * @var array<string,string> $typeLabels
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/finance/account';
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Kontenplan</h2>
            <span class="be-list__section-badge"><?= count($accounts) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if ($accounts === []): ?>
            <p class="be-list__empty">Der Kontenplan ist leer. Konten einzeln anlegen — oder den KMU-Kontenrahmen
               (Klassen 1–9 mit den gebräuchlichen Konten) als Ausgangslage übernehmen.</p>
            <p>
                <button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e($actionBase) ?>/confirm-adopt-kmu-chart">KMU-Kontenrahmen übernehmen …</button>
            </p>
            <?php endif; ?>
            <?php foreach ($accounts as $account): ?>
            <div class="be-tree__node<?= $account->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:<?= (int) ($depths[$account->getId()] ?? 0) ?>" data-account-id="<?= e((string) $account->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $account->isActive() ? 'Aktiv — wird für neue Buchungen angeboten' : 'Inaktiv — nur noch für bestehende Buchungen' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $account->getId()) ?>"<?= $account->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $account->getId()) ?>">⋮</button>

                    <span class="be-tree__name" data-field="name">
                        <?php if ($account->isPostable()): ?>
                        <code><?= e($account->getNumber()) ?></code> <?= e($account->getName()) ?>
                        <?php else: ?>
                        <strong><code><?= e($account->getNumber()) ?></code> <?= e($account->getName()) ?></strong>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__url" data-field="type">
                        <small class="be-list__cell--muted"><?= e($typeLabels[$account->getType()] ?? $account->getType()) ?></small>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php if (!$account->isPostable()): ?>
                        <span class="badge badge--muted">Gruppe</span>
                        <?php endif; ?>
                        <?php if (!$account->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
