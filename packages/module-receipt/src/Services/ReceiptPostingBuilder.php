<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Ledger\GrossEntry;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\GrossPartKind;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The posting of ONE receipt — what «Journal verbuchen» books and what its preview shows. A
 * receipt is one gross part (Soll = the template's account, Haben = the payment method's, optional
 * VAT code): the lines come from financial's {@see GrossEntryBuilder} — the VAT split (gross mode at
 * the rate of the receipt date, net on the P&L side, the tax line on the mandator's VAT account,
 * zero-rated and exempt codes without a tax line) exists there ONCE, the same as the one-line
 * entry's. This class wraps the lines as the GENERATED request(s) of the receipt (source `receipt`,
 * ref = the id).
 *
 *   - A «nur Steuer» receipt (VAT return stage S5, ADR-041 addendum 2026-11-25, point 6) is ONE part
 *     of kind {@see GrossPartKind::TaxOnly}: its amount IS the input tax, the builder derives the
 *     input account from the code (the receipt has no Soll).
 *   - CATCHING UP INPUT TAX (point 9 there): a receipt with an input code dated in a settled VAT
 *     period asks financial's input-tax rule by its VOUCHER date — the date IS the payment date of a
 *     receipt (owner O-1) — `VatSettlement::inputTaxDeferral()`: within the calendar year two entries
 *     (`GrossEntryBuilder::buildSplit()`: the expense at the receipt date, its tax on the transit
 *     account `vat-input-pending`, key `receipt:{id}`; the tax moved to the input account on the
 *     first day of the running period, key `receipt:{id}:vat`); across the year REFUSED with
 *     financial's sentence for «den Beleg». The receipt never computes a date or a period itself,
 *     and never calls `claimableFrom()`. Reverse charge does not reach this rule (a receipt refuses
 *     a reverse-charge code in this stage; its rule is another one, decided by the invoice date).
 *
 * The mandator's VAT liability IS applied (RECEIPT-VAT-001: not liable refuses every tax code). Nothing
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

    /** Behind the receipt's text: the tax entry of a caught-up input tax (board S5-3, part 3). */
    public const TAX_ENTRY_TEXT = ' · Vorsteuer nachgeholt';

    private ?VatSettlement $settlement;

    /**
     * @param VatSettlement|null $settlement the settled VAT periods (one read per instance); null =
     *                                       its own, read on first need. The posting hands the one of
     *                                       its unit of work.
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?VatSettlement $settlement = null)
    {
        $this->settlement = $settlement;
    }

    /**
     * What $receipt books: one entry, or — its input tax caught up — two.
     *
     * @throws ReceiptRefusedException INCOMPLETE (text, an account or the VAT decision missing) | NOT_POSTABLE (the lines cannot be built)
     *                                 | INPUT_TAX_REFUSED (dated in a settled period of ANOTHER calendar year than the running period — F-1)
     */
    public function posting(Receipt $receipt): ReceiptPosting
    {
        $missing = $receipt->missing();
        if ($missing !== []) {
            throw new ReceiptRefusedException(ReceiptRefusedException::INCOMPLETE, self::name($receipt) . ' ist unvollständig — es fehlt: ' . implode(', ', $missing) . '.');
        }
        $date     = $receipt->getReceiptDate();
        $deferral = $this->catchesUpInputTax($receipt) ? $this->settlement()->inputTaxDeferral($date) : null;
        if ($deferral !== null && $deferral->isRefused()) {
            throw new ReceiptRefusedException(ReceiptRefusedException::INPUT_TAX_REFUSED, self::name($receipt) . ': ' . $deferral->refusalText('den Beleg'));
        }
        $request = static fn(\DateTimeImmutable $day, string $text, string $key, array $lines): PostingRequest
            => PostingRequest::generated($day, $text, Receipt::SOURCE_TYPE, (string) $receipt->getId(), $key, $lines);
        try {
            if ($deferral === null || !$deferral->isCatchUp()) {
                return new ReceiptPosting($request($date, $receipt->getText(), $receipt->idempotencyKey(), $this->receiptLines($receipt)));
            }
            $split = $this->split($receipt);
        } catch (ReceiptRefusedException $e) {
            throw new ReceiptRefusedException($e->reason, self::name($receipt) . ': ' . $e->getMessage(), $e);
        }
        $expense = $request($date, $receipt->getText(), $receipt->idempotencyKey(), $split->lines);
        if ($split->taxLines === []) {
            return new ReceiptPosting($expense);   // the tax rounds to 0.00 — nothing to move, posted uncoded
        }
        $text = mb_substr($receipt->getText(), 0, Receipt::TEXT_LENGTH - mb_strlen(self::TAX_ENTRY_TEXT)) . self::TAX_ENTRY_TEXT;

        return new ReceiptPosting($expense, $request($deferral->taxDate, $text, $receipt->taxEntryKey(), $split->taxLines), $deferral);
    }

    /**
     * The lines of one gross amount from $debit to $credit — the ONE entry of a receipt (the shape
     * the one-line entry builds; `tests/financial-gross-entry.php` pins it). $kind TaxOnly: $gross IS
     * the input tax of $taxCode, $debit is ignored (derived from the code).
     *
     * @return list<PostingLine>
     * @throws ReceiptRefusedException NOT_POSTABLE with the builder's German message, named by its field
     */
    public function lines(\DateTimeImmutable $date, Money $gross, string $debit, string $credit, ?string $taxCode, GrossPartKind $kind = GrossPartKind::Standard): array
    {
        try {
            // A mandator NOT liable to VAT refuses every tax code (owner 2026-11-15, RECEIPT-VAT-001 — as the creditor
            // journal does); no mandator yet → not judged here (the posting refuses without one anyway).
            $liable = (new CurrentMandator($this->em))->find()?->isLiableToVat();

            return GrossEntryBuilder::of($this->em)->build($date, $credit, [new GrossPart($debit, $gross, $taxCode, kind: $kind)], $liable)->lines;
        } catch (GrossEntryRefusedException $e) {
            throw self::refused($e);
        }
    }

    /** «Beleg 17 vom 03.11.2026» — how every message names a receipt. */
    public static function name(Receipt $receipt): string
    {
        return 'Beleg ' . $receipt->getId() . ' vom ' . $receipt->getReceiptDate()->format('d.m.Y');
    }

    /** @return list<PostingLine> */
    private function receiptLines(Receipt $receipt): array
    {
        return $this->lines($receipt->getReceiptDate(), $receipt->getAmount(), (string) $receipt->getDebitAccount(), (string) $receipt->getCreditAccount(), $receipt->getTaxCode(), $receipt->getLineKind());
    }

    /**
     * The two entries of a caught-up input tax (`GrossEntryBuilder::buildSplit()`, the rate of the
     * receipt date — the voucher's).
     *
     * @throws ReceiptRefusedException NOT_POSTABLE
     */
    private function split(Receipt $receipt): GrossEntry
    {
        try {
            $liable = (new CurrentMandator($this->em))->find()?->isLiableToVat();
            $part   = new GrossPart((string) $receipt->getDebitAccount(), $receipt->getAmount(), $receipt->getTaxCode(), kind: $receipt->getLineKind());

            return GrossEntryBuilder::of($this->em)->buildSplit($receipt->getReceiptDate(), (string) $receipt->getCreditAccount(), [$part], $liable, [], null, false);
        } catch (GrossEntryRefusedException $e) {
            throw self::refused($e);
        }
    }

    /**
     * Whether the input-tax rule is asked for $receipt: an INPUT code (VM, VI and their own codes,
     * tax-only included) — never «ohne MWST», an output, zero-rated or exempt code (no input tax to
     * catch up), never reverse charge (its own rule; refused on a receipt in this stage).
     */
    public function catchesUpInputTax(Receipt $receipt): bool
    {
        $code = $receipt->getTaxCode();
        if ($code === null || $receipt->isWithoutVat()) {
            return false;
        }
        $category = $this->em->getRepository(TaxCode::class)->findByCode($code)?->category();

        return $category !== null && $category->isDebitNatural() && $category !== TaxCategory::ReverseCharge && !$category->isManualOnly();
    }

    private function settlement(): VatSettlement
    {
        return $this->settlement ??= new VatSettlement($this->em);
    }

    private static function refused(GrossEntryRefusedException $e): ReceiptRefusedException
    {
        $name = self::FIELDS[$e->field] ?? null;

        return new ReceiptRefusedException(ReceiptRefusedException::NOT_POSTABLE, ($name !== null ? $name . ': ' : '') . $e->getMessage(), $e);
    }
}
