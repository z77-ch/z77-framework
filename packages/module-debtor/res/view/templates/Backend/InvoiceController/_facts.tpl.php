<?php
/**
 * The facts of an EXISTING document — the block under the positions of the one mask
 * (owner 2026-10-21: the read view `detail` is gone, what only it showed stands here,
 * read-only). From the SNAPSHOT for a final document (`debtor.md`: an issued document is
 * never resolved again); what the form shows itself (address, subject, dates, terms, lines,
 * totals) is not repeated.
 *
 *   - «Beleg»: the state badge; «Gutschrift zu» (a credit note); «Buchung» — the ledger
 *     reference linked to the journal entry as a WINDOW (ADR-047, `?ref=`) when the
 *     bookkeeping is here; «Zahlungen» — the settlements with their «ändern» link (P4 part 1);
 *     «Offen»; «Mahnungen» — the dunning history with the fee document and its PDF (P4 part 3);
 *     «Mahngebühr zu» (a fee document); «Gutschriften»; «MWST» — the note a document without
 *     VAT printed, from the snapshot (owner 2026-10-22; only when one was stored); «Erstellt»
 *     with the last change;
 *   - «Zahlteil (QR-Rechnung)» (not on a credit note): account with its kind, the creditor, the
 *     reference in blocks of five or «NON» with the message — on a draft «folgt mit der
 *     Belegnummer beim Buchungsdruck» — and «Vor dem Buchungsdruck beheben» / «Kein Zahlteil»
 *     with the problems. The bill is `InvoicingService::bookingBill()`: for a DRAFT the payment
 *     part the booking print WILL write, taken again from the target and the mandator (owner
 *     2026-10-13, review H5 — the problems are exactly what the booking print refuses).
 *
 * Links to other documents, «ändern» of a payment and the journal stay in the window
 * (`data-window-link`, `data-window-open`); the PDFs open a new tab.
 *
 * @var \Z77\Module\Debtor\Entities\Invoice $document  lines loaded
 * @var \Z77\Module\Debtor\Invoicing\QrBill $bill
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $creditNotes
 * @var \Z77\Shared\Money\Money|null $openAmount  final invoices only — after credit notes, payments, discount and loss
 * @var list<\Z77\Module\Debtor\Entities\PaymentAllocation> $allocations  final invoices only
 * @var list<\Z77\Module\Debtor\Entities\DunningNotice> $notices  final invoices only
 * @var bool $ledgerKnown
 * @var array<string, string> $states
 * @var callable $fmt
 * @var string $actionBase
 */
use Z77\Module\Debtor\Services\Iban;

$actionBase = $actionBase ?? '/backend/finance/invoice';
$payment    = $bill->payment();
$state      = $document->isFinal() ? 'final' : 'invoicing';
$row        = static fn(string $label, string $html): string => '<div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">' . e($label) . '</span><span class="be-list__cell be-list__cell--wrap">' . $html . '</span></div></div>';
/** A link that stays in the window (ADR-047): `data-window-link`; on a page a plain link. */
$winLink    = static fn(string $href, string $label, string $title = ''): string
    => '<a href="' . e($href) . '" data-window-link' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($label) . '</a>';
