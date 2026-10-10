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
 * Fetched, the content of the payment WINDOW it is linked from (ADR-047,
 * addendum 2026-10-10: «Löschen …» is a `data-window-link`), so the delete
 * answers into that window — the detail replaces it. Without the script it
 * is a page. The action row (ADR-049, revision 2026-10-10) stands FIRST, like
 * every other dialog of this module (one shape; `--end` is not used).
 *
 * @var \Z77\Module\Debtor\Entities\Invoice $document
 * @var \Z77\Module\Debtor\Entities\Payment $payment
 * @var string $entityCsrf
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window  fetched as a window (ADR-047)
 * @var string $origin  where the window came from (WindowOrigin) — travels back as `_origin`
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$window     = !empty($window);
$winAttr    = $window
    ? ' data-window="invoice-payment-delete" data-window-entity="payment:' . (int) $payment->getId() . '" data-window-title="' . e('Zahlung löschen · ' . $document->documentName()) . '"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/payment-delete?id=' . (int) $document->getId() . '&payment=' . (int) $payment->getId()) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $payment->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Zahlung löschen',
            'kind'       => 'danger',
            'cancelHref' => $actionBase . '/detail?id=' . (int) $document->getId(),
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zahlung löschen <small class="be-list__cell--muted">· <?= e($document->documentName()) ?> · <?= e($payment->getDate()->format('d.m.Y')) ?></small></h2>
        </div>
        <?php endif; ?>
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
        <p class="be-form__hint">Zahlung vom <?= e($payment->getDate()->format('d.m.Y')) ?>. Die Buchungen werden aus dem Journal entfernt und im Änderungsprotokoll festgehalten; die Rechnung ist danach wieder um diesen Betrag offen. Nur möglich, solange die Periode offen ist.</p>
    </form>
</div>
