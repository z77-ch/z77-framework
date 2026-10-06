<?php
/**
 * One document (P3 part 3) — read from its SNAPSHOT only (`debtor.md`: the
 * address, the terms, the rates and the totals of an issued document are the
 * document's, never resolved again): the address block
 * (`AddressSnapshot::lines()`), dates and terms, the lines with their tax
 * code, the tax summary, the totals, the payment part and whether its
 * QR-bill is printable (`QrBill::of()` — the problems in German), the ledger
 * reference linked to the journal entry as a WINDOW (ADR-047) when the
 * bookkeeping is here, the credit notes and the open amount.
 *
 * Offers «PDF» (the document rendered on request, `pdf` action, a new tab),
 * «Neu fakturieren» while `invoicing`, «Gutschrift erstellen …» on a final
 * invoice — nothing else on a final credit note.
 *
 * @var \Z77\Module\Debtor\Entities\Invoice $document  lines loaded
 * @var \Z77\Module\Debtor\Invoicing\QrBill $bill
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $creditNotes
 * @var \Z77\Shared\Money\Money|null $openAmount  final invoices only — after credit notes, payments, discount and loss
 * @var list<\Z77\Module\Debtor\Entities\PaymentAllocation> $allocations  final invoices only (P4 part 1)
 * @var bool $ledgerKnown
 * @var array<string, string> $states
 * @var callable $fmt
 * @var string $actionBase
 * @var bool   $window
 * @var string $windowWidth
 */
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Module\Debtor\Services\Iban;

$window  = $window ?? false;
$winAttr = $window
    ? ' data-window="invoice-detail" data-window-entity="invoice:' . (int) $document->getId() . '" data-window-title="' . e($document->documentName()) . '"'
        . (!empty($windowWidth) ? ' data-window-width="' . e($windowWidth) . '"' : '')
    : '';
