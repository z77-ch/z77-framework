<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Vat\Calculation\PriceMode;

/**
 * What a SOURCE hands `InvoicingService` (plan §6.2, ADR-040 decision 3):
 * an order, a manual invoice screen, a later contract or fee run — the
 * single entry for every one of them. The draft names the party BY ID,
 * the dates, the currency and price mode, the payment terms (or none — the
 * debtor profile's default then applies), the lines ({@see LineDraft}) and
 * an opaque origin. For a credit note it names the FINAL invoice it corrects.
 *
 * `address`: normally null — the service snapshots the contact's invoice
 * address (type `invoice`, else `main`). A source that already holds the
 * address the document must show (an order, whose invoice address was
 * chosen at order time) passes it, and the service snapshots that instead.
 *
 * `serviceFrom` is null ONLY on a credit note: the service date of a
 * reduction of consideration is the date of the ORIGINAL supply (ADR-041
 * decision 4), so the service takes it — and `serviceTo` — from the invoice
 * the credit note corrects (review 2026-09-23). A credit note may still name
 * its own dates (a partial period), but its rates must equal the invoice's.
 *
 * Plain data, no rules: `InvoicingService` validates and refuses with a
 * reason ({@see \Z77\Module\Debtor\Services\InvoiceRefusedException}).
 */
final class InvoiceDraft
{
    /** @param list<LineDraft> $lines in document order */
    public function __construct(
        public readonly InvoiceKind $kind,
        public readonly int $contactId,
        public readonly \DateTimeImmutable $invoiceDate,
        public readonly ?\DateTimeImmutable $serviceFrom,
        public readonly ?\DateTimeImmutable $serviceTo,
        public readonly string $currency,
        public readonly PriceMode $priceMode,
        public readonly ?string $paymentTermsCode,
        public readonly ?AddressSnapshot $address,
        public readonly array $lines,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceRef = null,
        public readonly ?int $creditNoteOfId = null,
    ) {}

    /** @param list<LineDraft> $lines */
    public static function invoice(
        int $contactId,
        \DateTimeImmutable $invoiceDate,
        \DateTimeImmutable $serviceFrom,
        string $currency,
        array $lines,
        ?\DateTimeImmutable $serviceTo = null,
        PriceMode $priceMode = PriceMode::Net,
        ?string $paymentTermsCode = null,
        ?AddressSnapshot $address = null,
        ?string $sourceType = null,
        ?string $sourceRef = null,
    ): self {
        return new self(InvoiceKind::Invoice, $contactId, $invoiceDate, $serviceFrom, $serviceTo, $currency, $priceMode, $paymentTermsCode, $address, array_values($lines), $sourceType, $sourceRef, null);
    }

    /**
     * A credit note against a FINAL invoice (plan §6.2): its lines are
     * drafted AS PRINTED (positive amounts), the kind gives the sign. Without
     * `serviceFrom` / `serviceTo` the invoice's service dates apply — the
     * rate of a reduction follows the original supply (ADR-041 decision 4).
     *
     * @param list<LineDraft> $lines
     */
    public static function creditNote(
        int $creditNoteOfId,
        int $contactId,
        \DateTimeImmutable $invoiceDate,
        string $currency,
        array $lines,
        ?\DateTimeImmutable $serviceFrom = null,
        ?\DateTimeImmutable $serviceTo = null,
        PriceMode $priceMode = PriceMode::Net,
        ?string $paymentTermsCode = null,
        ?AddressSnapshot $address = null,
        ?string $sourceType = null,
        ?string $sourceRef = null,
    ): self {
        return new self(InvoiceKind::CreditNote, $contactId, $invoiceDate, $serviceFrom, $serviceTo, $currency, $priceMode, $paymentTermsCode, $address, array_values($lines), $sourceType, $sourceRef, $creditNoteOfId);
    }
}
