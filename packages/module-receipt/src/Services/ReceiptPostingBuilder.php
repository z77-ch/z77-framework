<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Ledger\GrossEntry;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\GrossPartKind;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\VatReturn\ReverseChargeTiming;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\VatSettings;
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
 *     and never calls `claimableFrom()`.
 *   - REVERSE CHARGE (stage S6, ADR-041 addendum 2026-11-25, point 10 and b): a receipt with a
 *     reverse-charge code is ALWAYS ONE entry at the receipt date — `GrossEntryBuilder::build(…,
 *     reverseCharge: true)`, base = the amount (a receipt names no currency), the pair of tax lines
 *     computed by financial. Its date is decided by financial's OWN reverse-charge rule,
 *     `ReverseChargeTiming::forInvoiceDate()` with the receipt date — NEVER by the input-tax rule
 *     (owner O-4: the two rules stay apart; {@see reverseChargePosting()} names neither
 *     `inputTaxDeferral()` nor `claimableFrom()`): dated in a POSTED period → refused with
 *     financial's sentence for «den Beleg», never caught up through 1179 (O-3); before «Abrechnung
 *     ab» → built as is, the ledger's period check gives the existing «ohne MWST» sentence; after
 *     «Abrechnung bis» → posted WITHOUT the reverse-charge tax (owner D-4: z77 settles nothing
 *     there), the window shows a grey hint.
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

    private ?ReverseChargeTiming $reverseChargeTiming = null;

    private ?VatSettings $vatSettings = null;

    private bool $vatSettingsRead = false;

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
     *                                 | REVERSE_CHARGE_REFUSED (a reverse-charge code, dated in a POSTED VAT period — O-3)
     */
    public function posting(Receipt $receipt): ReceiptPosting
    {
        $missing = $receipt->missing();
        if ($missing !== []) {
            throw new ReceiptRefusedException(ReceiptRefusedException::INCOMPLETE, self::name($receipt) . ' ist unvollständig — es fehlt: ' . implode(', ', $missing) . '.');
        }
        if ($this->chargesReverse($receipt)) {
            return $this->reverseChargePosting($receipt);
        }
        $date     = $receipt->getReceiptDate();
        $deferral = $this->catchesUpInputTax($receipt) ? $this->settlement()->inputTaxDeferral($date) : null;
        if ($deferral !== null && $deferral->isRefused()) {
            throw new ReceiptRefusedException(ReceiptRefusedException::INPUT_TAX_REFUSED, self::name($receipt) . ': ' . $deferral->refusalText('den Beleg'));
        }
        try {
            if ($deferral === null || !$deferral->isCatchUp()) {
                return new ReceiptPosting(self::request($receipt, $date, $receipt->getText(), $receipt->idempotencyKey(), $this->receiptLines($receipt)));
            }
            $split = $this->split($receipt);
        } catch (ReceiptRefusedException $e) {
            throw new ReceiptRefusedException($e->reason, self::name($receipt) . ': ' . $e->getMessage(), $e);
        }
        $expense = self::request($receipt, $date, $receipt->getText(), $receipt->idempotencyKey(), $split->lines);
        if ($split->taxLines === []) {
            return new ReceiptPosting($expense);   // the tax rounds to 0.00 — nothing to move, posted uncoded
        }
        $text = mb_substr($receipt->getText(), 0, Receipt::TEXT_LENGTH - mb_strlen(self::TAX_ENTRY_TEXT)) . self::TAX_ENTRY_TEXT;

        return new ReceiptPosting($expense, self::request($receipt, $deferral->taxDate, $text, $receipt->taxEntryKey(), $split->taxLines), $deferral);
    }

    /**
     * The lines of one gross amount from $debit to $credit — the ONE entry of a receipt (the shape
     * the one-line entry builds; `tests/financial-gross-entry.php` pins it). $kind TaxOnly: $gross IS
     * the input tax of $taxCode, $debit is ignored (derived from the code). $reverseCharge: a
     * reverse-charge $taxCode is taken — $gross is the base, the pair of tax lines on top.
     *
     * @return list<PostingLine>
     * @throws ReceiptRefusedException NOT_POSTABLE with the builder's German message, named by its field
     */
    public function lines(\DateTimeImmutable $date, Money $gross, string $debit, string $credit, ?string $taxCode, GrossPartKind $kind = GrossPartKind::Standard, bool $reverseCharge = false): array
    {
        try {
            // A mandator NOT liable to VAT refuses every tax code (owner 2026-11-15, RECEIPT-VAT-001 — as the creditor
            // journal does); no mandator yet → not judged here (the posting refuses without one anyway).
            $liable = (new CurrentMandator($this->em))->find()?->isLiableToVat();

            return GrossEntryBuilder::of($this->em)->build($date, $credit, [new GrossPart($debit, $gross, $taxCode, kind: $kind)], $liable, [], null, $reverseCharge)->lines;
        } catch (GrossEntryRefusedException $e) {
            throw self::refused($e);
        }
    }

    /** «Beleg 17 vom 03.11.2026» — how every message names a receipt. */
    public static function name(Receipt $receipt): string
    {
        return 'Beleg ' . $receipt->getId() . ' vom ' . $receipt->getReceiptDate()->format('d.m.Y');
    }

    /** @param list<PostingLine> $lines */
    private static function request(Receipt $receipt, \DateTimeImmutable $day, string $text, string $key, array $lines): PostingRequest
    {
        return PostingRequest::generated($day, $text, Receipt::SOURCE_TYPE, (string) $receipt->getId(), $key, $lines);
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
     * The ONE entry of a reverse-charge receipt — its date decided by financial's reverse-charge rule
     * alone (`ReverseChargeTiming::forInvoiceDate()`, the receipt date is the invoice date of a slip
     * paid on the spot). This method shares nothing with the input-tax path above (owner O-4):
     *
     *   - `refused` (the receipt date lies in a POSTED VAT period, same calendar year or not, O-3) →
     *     REVERSE_CHARGE_REFUSED with financial's sentence for «den Beleg»; nothing goes through 1179;
     *   - `none` AFTER «Abrechnung bis» (owner D-4) → the receipt is posted WITHOUT reverse-charge tax
     *     (Dr Soll the amount / Cr Haben), {@see ReceiptPosting::$reverseChargeAfterEnd} set — the
     *     window names it; any other `none` (no «Abrechnung ab», not liable) → the ordinary rules;
     *   - `post-at`, `before-start` → `build(…, reverseCharge: true)` at the receipt date (the tax
     *     date IS the receipt date — never moved); before «Abrechnung ab» the ledger's period check
     *     refuses the tax lines with its existing sentence (the «ohne MWST» way out of the run).
     *
     * @throws ReceiptRefusedException REVERSE_CHARGE_REFUSED | NOT_POSTABLE
     */
    private function reverseChargePosting(Receipt $receipt): ReceiptPosting
    {
        $date   = $receipt->getReceiptDate();
        $answer = $this->reverseChargeTiming()->forInvoiceDate($date);
        if ($answer->isRefused()) {
            throw new ReceiptRefusedException(ReceiptRefusedException::REVERSE_CHARGE_REFUSED, self::name($receipt) . ': ' . $answer->refusalText('den Beleg'));
        }
        // After «Abrechnung bis» the rule does not apply: z77 posts no reverse-charge tax and requires none (D-4).
        $afterEnd = $answer->isAfterEnd();
        try {
            $lines = $this->lines($date, $receipt->getAmount(), (string) $receipt->getDebitAccount(), (string) $receipt->getCreditAccount(),
                $afterEnd ? null : $receipt->getTaxCode(), GrossPartKind::Standard, !$afterEnd);
        } catch (ReceiptRefusedException $e) {
            throw new ReceiptRefusedException($e->reason, self::name($receipt) . ': ' . $e->getMessage(), $e);
        }

        return new ReceiptPosting(self::request($receipt, $date, $receipt->getText(), $receipt->idempotencyKey(), $lines), reverseChargeAfterEnd: $afterEnd);
    }

    /**
     * What the receipt window shows under «MWST-Code» for a reverse-charge receipt (board S6-2, part
     * 4: «Basis 21.60 · Bezugsteuer 8.1 % = 1.75») — read off the coded line the posting builds at the
     * receipt date, never computed here. Null without a reverse-charge code, or while the lines cannot
     * be built (an account missing, no VAT account on the mandator — the run says why).
     *
     * @return array{base: Money, rate: int, tax: Money}|null
     */
    public function reverseChargeTax(Receipt $receipt): ?array
    {
        if (!$this->chargesReverse($receipt) || $receipt->getDebitAccount() === null || $receipt->getCreditAccount() === null) {
            return null;
        }
        try {
            $lines = $this->lines($receipt->getReceiptDate(), $receipt->getAmount(), $receipt->getDebitAccount(), $receipt->getCreditAccount(), $receipt->getTaxCode(), GrossPartKind::Standard, true);
        } catch (ReceiptRefusedException) {
            return null;
        }
        foreach ($lines as $line) {
            if ($line->taxCode !== null && $line->taxBase !== null && $line->taxAmount !== null) {
                return ['base' => $line->taxBase, 'rate' => (int) $line->taxRate, 'tax' => $line->taxAmount];
            }
        }

        return null;
    }

    /**
     * Whether $receipt carries a REVERSE-CHARGE code (BM, BI, BM2, BI2 or an own code of that
     * category) — its posting then follows {@see reverseChargePosting()}, never the input-tax rule.
     */
    public function chargesReverse(Receipt $receipt): bool
    {
        $code = $receipt->getTaxCode();
        if ($code === null || $receipt->isWithoutVat()) {
            return false;
        }

        return $this->em->getRepository(TaxCode::class)->findByCode($code)?->category() === TaxCategory::ReverseCharge;
    }

    /**
     * Whether the input-tax rule is asked for $receipt: an INPUT code (VM, VI and their own codes,
     * tax-only included) — never «ohne MWST», an output, zero-rated or exempt code (no input tax to
     * catch up), never reverse charge (its own rule, {@see chargesReverse()}).
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

    private function reverseChargeTiming(): ReverseChargeTiming
    {
        return $this->reverseChargeTiming ??= new ReverseChargeTiming($this->em, $this->vatSettings());
    }

    /** The mandator's VAT settings, read once (null: no readable record — the rule then answers `none`). */
    private function vatSettings(): ?VatSettings
    {
        if (!$this->vatSettingsRead) {
            $this->vatSettings     = (new LedgerService($this->em))->vatSettings();
            $this->vatSettingsRead = true;
        }

        return $this->vatSettings;
    }

    private static function refused(GrossEntryRefusedException $e): ReceiptRefusedException
    {
        $name = self::FIELDS[$e->field] ?? null;

        return new ReceiptRefusedException(ReceiptRefusedException::NOT_POSTABLE, ($name !== null ? $name . ': ' : '') . $e->getMessage(), $e);
    }
}
