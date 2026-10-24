<?php
/**
 * «Rechnung löschen …» — the last look before an ISSUED manual document (in the Faktura-Journal, not
 * posted) is deleted (ADR-054 decision 14, Q7 — decided provisionally): party, number, date, amount
 * and a MANDATORY reason. One button POSTs to `confirm-delete` (`#[Csrf]`, the entity token
 * `invoice:<id>` and the VERSION shown) → `InvoicingService::delete()`: the document stays with its
 * number, snapshot and archived PDF as «gelöscht» (who / when / why — the gap documentation); its
 * waiting e-mails are cancelled. Nothing was posted, so nothing is reversed.
 *
 * Fetched, the content of the document WINDOW (the mask) it is linked from (`data-window-link`); the
 * delete answers the document's mask into that window. Without the script a page. Action row first
 * (ADR-049).
 *
 * @var \Z77\Module\Debtor\Entities\Invoice $document  issued (`issued`)
 * @var string $reason  what was typed (a refused POST)
 * @var string $error
 * @var string $entityCsrf
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$window     = !empty($window);
$id         = (int) $document->getId();
$winAttr    = $window
    ? ' data-window="invoice-delete" data-window-entity="invoice:' . $id . '" data-window-title="' . e('Rechnung löschen · ' . $document->documentName()) . '"'
    : '';
$row        = static fn(string $label, string $text): string => '<div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">' . e($label) . '</span><span class="be-list__cell be-list__cell--wrap">' . e($text) . '</span></div></div>';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-delete?id=' . $id) ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $document->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Rechnung löschen',
            'kind'       => 'danger',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Rechnung löschen <small class="be-list__cell--muted">· <?= e($document->documentName()) ?></small></h2>
        </div>
        <?php endif; ?>
        <?php if (($error ?? '') !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <?= raw($row('Debitor', trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName()))) ?>
            <?= raw($row('Nummer', (string) $document->getNumber() . ($document->getDocumentStamp() !== null ? ' · ID ' . $document->getDocumentStamp() : ''))) ?>
            <?= raw($row('Rechnungsdatum', $document->getInvoiceDate()->format('d.m.Y'))) ?>
            <?= raw($row('Betrag', $document->getCurrency() . ' ' . $fmt($document->getGrossTotal()))) ?>
        </div>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="invoice-delete-reason">Grund</label>
            <textarea id="invoice-delete-reason" name="reason" rows="2" maxlength="255" required aria-invalid="<?= ($error ?? '') !== '' && trim($reason ?? '') === '' ? 'true' : 'false' ?>"><?= e($reason ?? '') ?></textarea>
        </div>
        <p class="be-form__hint">Die Rechnung ist fakturiert, aber nicht verbucht. Sie bleibt mit ihrer Nummer, dem PDF und dem Grund unter «Gelöscht» aufbewahrt — so ist die Nummer erklärt. Noch wartende E-Mails zu ihr werden abgebrochen; nichts wird verbucht oder storniert.</p>
    </form>
</div>
