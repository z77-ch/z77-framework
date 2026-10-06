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
 * module-financial is not installed at all.
 *
 * Styling: the shared backend list/tree classes only.
 *
 * @var list<\Z77\Module\Debtor\Entities\PaymentTarget> $targets  in file order
 * @var array<string,bool|null> $accountState  code → postable? (null = financial absent)
 * @var array<string,\Z77\Module\Debtor\Services\Creditor> $creditors  code → the effective creditor block
 * @var bool $ledgerKnown
 * @var string $actionBase
 */
use Z77\Module\Debtor\Services\Iban;

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
        <div class="be-tree be-tree--hub">
            <?php if ($targets === []): ?>
            <p class="be-list__empty">Kein Zahlungsziel erfasst. Eine IBAN lässt sich nicht erraten — darum wird hier nichts vorbelegt.</p>
            <?php endif; ?>
            <?php foreach ($targets as $target): ?>
            <?php $postable = $accountState[$target->getCode()] ?? null; ?>
            <div class="be-tree__node<?= $target->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-payment-target-id="<?= e((string) $target->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $target->isActive() ? 'Aktiv — wird für neue Belege angeboten' : 'Inaktiv — nur noch an bestehenden Belegen' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $target->getId()) ?>"<?= $target->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $target->getId()) ?>">⋮</button>

                    <span class="be-tree__name" data-field="code">
                        <code><?= e($target->getCode()) ?></code>
                        <?= e($target->getLabel()) ?>
                    </span>

                    <span class="be-tree__url" data-field="iban">
                        <?php if ($target->hasQrIban()): ?><?= e(Iban::format($target->getQrIban())) ?> <span class="badge badge--success">QR-IBAN</span><?php endif; ?>
                        <?php if ($target->getIban() !== ''): ?><?= e(Iban::format($target->getIban())) ?> <span class="badge badge--muted">IBAN</span><?php endif; ?>
                        <small class="be-list__cell--muted">· Konto <?= e($target->getAccountNumber()) ?></small>
                        <?php $creditor = $creditors[$target->getCode()] ?? null; if ($creditor !== null): ?>
                        <br><small class="be-list__cell--muted" data-field="creditor">Empfänger: <?= e(implode(', ', array_filter([$creditor->name, trim($creditor->street . ' ' . $creditor->houseNo), trim($creditor->country . ' ' . $creditor->zip . ' ' . $creditor->city)]))) ?><?= $creditor->fromMandator !== [] ? ' (' . ($creditor->fromMandator === ['name', 'street', 'house_no', 'zip', 'city', 'country'] ? 'vom Mandanten' : 'teils vom Mandanten') . ')' : '' ?></small>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php if ($postable === false): ?>
                        <span class="badge badge--warning" title="Konto fehlt in der Buchhaltung, ist eine Gruppe oder inaktiv">Konto prüfen</span>
                        <?php elseif ($postable === null): ?>
                        <span class="badge badge--muted" title="Ohne module-financial nicht prüfbar">ungeprüft</span>
                        <?php endif; ?>
                        <?php if (!$target->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
