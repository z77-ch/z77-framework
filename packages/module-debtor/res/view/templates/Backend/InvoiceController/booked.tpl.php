<?php
/**
 * «Verbucht» — what a «Verbuchen …» batch just booked (owner 2026-10-10:
 * after booking, nothing said whether the invoices were actually created and
 * sent). The documents of exactly that batch with their «Fakturiert» state,
 * and in the action row ONE button that opens their combined PDF in a new
 * tab (POST `pdf-batch`, `#[Csrf]`, `target="_blank"` — each document on its
 * own pages with its own payment part, `InvoicePdf::combined()`). Opening
 * that PDF marks every document in it «Fakturiert»; the button stays, a
 * second PDF changes no date. This page itself writes nothing — reloading it
 * shows the current marks.
 *
 * A PAGE like the confirmation before it (debtor.md DEBTOR-WIN-002); the
 * action row first (ADR-049).
 *
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents  final, in the batch's order
 * @var \Z77\Shared\Money\Money|null $total  credit notes counted negative
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$count      = count($documents);
$open       = count(array_filter($documents, static fn($d) => !$d->isDelivered()));
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase) ?>/pdf-batch" target="_blank" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php foreach ($documents as $document): ?>
        <input type="hidden" name="doc[]" value="<?= (int) $document->getId() ?>">
        <?php endforeach; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $count === 1 ? 'PDF öffnen' : 'PDF der ' . $count . ' Dokumente öffnen',
            'cancel'     => 'Zur Liste «Verbucht»',
            'cancelHref' => $actionBase . '/list?view=final',
        ], 'Z77\\Shared') ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Verbucht <small class="be-list__cell--muted">· <?= $count ?> Dokument<?= $count === 1 ? '' : 'e' ?><?= $total !== null ? ' · ' . e($fmt($total)) : '' ?></small></h2>
            <?php if ($open > 0): ?>
            <span class="badge badge--warning"><?= $open ?> nicht fakturiert</span>
            <?php else: ?>
            <span class="badge badge--success">alle fakturiert</span>
            <?php endif; ?>
        </div>
        <div class="be-list__table" style="--be-list-cols: 9rem 6.5rem minmax(10rem, 2fr) 8rem 9rem">
            <?php foreach ($documents as $document): ?>
            <div class="be-list__item" data-invoice-id="<?= (int) $document->getId() ?>">
                <div class="be-list__row">
                    <a class="be-list__cell be-list__cell--mono" href="<?= e($actionBase . '/detail?id=' . (int) $document->getId()) ?>" data-window-open="<?= e($actionBase . '/detail?id=' . (int) $document->getId()) ?>"><?= e($document->documentName()) ?></a>
                    <span class="be-list__cell"><?= e($document->getInvoiceDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell"><?= e(trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName())) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($document->getGrossTotal())) ?></span>
                    <span class="be-list__cell"><?= $document->isDelivered()
                        ? '<span class="badge badge--success">fakturiert ' . e($document->getDeliveredAt()->format('d.m.Y')) . '</span>'
                        : '<span class="badge badge--warning">nicht fakturiert</span>' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Die Dokumente sind verbucht und unveränderlich. Das PDF öffnet sich in einem neuen Tab — jedes Dokument auf eigenen Seiten mit seinem Zahlteil — und markiert die Dokumente als <strong>fakturiert</strong> (Datum und Benutzer). Wer die Dokumente anders versendet, markiert sie im Dokument mit «Als fakturiert markieren».</p>
    </form>
</div>
