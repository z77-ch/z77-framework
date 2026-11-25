<?php

namespace Z77\Module\Creditor\Posting;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Services\CreditorProfileService;
use Z77\Module\Creditor\Services\CreditorRefusedException;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\VatReturn\InputTaxDeferral;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The DIRECT variant (owner 2026-11-15, creditor plan §2.4, ADR-059): a supplier invoice is booked
 * only when it is PAID — ONE generated entry per invoice, dated with the payment date «bezahlt am»:
 * per invoice line its gross on the line's account (net + input VAT on the mandator's input-tax
 * account when it carries a code; a «nur Steuer» line its amount on the input account its code
 * maps to), against ONE credit line, the payment method's account (`credit_account`). The cash
 * principle: an invoice paid in the new year is an expense of the new year; an unpaid invoice is
 * no liability in the books. A SECOND entry only when its input tax is caught up
 * ({@see paymentPosting()}, ADR-059 addendum 2026-11-25).
 *
 * The lines come from financial's {@see GrossEntryBuilder} (the VAT split exists there once —
 * rounding per part, the rate of the invoice date). The mandator's VAT liability is applied
 * STRICTLY (owner answer 5): not liable → every tax code is refused, the line must be «ohne MWST».
 *
 * The requests: source `creditor`, ref = the invoice id, key `creditor:{id}` (expense) and
 * `creditor:{id}:vat` (tax entry), text «Rechnung … · Kreditor». Nothing here posts.
 */
final class DirectPosting implements CreditorPostingStrategy
{
    public const KEY = CreditorInvoice::VARIANT_DIRECT;

    public function __construct(private readonly UnifiedEntityManager $em) {}

    public function key(): string
    {
        return self::KEY;
    }

    /** Direct: the arrival of an invoice books nothing. */
    public function invoicePosting(CreditorInvoice $invoice, array $lines): ?PostingRequest
    {
        return null;
    }

