<?php
/**
 * «Zahlung löschen» — the last look before a settlement is removed (P4
 * part 1, owner 2026-10-06): what it cleared, with the journal reference of
 * each posting. One button POSTs to `payment-delete` (`#[Csrf]`, the
 * entity token and the VERSION shown), which hands id + version to
 * `PaymentService::delete()`: every posting retracted (its number stays a
 * documented gap), the rows gone, the invoice open again by that amount —
 * refused while the period is closed.
 *
 * @var \Z77\Module\Debtor\Entities\Invoice $document
 * @var \Z77\Module\Debtor\Entities\Payment $payment
 * @var string $entityCsrf
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase . '/payment-delete?id=' . (int) $document->getId() . '&payment=' . (int) $payment->getId()) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $payment->getVersion() ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zahlung löschen <small class="be-list__cell--muted">· <?= e($document->documentName()) ?> · <?= e($payment->getDate()->format('d.m.Y')) ?></small></h2>
        </div>
        <div class="be-list__table" style="--be-list-cols: 8rem 8rem minmax(10rem, 2fr)">
            <?php foreach ($payment->getAllocations() as $allocation): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= e($allocation->kind()->label()) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($allocation->getAmount())) ?></span>
                    <span class="be-list__cell be-list__cell--muted"><?= $allocation->getLedgerEntryRef() !== null ? 'Buchung ' . e($allocation->getLedgerEntryRef()) . ' wird gelöscht' : 'nicht verbucht' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Die Buchungen werden aus dem Journal entfernt und im Änderungsprotokoll festgehalten; die Rechnung ist danach wieder um diesen Betrag offen. Nur möglich, solange die Periode offen ist.</p>
        <div class="z77-form-actions">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm">Zahlung löschen</button>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/detail?id=' . (int) $document->getId()) ?>">Abbrechen</a>
        </div>
    </form>
</div>
