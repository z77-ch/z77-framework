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
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The DIRECT variant (owner 2026-11-15, creditor plan §2.4, ADR-059): a supplier invoice is booked
 * only when it is PAID — ONE generated entry per invoice, dated with the payment date «bezahlt am»:
 * per invoice line its gross on the line's account (net + input VAT on the mandator's input-tax
 * account when it carries a code), against ONE credit line, the payment method's account
 * (`credit_account`). The cash principle: an invoice paid in the new year is an expense of the
 * new year; an unpaid invoice is no liability in the books.
 *
 * The lines come from financial's {@see GrossEntryBuilder} (the VAT split exists there once —
 * rounding per part, the rate of the payment date). The mandator's VAT liability is applied
 * STRICTLY (owner answer 5): not liable → every tax code is refused, the line must be «ohne MWST».
 *
 * The request: source `creditor`, ref = the invoice id, key `creditor:{id}`, text «Rechnung … ·
 * Kreditor». Nothing here posts.
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

    public function paymentPosting(CreditorInvoice $invoice, array $lines): ?PostingRequest
    {
        $paidOn = $invoice->getPaidOn();
        $credit = $invoice->getCreditAccount();
        if ($paidOn === null || $credit === null || $lines === []) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . ($paidOn === null ? 'nicht bezahlt — zuerst «Bezahlt am …».' : 'es fehlt: ' . ($credit === null ? 'Bezahlt mit' : 'Konto') . '.'));
        }
        $parts = [];
        foreach ($lines as $line) {
            $amount = $line->getAmount();
            if ($amount === null || $line->getAccount() === null) {
                throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': Zeile ' . ($line->getPosition() + 1) . ' ohne Konto oder Betrag.');
            }
            $parts[] = new GrossPart($line->getAccount(), $amount, $line->getTaxCode(), $line->getText() !== '' ? $line->getText() : null);
        }
        try {
            $entry = GrossEntryBuilder::of($this->em)->build($paidOn, $credit, $parts, (new CreditorProfileService($this->em))->liableToVat());
        } catch (GrossEntryRefusedException $e) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . self::field($e) . $e->getMessage(), $e);
        }

        return PostingRequest::generated($paidOn, self::text($invoice), CreditorInvoice::SOURCE_TYPE, (string) $invoice->getId(), $invoice->idempotencyKey(), $entry->lines);
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
