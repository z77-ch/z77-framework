<?php
/**
 * Payment terms (plan §6.1, master data). One row per terms: the inline
 * switch is `active` (deactivate, never delete — profiles and invoices
 * reference the code, ADR-043 decision 19), «Bearbeiten» opens the modal;
 * the badge says how many debtors use it, so a deactivation is an informed
 * one.
 *
 * Styling: the shared backend list/tree classes only — no inline styles, no
 * CSS of its own.
 *
 * @var list<\Z77\Module\Debtor\Entities\PaymentTerms> $terms  in file order
 * @var array<string,int> $usage  code → number of debtor profiles referencing it
 * @var list<string> $languages   the installation's languages, default first
 * @var string $actionBase        URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/finance/payment-terms';

$tierText = static function (array $discounts): string {
    $parts = [];
    foreach ($discounts as $tier) {
        $parts[] = $tier['days'] . ' Tage ' . \Z77\Module\Debtor\Entities\PaymentTerms::formatPercent($tier['percent']) . ' %';
    }

    return implode(', ', $parts);
};
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zahlungskonditionen</h2>
            <span class="be-list__section-badge"><?= count($terms) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if ($terms === []): ?>
            <p class="be-list__empty">Keine Zahlungskonditionen vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($terms as $row): ?>
            <?php $count = $usage[$row->getCode()] ?? 0; ?>
            <div class="be-tree__node<?= $row->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-payment-terms-id="<?= e((string) $row->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $row->isActive() ? 'Aktiv — wird für neue Debitoren angeboten' : 'Inaktiv — nur noch an bestehenden Debitoren' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $row->getId()) ?>"<?= $row->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $row->getId()) ?>">⋮</button>

                    <span class="be-tree__name" data-field="code">
                        <code><?= e($row->getCode()) ?></code>
                        <?= e($row->getLabel()) ?>
                    </span>

                    <span class="be-tree__url" data-field="terms">
                        <?= $row->getDueDays() === 0 ? 'zahlbar sofort' : e((string) $row->getDueDays()) . ' Tage netto' ?>
                        <?php if ($row->getDiscounts() !== []): ?>
                        <small class="be-list__cell--muted">· Skonto <?= e($tierText($row->getDiscounts())) ?></small>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php foreach ($languages as $language): ?>
                        <span class="badge <?= isset($row->getDocumentText()[$language]) ? 'badge--success' : 'badge--muted' ?>"
                              title="<?= isset($row->getDocumentText()[$language]) ? 'Belegtext vorhanden' : 'Kein Belegtext' ?>"><?= e(mb_strtoupper($language)) ?></span>
                        <?php endforeach; ?>
                        <span class="badge <?= $count > 0 ? 'badge--success' : 'badge--muted' ?>" title="Debitoren mit diesen Konditionen"><?= $count ?> <?= $count === 1 ? 'Debitor' : 'Debitoren' ?></span>
                        <?php if (!$row->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