$mask       = static fn(int $id): string => $actionBase . '/edit?id=' . $id;
?>
<div class="be-form__block" data-invoice-facts>
    <div class="be-form__block-head">
        <h3 class="be-list__section-title">Beleg</h3>
        <span class="badge <?= $state === 'final' ? 'badge--success' : 'badge--info' ?>" title="<?= e($states[$state]) ?>"><?= $state === 'final' ? 'verbucht' : 'in Fakturierung' ?></span>
    </div>
    <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
        <?php if ($document->isCreditNote()): ?>
        <?= raw($row('Gutschrift zu', $winLink($mask((int) $document->getCreditNoteOf()->getId()), $document->getCreditNoteOf()->documentName()))) ?>
        <?php endif; ?>
        <?php if ($document->getLedgerEntryRef() !== null): ?>
        <?php $ref = $document->getLedgerEntryRef(); $journal = '/backend/finance/journal/detail?ref=' . rawurlencode($ref); ?>
        <?= raw($row('Buchung', $ledgerKnown ? '<a href="' . e($journal) . '" data-window-open="' . e($journal) . '">' . e($ref) . '</a>' : e($ref))) ?>
        <?php elseif ($document->isFinal()): ?>
        <?= raw($row('Buchung', 'keine (Buchhaltung ausserhalb oder nichts zu buchen)')) ?>
        <?php endif; ?>
        <?php if ($allocations !== []): ?>
        <?= raw($row('Zahlungen', implode('<br>', array_map(static function ($a) use ($fmt, $actionBase, $document, $winLink): string {
            $p = $a->getPayment();
            return e($p->getDate()->format('d.m.Y') . ' · ' . $a->kind()->label() . ' ' . $fmt($a->getAmount()))
                . ($a->kind()->value === 'payment' && $p->getAccountNumber() !== '' ? ' <small class="be-list__cell--muted">· Konto ' . e($p->getAccountNumber()) . '</small>' : '')
                . ($a->getLedgerEntryRef() !== null ? ' <small class="be-list__cell--muted">· Buchung ' . e($a->getLedgerEntryRef()) . '</small>' : '')
                . ($p->getNote() !== null ? ' <small class="be-list__cell--muted">· ' . e($p->getNote()) . '</small>' : '')
                . ' <small>' . $winLink($actionBase . '/payment?id=' . (int) $document->getId() . '&payment=' . (int) $p->getId(), 'ändern', 'Zahlung ändern oder löschen') . '</small>';
        }, $allocations)))) ?>
        <?php endif; ?>
        <?php if ($openAmount !== null): ?>
        <?= raw($row('Offen', $openAmount->isZero() ? '<span class="badge badge--success">bezahlt</span>' : e($fmt($openAmount)))) ?>
        <?php endif; ?>
        <?php if ($notices !== []): ?>
        <?= raw($row('Mahnungen', implode('<br>', array_map(static function ($n) use ($fmt, $mask, $winLink): string {
            $fee = $n->getFeeInvoice();
            return e($n->getRun()->getRunDate()->format('d.m.Y') . ' · ' . $n->getLevelCode() . ' · offen damals ' . $fmt($n->getOpenAmount()))
                . ($fee !== null ? ' <small class="be-list__cell--muted">· ' . $winLink($mask((int) $fee->getId()), $fee->documentName()) . ' ' . e($fmt($fee->getGrossTotal())) . '</small>' : '')
                . ' <a class="be-list__cell--muted" href="/backend/finance/dunning/notice-pdf?id=' . (int) $n->getId() . '" target="_blank" rel="noopener">PDF</a>';
        }, $notices)))) ?>
        <?php endif; ?>
        <?php if ($document->getSourceType() === 'dunning'): [$feeOfNumber, $feeLevel] = array_pad(explode(':', (string) $document->getSourceRef(), 2), 2, ''); ?>
        <?= raw($row('Mahngebühr zu', 'Rechnung ' . e($feeOfNumber) . ' <small class="be-list__cell--muted">· Stufe ' . e($feeLevel) . '</small>')) ?>
        <?php endif; ?>
        <?php if ($creditNotes !== []): ?>
        <?= raw($row('Gutschriften', implode(', ', array_map(fn($n) => $winLink($mask((int) $n->getId()), $n->documentName()) . ' ' . e($fmt($n->getGrossTotal())) . ($n->isFinal() ? '' : ' (in Fakturierung)'), $creditNotes)))) ?>
        <?php endif; ?>
        <?php if ($document->getVatNote() !== null): /* a document without VAT: the note as printed, from the snapshot (owner 2026-10-22) */ ?>
        <?= raw($row('MWST', '<span class="be-list__cell--muted">' . e($document->getVatNote()) . '</span>')) ?>
        <?php endif; ?>
        <?= raw($row('Erstellt', e($document->getCreatedAt()->format('d.m.Y H:i') . ' von ' . $document->getCreatedBy()) . ($document->getChangedAt() !== null ? ' · zuletzt ' . e($document->getChangedAt()->format('d.m.Y H:i') . ' von ' . $document->getChangedBy()) : ''))) ?>
    </div>

    <?php if (!$document->isCreditNote()): ?>
    <span class="be-form__card-label">Zahlteil (QR-Rechnung)</span>
    <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)" data-qr-bill="<?= $bill->isPending() ? 'pending' : ($bill->isPrintable() ? 'printable' : 'missing') ?>">
        <?php if ($payment->hasPaymentPart()): ?>
        <?= raw($row('Konto', e($bill->formattedAccount()) . ' <span class="badge ' . (Iban::isQrIban($payment->getAccount()) ? 'badge--success">QR-IBAN' : 'badge--muted">IBAN') . '</span> <small class="be-list__cell--muted">· Zahlungsziel ' . e($payment->getTargetCode()) . '</small>')) ?>
        <?= raw($row('Zahlbar an', e(implode(', ', array_filter([$payment->getCreditorName(), trim($payment->getCreditorStreet() . ' ' . $payment->getCreditorHouseNo()), trim($payment->getCreditorCountry() . ' ' . $payment->getCreditorZip() . ' ' . $payment->getCreditorCity())]))))) ?>
        <?php if ($bill->isPending()): ?>
        <?= raw($row('Referenz', 'folgt mit der Belegnummer beim Buchungsdruck' . ($payment->getReferenceType() === 'QRR' ? ' (QR-Referenz)' : ' (keine Referenz, NON — die Mitteilung nennt das Dokument)'))) ?>
        <?php else: ?>
        <?= raw($row('Referenz', $payment->getReferenceType() === 'QRR' ? '<code>' . e($bill->formattedReference()) . '</code>' : 'keine (NON) · Mitteilung «' . e($payment->getMessage()) . '»')) ?>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (!$bill->isPrintable() && $bill->problems() !== []): ?>
        <?= raw($row($bill->isPending() ? 'Vor dem Buchungsdruck beheben' : 'Kein Zahlteil', implode('<br>', array_map('e', $bill->problems())))) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
