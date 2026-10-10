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
 * One row = `_row` (also the in-place answer of a save, ADR-047 addendum 2026-10-10).
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
        <div class="be-tree be-tree--hub be-tree--lead-switch" data-entity-list="account">
            <?php if ($accounts === []): ?>
            <p class="be-list__empty">Der Kontenplan ist leer. Konten einzeln anlegen — oder den KMU-Kontenrahmen
               (Klassen 1–9 mit den gebräuchlichen Konten) als Ausgangslage übernehmen.</p>
            <p>
                <button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e($actionBase) ?>/confirm-adopt-kmu-chart">KMU-Kontenrahmen übernehmen …</button>
            </p>
            <?php endif; ?>
            <?php foreach ($accounts as $account): ?>
            <?= raw($this->partial('Backend/AccountController/_row', [
                'account'    => $account,
                'depth'      => (int) ($depths[$account->getId()] ?? 0),
                'typeLabels' => $typeLabels,
                'actionBase' => $actionBase,
            ], 'Z77\\Module\\Financial')) ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