$actionBase = $actionBase ?? '/backend/finance/invoice';
$payment    = $document->getPayment();
$state      = $document->isFinal() ? 'final' : 'invoicing';
$row        = static fn(string $label, string $html): string => '<div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">' . e($label) . '</span><span class="be-list__cell be-list__cell--wrap">' . $html . '</span></div></div>';
$period     = $document->getServiceFrom()->format('d.m.Y') . ($document->getServiceTo() !== null ? ' – ' . $document->getServiceTo()->format('d.m.Y') : '');
?>
<div class="be-list"<?= $winAttr ?>>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                <?= e($document->documentName()) ?>
                <small class="be-list__cell--muted">· <?= e($document->getInvoiceDate()->format('d.m.Y')) ?> · <?= e($document->getCurrency()) ?> <?= e($fmt($document->getGrossTotal())) ?></small>
            </h2>
            <span class="badge <?= $state === 'final' ? 'badge--success' : 'badge--info' ?>" title="<?= e($states[$state]) ?>"><?= $state === 'final' ? 'definitiv' : 'in Fakturierung' ?></span>
        </div>

        <nav class="be-list__toggles" aria-label="Aktionen">
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/pdf?id=' . (int) $document->getId()) ?>" target="_blank" rel="noopener" title="Als PDF öffnen (neuer Tab)">PDF</a>
            <?php if ($document->isFinal() && !$document->isCreditNote() && $openAmount !== null && !$openAmount->isZero()): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/payment?id=' . (int) $document->getId()) ?>">Zahlung erfassen …</a>
            <?php endif; ?>
            <?php if (!$document->isFinal()): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/edit?id=' . (int) $document->getId()) ?>">Neu fakturieren …</a>
            <?php elseif (!$document->isCreditNote()): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/credit-note?of=' . (int) $document->getId()) ?>">Gutschrift erstellen …</a>
            <?php endif; ?>
        </nav>

        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <?= raw($row('Adresse', implode('<br>', array_map('e', $document->getAddress()->lines())))) ?>
            <?= raw($row('Leistung', e($period))) ?>
            <?php if ($document->isCreditNote()): ?>
            <?= raw($row('Gutschrift zu', '<a href="' . e($actionBase . '/detail?id=' . (int) $document->getCreditNoteOf()->getId()) . '">' . e($document->getCreditNoteOf()->documentName()) . '</a>')) ?>
            <?php else: ?>
            <?= raw($row('Fällig', e($document->getDueDate()->format('d.m.Y')) . ($document->getTermsText() !== null ? ' · ' . e($document->getTermsText()) : ''))) ?>
            <?php endif; ?>
            <?= raw($row('Preise', $document->getPriceMode() === 'gross' ? 'brutto (inkl. MWST)' : 'netto (zzgl. MWST)')) ?>
            <?php if ($document->getLedgerEntryRef() !== null): ?>
            <?php $ref = $document->getLedgerEntryRef(); $journal = '/backend/finance/journal/detail?ref=' . rawurlencode($ref); ?>
            <?= raw($row('Buchung', $ledgerKnown ? '<a href="' . e($journal) . '" data-window-open="' . e($journal) . '">' . e($ref) . '</a>' : e($ref))) ?>
            <?php elseif ($document->isFinal()): ?>
            <?= raw($row('Buchung', 'keine (Buchhaltung ausserhalb oder nichts zu buchen)')) ?>
            <?php endif; ?>
            <?php if ($allocations !== []): ?>
            <?= raw($row('Zahlungen', implode('<br>', array_map(static function ($a) use ($fmt, $actionBase, $document): string {
                $p = $a->getPayment();
                return e($p->getDate()->format('d.m.Y') . ' · ' . $a->kind()->label() . ' ' . $fmt($a->getAmount()))
                    . ($a->kind()->value === 'payment' && $p->getAccountNumber() !== '' ? ' <small class="be-list__cell--muted">· Konto ' . e($p->getAccountNumber()) . '</small>' : '')
                    . ($a->getLedgerEntryRef() !== null ? ' <small class="be-list__cell--muted">· Buchung ' . e($a->getLedgerEntryRef()) . '</small>' : '')
                    . ($p->getNote() !== null ? ' <small class="be-list__cell--muted">· ' . e($p->getNote()) . '</small>' : '')
                    . ' <a class="be-list__cell--muted" href="' . e($actionBase . '/payment?id=' . (int) $document->getId() . '&payment=' . (int) $p->getId()) . '" title="Zahlung ändern oder löschen">ändern</a>';
            }, $allocations)))) ?>
            <?php endif; ?>
            <?php if ($openAmount !== null): ?>
            <?= raw($row('Offen', $openAmount->isZero() ? '<span class="badge badge--success">bezahlt</span>' : e($fmt($openAmount)))) ?>
            <?php endif; ?>
            <?php if ($creditNotes !== []): ?>
            <?= raw($row('Gutschriften', implode(', ', array_map(fn($n) => '<a href="' . e($actionBase . '/detail?id=' . (int) $n->getId()) . '">' . e($n->documentName()) . '</a> ' . e($fmt($n->getGrossTotal())) . ($n->isFinal() ? '' : ' (in Fakturierung)'), $creditNotes)))) ?>
            <?php endif; ?>
            <?= raw($row('Erstellt', e($document->getCreatedAt()->format('d.m.Y H:i') . ' von ' . $document->getCreatedBy()) . ($document->getChangedAt() !== null ? ' · zuletzt ' . e($document->getChangedAt()->format('d.m.Y H:i') . ' von ' . $document->getChangedBy()) : ''))) ?>
        </div>

        <div class="be-form__section">Positionen</div>
        <div class="be-list__table" style="--be-list-cols: minmax(12rem, 3fr) 6rem 7rem 5rem 8rem">
            <div class="be-list__head">
                <span class="be-list__col">Text</span>
                <span class="be-list__col be-list__col--num">Menge</span>
                <span class="be-list__col be-list__col--num">Preis</span>
                <span class="be-list__col">MWST</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
            </div>
            <?php foreach ($document->getLines() as $line): ?>
            <?php $type = $line->type()->value; ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--wrap"<?= $line->getParentLine() !== null ? ' style="padding-left: 1.5rem"' : '' ?>><?= nl2br(e($line->getText())) ?><?= $line->getDiscountPercent() > 0 ? ' <small class="be-list__cell--muted">· Rabatt ' . e(TaxRate::formatPercent($line->getDiscountPercent())) . ' %</small>' : '' ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $line->getQuantity() !== null ? e(rtrim(rtrim($line->getQuantity(), '0'), '.') . ' ' . (string) $line->getUnit()) : '' ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($line->getUnitPrice())) ?></span>
                    <span class="be-list__cell"><?= $line->getTaxCode() !== null ? e($line->getTaxCode() . ' ' . TaxRate::formatPercent((int) $line->getTaxRate()) . ' %') : '' ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $type === 'text' ? '' : e($fmt($line->getAmount())) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell">Netto</span><span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell be-list__cell--num"><?= e($fmt($document->getNetTotal())) ?></span></div></div>
            <?php foreach ($document->getTaxes() as $tax): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell">MWST <?= e($tax->getTaxLabel()) ?> <?= e(TaxRate::formatPercent($tax->getTaxRate())) ?> % auf <?= e($fmt($tax->getBase())) ?></span><span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"><?= e($tax->getTaxCode()) ?></span><span class="be-list__cell be-list__cell--num"><?= e($fmt($tax->getTax())) ?></span></div></div>
            <?php endforeach; ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell"><strong>Total <?= e($document->getCurrency()) ?></strong></span><span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($document->getGrossTotal())) ?></strong></span></div></div>
        </div>

        <?php if (!$document->isCreditNote()): ?>
        <div class="be-form__section">Zahlteil (QR-Rechnung)</div>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)" data-qr-bill="<?= $bill->isPrintable() ? 'printable' : 'missing' ?>">
            <?php if ($payment->hasPaymentPart()): ?>
            <?= raw($row('Konto', e($bill->formattedAccount()) . ' <span class="badge ' . (Iban::isQrIban($payment->getAccount()) ? 'badge--success">QR-IBAN' : 'badge--muted">IBAN') . '</span> <small class="be-list__cell--muted">· Zahlungsziel ' . e($payment->getTargetCode()) . '</small>')) ?>
            <?= raw($row('Zahlbar an', e(implode(', ', array_filter([$payment->getCreditorName(), trim($payment->getCreditorStreet() . ' ' . $payment->getCreditorHouseNo()), trim($payment->getCreditorCountry() . ' ' . $payment->getCreditorZip() . ' ' . $payment->getCreditorCity())]))))) ?>
            <?= raw($row('Referenz', $payment->getReferenceType() === 'QRR' ? '<code>' . e($bill->formattedReference()) . '</code>' : 'keine (NON) · Mitteilung «' . e($payment->getMessage()) . '»')) ?>
            <?php endif; ?>
            <?php if (!$bill->isPrintable()): ?>
            <?= raw($row('Kein Zahlteil', implode('<br>', array_map('e', $bill->problems())))) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
