<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The posting of ONE receipt — what «Journal verbuchen» books and what its preview shows. A
 * receipt is one gross amount (Soll = the template's account, Haben = the payment method's,
 * optional VAT code): the lines come from financial's {@see GrossEntryBuilder} — the VAT split
 * (gross mode at the rate of the receipt date, net on the P&L side, the tax line on the mandator's
 * VAT account, zero-rated and exempt codes without a tax line) exists there ONCE, the same as the
 * one-line entry's. This class wraps the lines as the GENERATED request of the receipt (source
 * `receipt`, ref = the id, key `receipt:{id}`).
 *
 * The mandator's VAT liability is NOT applied here (RECEIPT-VAT-001: a code posts input tax even
 * when the mandator is not liable) — the builder's strict mode is creditor's for now. Nothing
 * here posts.
 */
final class ReceiptPostingBuilder
{
    /** The builder's refusal field → its German name in a message («Soll: …»). */
    private const FIELDS = [
        GrossEntryRefusedException::ACCOUNT    => 'Soll',
        GrossEntryRefusedException::COUNTER    => 'Haben',
        GrossEntryRefusedException::AMOUNT     => 'Betrag',
        GrossEntryRefusedException::TAX_CODE   => 'MWST',
        GrossEntryRefusedException::TAX_AMOUNT => 'Steuerbetrag',
    ];

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * The generated posting of $receipt.
     *
     * @throws ReceiptRefusedException INCOMPLETE (text, an account or the VAT decision missing) | NOT_POSTABLE (the lines cannot be built)
     */
    public function build(Receipt $receipt): PostingRequest
    {
        $missing = $receipt->missing();
        if ($missing !== []) {
            throw new ReceiptRefusedException(ReceiptRefusedException::INCOMPLETE, self::name($receipt) . ' ist unvollständig — es fehlt: ' . implode(', ', $missing) . '.');
        }
        try {
            $lines = $this->lines($receipt->getReceiptDate(), $receipt->getAmount(), (string) $receipt->getDebitAccount(), (string) $receipt->getCreditAccount(), $receipt->getTaxCode());
        } catch (ReceiptRefusedException $e) {
            throw new ReceiptRefusedException($e->reason, self::name($receipt) . ': ' . $e->getMessage(), $e);
        }

        return PostingRequest::generated(
            $receipt->getReceiptDate(),
            $receipt->getText(),
            Receipt::SOURCE_TYPE,
            (string) $receipt->getId(),
            $receipt->idempotencyKey(),
            $lines,
        );
    }

    /**
     * The lines of one gross amount from $debit to $credit — what {@see build()} wraps.
     *
     * @return list<PostingLine>
     * @throws ReceiptRefusedException NOT_POSTABLE with the builder's German message, named by its field
     */
    public function lines(\DateTimeImmutable $date, Money $gross, string $debit, string $credit, ?string $taxCode): array
    {
        try {
            return GrossEntryBuilder::of($this->em)->build($date, $credit, [new GrossPart($debit, $gross, $taxCode)])->lines;
        } catch (GrossEntryRefusedException $e) {
            $name = self::FIELDS[$e->field] ?? null;

            throw new ReceiptRefusedException(ReceiptRefusedException::NOT_POSTABLE, ($name !== null ? $name . ': ' : '') . $e->getMessage(), $e);
        }
    }

    /** «Beleg 17 vom 03.11.2026» — how every message names a receipt. */
    public static function name(Receipt $receipt): string
    {
        return 'Beleg ' . $receipt->getId() . ' vom ' . $receipt->getReceiptDate()->format('d.m.Y');
    }
}
