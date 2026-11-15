<?php
/**
 * «Löschen …» / «Verwerfen …» of a creditor invoice, loaded into the invoice window
 * (`data-window-link`) — the last look before the invoice and its lines go. The PDF STAYS in the
 * DMS and the same file is not read again (owner answer 4); the window says both. The form carries
 * the version shown.
 *
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice $invoice
 * @var array<string, mixed> $pdf  ReceiptPhoto
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$id         = (int) $invoice->getId();
$verb       = $invoice->isBlocked() ? 'verwerfen' : 'löschen';
$title      = $invoice->label() . ' · ' . $verb;
$winAttr    = $window ? ' data-window="creditor-invoice-delete" data-window-entity="creditorInvoice:' . $id . '" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-delete?id=' . $id) ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="version" value="<?= (int) $invoice->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Rechnung ' . $verb,
            'kind'       => 'danger',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Kreditor</span><span class="be-list__cell"><?= e($invoice->getCreditor()?->getContact()?->displayName() ?? '—') ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Betrag</span><span class="be-list__cell be-list__cell--mono"><?= $invoice->getAmount() !== null ? e($fmt($invoice->getAmount())) : '—' ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">PDF</span><span class="be-list__cell"><?= $pdf['state'] === 'doc' ? e($pdf['name']) : 'Dokument Nr. ' . (int) $pdf['id'] ?></span></div></div>
        </div>
        <p class="be-form__hint">Das PDF bleibt unter «Kreditorenrechnungen» in den Dokumenten. Dieselbe Datei wird nicht noch einmal eingelesen — ein erneutes Hochladen meldet «bereits eingelesen».</p>
    </form>
</div>