    /**
     * The expense entry at «bezahlt am», per line a {@see GrossPart} — a tax-only line («nur Steuer»)
     * as {@see GrossPartKind::TaxOnly} (its amount is the input tax, the account derived from the
     * code). The rate is that of the RATE DATE — the invoice date, empty: the payment date (S56-5,
     * ADR-041 addendum 2026-11-25 point 7); the posting stays on the payment date.
     *
     * Input tax of an invoice paid in a SETTLED VAT period goes through financial's ONE input-tax
     * rule, `VatSettlement::inputTaxDeferral()` (decided by the PAYMENT date, O-1 — never used for
     * reverse charge): within the calendar year the expense is posted without a code with its tax on
     * the transit account, and a second entry (`creditor:{id}:vat`) moves the tax to the input
     * account on the first day of the running period (`GrossEntryBuilder::buildSplit()`); across the
     * year the invoice is refused with the owner's sentence (F-1) — «ohne MWST» and a correction
     * entry by hand.
     */
    public function paymentPosting(CreditorInvoice $invoice, array $lines): ?PaymentEntries
    {
        $paidOn = $invoice->getPaidOn();
        $credit = $invoice->getCreditAccount();
        if ($paidOn === null || $credit === null || $lines === []) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . ($paidOn === null ? 'nicht bezahlt — zuerst «Bezahlt am …».' : 'es fehlt: ' . ($credit === null ? 'Bezahlt mit' : 'Konto') . '.'));
        }
        $parts = [];
        $coded = false;
        foreach ($lines as $line) {
            $amount = $line->getAmount();
            if ($amount === null || ($line->getAccount() === null && !$line->isTaxOnly())) {
                throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': Zeile ' . ($line->getPosition() + 1) . ' ohne Konto oder Betrag.');
            }
            $parts[] = new GrossPart((string) $line->getAccount(), $amount, $line->getTaxCode(), $line->getText() !== '' ? $line->getText() : null, null, $line->getKind());
            $coded   = $coded || $line->getTaxCode() !== null;
        }
        $rateDate = $invoice->getInvoiceDate() ?? $paidOn;
        $liable   = (new CreditorProfileService($this->em))->liableToVat();
        $deferral = $coded ? (new VatSettlement($this->em))->inputTaxDeferral($paidOn) : InputTaxDeferral::none();
        if ($deferral->isRefused()) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . $deferral->refusalText());
        }
        try {
            $builder = GrossEntryBuilder::of($this->em);
            $entry   = $deferral->isCatchUp()
                ? $builder->buildSplit($paidOn, $credit, $parts, $liable, [], $rateDate)
                : $builder->build($paidOn, $credit, $parts, $liable, [], $rateDate);
        } catch (GrossEntryRefusedException $e) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . self::field($e) . $e->getMessage(), $e);
        }
        $expense = PostingRequest::generated($paidOn, self::text($invoice), CreditorInvoice::SOURCE_TYPE, (string) $invoice->getId(), $invoice->idempotencyKey(), $entry->lines);
        if ($entry->taxLines === [] || $deferral->taxDate === null) {
            return new PaymentEntries($expense);   // nothing to move (a tax of 0.00) — the one entry
        }
        $moved = null;
        foreach ($entry->taxLines as $taxLine) {
            if ($taxLine->taxAmount !== null) {
                $moved = $moved === null ? $taxLine->taxAmount : $moved->add($taxLine->taxAmount);
            }
        }
        $tax = PostingRequest::generated($deferral->taxDate, self::taxText($invoice), CreditorInvoice::SOURCE_TYPE, (string) $invoice->getId(), $invoice->taxIdempotencyKey(), $entry->taxLines);

        return new PaymentEntries($expense, $tax, $deferral->settledPeriod, $moved ?? Money::zero($expense->currency()));
    }

    /** «Rechnung 2608-117 · Druckerei Beispiel AG · Vorsteuer nachgeholt» — the tax entry's text (board S5-3). */
    public static function taxText(CreditorInvoice $invoice): string
    {
        $suffix = ' · Vorsteuer nachgeholt';

        return mb_substr(self::text($invoice), 0, CreditorInvoiceLine::TEXT_LENGTH - mb_strlen($suffix)) . $suffix;
    }

    /**
     * «Rechnung A-1 · Neulieferant GmbH» — the entry's text (at most the journal's 255 characters).
     * A foreign-currency invoice (stage A0) ends with its printed amount, «… · 9.00 USD» (receipt FX
     * plan T7 without a rate — F12); the front is cut, never the suffix.
     */
    public static function text(CreditorInvoice $invoice): string
    {
        $creditor = $invoice->getCreditor()?->getContact()?->displayName();
        $suffix   = $invoice->originalLabel() !== null ? ' · ' . $invoice->originalLabel() : '';

        return mb_substr($invoice->label() . ($creditor !== null && $creditor !== '' ? ' · ' . $creditor : ''), 0, CreditorInvoiceLine::TEXT_LENGTH - mb_strlen($suffix)) . $suffix;
    }

    /** «Rechnung A-1 (Neulieferant GmbH)» — how every run message names an invoice. */
    public static function name(CreditorInvoice $invoice): string
    {
        $creditor = $invoice->getCreditor()?->getContact()?->displayName();

        return $invoice->label() . ($creditor !== null && $creditor !== '' ? ' (' . $creditor . ')' : '');
    }

    /** The builder's field → its German name in a message («Zeile 2, MWST: …»). */
    private static function field(GrossEntryRefusedException $e): string
    {
        $line = $e->part !== null ? 'Zeile ' . ($e->part + 1) . ', ' : '';

        return match ($e->field) {
            GrossEntryRefusedException::ACCOUNT    => $line . 'Konto: ',
            GrossEntryRefusedException::COUNTER    => 'Haben (Bezahlt mit): ',
            GrossEntryRefusedException::AMOUNT     => $line . 'Betrag: ',
            GrossEntryRefusedException::TAX_CODE   => $line . 'MWST: ',
            GrossEntryRefusedException::TAX_AMOUNT => $line . 'Steuerbetrag: ',
            default                                => $line,
        };
    }
}
