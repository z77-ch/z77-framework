<?php
/**
 * Payment terms (plan §6.1, master data). One row per terms: the inline
 * switch is `active` (deactivate, never delete — profiles and invoices
 * reference the code, ADR-043 decision 19), «Bearbeiten» opens the modal;
 * the badge says how many debtors use it, so a deactivation is an informed
 * one. The row is `_row` — the same partial a save or a switch answers with
 * in place (ADR-047 addendum 2026-10-10).
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
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zahlungskonditionen</h2>
            <span class="be-list__section-badge"><?= count($terms) ?></span>
        </div>
        <div class="be-tree be-tree--hub" data-entity-list="paymentTerms">
            <?php if ($terms === []): ?>
            <p class="be-list__empty">Keine Zahlungskonditionen vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($terms as $row): ?>
            <?= $this->partial('Backend/PaymentTermsController/_row', [
                'row'        => $row,
                'count'      => $usage[$row->getCode()] ?? 0,
                'languages'  => $languages,
                'actionBase' => $actionBase,
            ], 'Z77\\Module\\Debtor') ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
