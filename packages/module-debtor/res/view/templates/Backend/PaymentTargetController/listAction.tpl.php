<?php
/**
 * Payment targets (plan §6.1, master data) — the company's bank accounts a
 * customer pays into. The inline switch is `active` (deactivate, never
 * delete — payments reference the code, ADR-043 decision 19). The QR-IBAN
 * and the plain IBAN are shown grouped in fours, each with its badge — the
 * QR-IBAN decides that an invoice carries a QR reference (P3 part 3). The
 * EFFECTIVE creditor block stands under each row (the holder fields, the
 * mandator's where empty — marked), so the name that lands on the bill is
 * visible before printing. The ledger account is flagged when the
 * bookkeeping will not take a posting on it — and marked «ungeprüft» when
 * module-financial is not installed at all. The row is `_row` — the same
 * partial a save or a switch answers with in place (ADR-047 addendum
 * 2026-10-10).
 *
 * Styling: the shared backend list/tree classes only.
 *
 * @var list<\Z77\Module\Debtor\Entities\PaymentTarget> $targets  in file order
 * @var array<string,bool|null> $accountState  code → postable? (null = financial absent)
 * @var array<string,\Z77\Module\Debtor\Services\Creditor> $creditors  code → the effective creditor block
 * @var bool $ledgerKnown
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/payment-target';
$creditors  = $creditors ?? [];
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zahlungsziele</h2>
            <span class="be-list__section-badge"><?= count($targets) ?></span>
        </div>
        <?php if (!$ledgerKnown): ?>
        <p class="be-form__hint">Ohne z77/module-financial bleibt das Konto ungeprüft — die Buchhaltung liegt ausserhalb.</p>
        <?php endif; ?>
        <div class="be-tree be-tree--hub" data-entity-list="paymentTarget">
            <?php if ($targets === []): ?>
            <p class="be-list__empty">Kein Zahlungsziel erfasst. Eine IBAN lässt sich nicht erraten — darum wird hier nichts vorbelegt.</p>
            <?php endif; ?>
            <?php foreach ($targets as $target): ?>
            <?= $this->partial('Backend/PaymentTargetController/_row', [
                'target'     => $target,
                'postable'   => $accountState[$target->getCode()] ?? null,
                'creditor'   => $creditors[$target->getCode()] ?? null,
                'actionBase' => $actionBase,
            ], 'Z77\\Module\\Debtor') ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
