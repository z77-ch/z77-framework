<?php
/**
 * One payment-target row of the list (`.be-tree__node`) — the single place that renders it,
 * for the list and for the in-place answer of a save or a switch (ADR-047 addendum
 * 2026-10-10). The node carries `data-entity="paymentTarget:<id>"`, the address
 * `FetchResponse::rowTarget()` builds.
 *
 * The node is the unit an answer replaces (`replaceRow`) or adds (`insertRow`) — core.js
 * wires the switch and the ⋮ of the HTML it inserts.
 *
 * @var \Z77\Module\Debtor\Entities\PaymentTarget $target
 * @var bool|null $postable   the ledger takes postings on its account? (null = financial absent)
 * @var \Z77\Module\Debtor\Services\Creditor|null $creditor  the effective creditor block
 * @var string $actionBase    URL root of THIS mount
 */
use Z77\Module\Debtor\Services\Iban;

$actionBase = $actionBase ?? '/backend/finance/payment-target';
$id         = (string) $target->getId();

$slots = [
    'code' => function () use ($target): void { ?>
                        <code><?= e($target->getCode()) ?></code>
                        <?= e($target->getLabel()) ?>
<?php },
    'iban' => function () use ($target, $creditor): void { ?>
                        <?php if ($target->hasQrIban()): ?><?= e(Iban::format($target->getQrIban())) ?> <span class="badge badge--success">QR-IBAN</span><?php endif; ?>
                        <?php if ($target->getIban() !== ''): ?><?= e(Iban::format($target->getIban())) ?> <span class="badge badge--muted">IBAN</span><?php endif; ?>
                        <small class="be-list__cell--muted">· Konto <?= e($target->getAccountNumber()) ?></small>
                        <?php if ($creditor !== null): ?>
                        <br><small class="be-list__cell--muted" data-field="creditor">Empfänger: <?= e(implode(', ', array_filter([$creditor->name, trim($creditor->street . ' ' . $creditor->houseNo), trim($creditor->country . ' ' . $creditor->zip . ' ' . $creditor->city)]))) ?><?= $creditor->fromMandator !== [] ? ' (' . ($creditor->fromMandator === ['name', 'street', 'house_no', 'zip', 'city', 'country'] ? 'vom Mandanten' : 'teils vom Mandanten') . ')' : '' ?></small>
                        <?php endif; ?>
<?php },
    'state' => function () use ($target, $postable): void { ?>
                        <?php if ($postable === false): ?>
                        <span class="badge badge--warning" title="Konto fehlt in der Buchhaltung, ist eine Gruppe oder inaktiv">Konto prüfen</span>
                        <?php elseif ($postable === null): ?>
                        <span class="badge badge--muted" title="Ohne module-financial nicht prüfbar">ungeprüft</span>
                        <?php endif; ?>
                        <?php if (!$target->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
<?php },
];

?>
            <div class="be-tree__node<?= $target->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-payment-target-id="<?= e($id) ?>" data-entity="paymentTarget:<?= e($id) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $target->isActive() ? 'Aktiv — wird für neue Belege angeboten' : 'Inaktiv — nur noch an bestehenden Belegen' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e($id) ?>"<?= $target->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e($id) ?>">⋮</button>

                    <span class="be-tree__name" data-field="code"><?php ($slots['code'])(); ?></span>

                    <span class="be-tree__url" data-field="iban"><?php ($slots['iban'])(); ?></span>

                    <span class="be-tree__route" data-field="state"><?php ($slots['state'])(); ?></span>
                </div>
            </div>
