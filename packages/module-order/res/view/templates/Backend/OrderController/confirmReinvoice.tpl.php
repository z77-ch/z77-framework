<?php
/**
 * «Neu fakturieren …» (ADR-054 decision 12, owner decisions O9 / O11; step 5 of the Faktura-Journal build
 * plan) — the last look before the order's ISSUED invoice (in the Faktura-Journal, not posted) is issued
 * again from the order as it stands: debtor's classification (`OrderService::reinvoicePreview()`) —
 * «neue Version mit gleicher Nummer» (the number and the QR reference stay, the earlier version's PDF stays
 * archived) or «Ersatz mit neuer Nummer» (the invoice is replaced, the new one prints «ersetzt Rechnung …»,
 * the order names the new one) or «keine Änderung» — with the reasons, and the invoice's flag (why the
 * journal is blocked). One button POSTs the order's VERSION in THIS tab (`#[Csrf]`) → `reinvoice` → debtor's
 * result list of the invoice that stands (PDF, e-mail).
 *
 * A PAGE like «Fakturieren …» (DEBTOR-WIN-002). Action row first (ADR-049).
 *
 * @var \Z77\Module\Order\Entities\SalesOrder $order
 * @var \Z77\Module\Debtor\Entities\Invoice|null $invoice
 * @var \Z77\Module\Debtor\Invoicing\Correction|null $correction  null = refused ($error)
 * @var string $correctionWord
 * @var string $error
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$id         = (int) $order->getId();
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase . '/reinvoice?id=' . $id) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $order->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => $correction !== null ? ($correction->isReplacement() ? 'Ersetzen und neu fakturieren' : ($correction->isRevision() ? 'Neu fakturieren' : 'Bestätigen')) : '',
            'cancel'     => 'Zur Liste',
            'cancelHref' => $actionBase . '/list?view=closed',
        ], 'Z77\\Shared') ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Neu fakturieren <small class="be-list__cell--muted">· Auftrag <?= e($order->documentNumber()) ?><?= $invoice !== null ? ' · ' . e($invoice->documentName()) : '' ?></small></h2>
        </div>
        <?php if ($error !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($invoice !== null && $invoice->getReissueNeeded() !== null): ?>
        <div class="be-modal__alert">Das Faktura-Journal ist gesperrt: <?= e($invoice->getReissueNeeded()) ?></div>
        <?php endif; ?>
        <?php if ($correction !== null): ?>
        <div class="be-modal__alert<?= $correction->isReplacement() ? ' be-modal__alert--error' : '' ?>" data-reinvoice="<?= e($correction->kind) ?>">
            <strong><?= e(ucfirst($correctionWord)) ?></strong><?= $correction->isNone() ? '' : ' — ' . e($correction->summary()) ?>.
            <?php if ($correction->isReplacement()): ?>
            <?= e($invoice->documentName()) ?> wird ersetzt und bleibt mit ihrer Nummer unter «Ersetzt»; die neue Rechnung erhält eine neue Nummer und druckt «ersetzt <?= e($invoice->documentName()) ?> (ID <?= e((string) $invoice->getDocumentStamp()) ?>)». Der Auftrag nennt danach die neue Rechnung; noch wartende E-Mails zur alten werden abgebrochen.
            <?php elseif ($correction->isRevision()): ?>
            <?= e($invoice->documentName()) ?> behält Nummer und QR-Referenz und wird zu Version <?= (int) $invoice->getRevision() + 1 ?>; das PDF der Version <?= (int) $invoice->getRevision() ?> bleibt archiviert.
            <?php else: ?>
            Die Rechnung stimmt mit dem Auftrag überein — es wird nichts neu gedruckt<?= $invoice->getReissueNeeded() !== null ? '; die Sperre wird aufgehoben' : '' ?>.
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Kunde</span><span class="be-list__cell"><?= e($order->getContact()->displayName()) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Auftrag jetzt</span><span class="be-list__cell"><?= $order->getTotalGross() !== null ? e($fmt($order->getTotalGross())) : '–' ?></span></div></div>
            <?php if ($invoice !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Rechnung</span><span class="be-list__cell"><?= e($invoice->documentName() . ' vom ' . $invoice->getInvoiceDate()->format('d.m.Y') . ' · ' . $fmt($invoice->getGrossTotal())) ?></span></div></div>
            <?php endif; ?>
        </div>
        <p class="be-form__hint">Das Rechnungsdatum bleibt das der fakturierten Rechnung. Verbucht wird nichts — die Rechnung steht im Faktura-Journal, bis es mit «Journal verbuchen» übernommen wird.</p>
    </form>
</div>
