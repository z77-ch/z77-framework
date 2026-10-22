<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Shared\Money\Money;

/**
 * Everything an issued document FROZE at the moment of issue, apart from
 * its lines and its tax summary (plan §6.2, §4a; ADR-041 decision 6):
 * the address it shows, the language it is written in, its dates, the
 * payment terms AS APPLIED (due date, discount tiers with their dates, the
 * printed sentence), the currency, the totals and the opaque origin.
 *
 * Built by `InvoicingService` from the draft and the master data, handed to
 * `Invoice::issue()` / `reissue()` as one value — the one place the header
 * snapshot is written, so a re-issue replaces it whole. Immutable.
 */
final class DocumentSnapshot
{
    /**
     * @param list<array{days: int, percent: int, until: string}> $discountTiers the terms' tiers with the
     *        last day each applies (`Y-m-d`), as printed — empty when the terms carry none
     */
    public function __construct(
        public readonly AddressSnapshot $address,
        public readonly string $language,
        public readonly \DateTimeImmutable $invoiceDate,
        public readonly \DateTimeImmutable $serviceFrom,
        public readonly ?\DateTimeImmutable $serviceTo,
        public readonly string $currency,
        public readonly PriceMode $priceMode,
        public readonly string $paymentTermsCode,
        public readonly \DateTimeImmutable $dueDate,
        public readonly array $discountTiers,
        public readonly string $termsText,
        public readonly Money $netTotal,
        public readonly Money $taxTotal,
        public readonly Money $rounding,
        public readonly Money $grossTotal,
        public readonly ?string $sourceType,
        public readonly ?string $sourceRef,
        /** The optional «Betreff» printed under the title (P7 slim, 2026-10-14); null = none. */
        public readonly ?string $subject = null,
        /** The optional «Betreffname» printed in front of the subject (owner 2026-10-21, {@see SubjectLabel}); null = none. */
        public readonly ?string $subjectLabel = null,
        /**
         * The note a document WITHOUT VAT prints under its totals (owner 2026-10-22) — the mandator's
         * «Hinweis ohne MWST» as it read at composing (`Mandator::documentVatNote()`); null for a
         * document computed with VAT or a mandator without a text. Snapshotted like the rest: a
         * reprint reads as issued (ADR-041 decision 6).
         */
        public readonly ?string $vatNote = null,
    ) {
        foreach ([$netTotal, $taxTotal, $rounding, $grossTotal] as $amount) {
            if ($amount->currency !== $currency) {
                throw new \InvalidArgumentException("Document snapshot: total in {$amount->currency}, document in {$currency}");
            }
        }
        if (!$netTotal->add($taxTotal)->add($rounding)->equals($grossTotal)) {
            throw new \InvalidArgumentException("Document snapshot: net {$netTotal} + tax {$taxTotal} + rounding {$rounding} is not the gross {$grossTotal}");
        }
    }
}
