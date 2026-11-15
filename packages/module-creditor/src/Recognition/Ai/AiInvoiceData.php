<?php

namespace Z77\Module\Creditor\Recognition\Ai;

use Z77\Module\Creditor\Recognition\RecognizedPayment;
use Z77\Shared\Money\Money;

/**
 * What the AI read from a supplier's invoice — validated values only, like the text layer's.
 *
 * The payment data travel in the text layer's own {@see RecognizedPayment} so the invoice
 * service treats both sources alike: IBAN, currency, the GROSS TOTAL as `amount`, reference,
 * creditor name and address. Its fields that only a printed QR slip has stay empty here
 * (`debtorName`, `message`, `billing` = null, `notForPayment` = false; `occurrences` = 1 when an
 * IBAN was taken, else 0) — so an AI result is never «high» confidence.
 *
 * What `RecognizedPayment` has no field for lives here instead of in a Swico S1 object (whose
 * constructor only parses a printed S1 string): the Swiss UID, invoice number, invoice date,
 * due date, the printed VAT lines, the creditor's country, a short German line text and an
 * expense category in plain words. The category is a PROPOSAL in words, never an account —
 * mapping it to the chart is the invoice service's job (it re-checks account and tax code).
 *
 * {@see $warnings} (German) repeats the payment's warnings plus the ones of the fields here:
 * every value the AI delivered that failed its check was dropped and named there.
 */
final class AiInvoiceData
{
    /**
     * @param list<array{rate: string, net: ?Money, vat: ?Money}> $vatLines rate in percent («8.1»)
     * @param list<string> $warnings German, for the row
     */
    public function __construct(
        public readonly RecognizedPayment $payment,
        public readonly ?string $creditorCountry,          // ISO 3166 alpha-2
        public readonly ?string $uid,                      // CHE-ddd.ddd.ddd, check digit valid
        public readonly ?string $invoiceNumber,
        public readonly ?\DateTimeImmutable $invoiceDate,
        public readonly ?\DateTimeImmutable $dueDate,
        public readonly array $vatLines,
        public readonly ?string $lineText,                 // short German booking text
        public readonly ?string $expenseCategory,          // plain words, e.g. «Büromaterial»
        public readonly string $model,                     // the model that answered
        public readonly array $warnings,
    ) {}

    /** The gross total of the invoice (the payment's amount). */
    public function grossTotal(): ?Money
    {
        return $this->payment->amount;
    }

    /** At least one field the invoice row can take over. */
    public function hasAnyValue(): bool
    {
        $p = $this->payment;

        return $p->iban !== null || $p->amount !== null || $p->creditorName !== null || $p->reference !== null
            || $this->uid !== null || $this->invoiceNumber !== null || $this->invoiceDate !== null
            || $this->dueDate !== null || $this->vatLines !== [] || $this->lineText !== null
            || $this->expenseCategory !== null;
    }
}
