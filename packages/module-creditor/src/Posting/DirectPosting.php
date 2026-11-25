<?php

namespace Z77\Module\Creditor\Posting;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Services\CreditorProfileService;
use Z77\Module\Creditor\Services\CreditorRefusedException;
use Z77\Module\Financial\Ledger\GrossEntry;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\VatReturn\InputTaxDeferral;
use Z77\Module\Financial\VatReturn\ReverseChargeDate;
use Z77\Module\Financial\VatReturn\ReverseChargeTiming;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The DIRECT variant (owner 2026-11-15, creditor plan §2.4, ADR-059): a supplier invoice is booked
 * when it is PAID — the expense entry dated with the payment date «bezahlt am»: per invoice line its
 * gross on the line's account (net + input VAT on the mandator's input-tax account when it carries a
 * code; a «nur Steuer» line its amount on the input account its code maps to), against ONE credit
 * line, the payment method's account (`credit_account`). The cash principle: an invoice paid in the
 * new year is an expense of the new year; an unpaid invoice is no liability in the books. A SECOND
 * entry, the tax entry (ADR-059 addendum 2026-11-25), when input tax is caught up or a reverse-charge
 * pair is dated apart from the payment.
 *
 * The lines come from financial's {@see GrossEntryBuilder} (the VAT split exists there once —
 * rounding per part, the rate of the invoice date). The mandator's VAT liability is applied
 * STRICTLY (owner answer 5): not liable → every tax code is refused, the line must be «ohne MWST».
 *
 * TWO DATE RULES, kept apart (owner O-4 — never one shared date check):
 *
 *   - INPUT TAX (VM, VI, «nur Steuer»): financial's `VatSettlement::inputTaxDeferral()`, decided by the
 *     PAYMENT date (O-1) — asked only when a line carries an input code, never for a reverse-charge
 *     line;
 *   - REVERSE CHARGE (BM, BI, BM2, BI2 — {@see ReverseChargeCodes}): financial's
 *     `ReverseChargeTiming::forInvoiceDate()`, decided by the INVOICE date (O-2): the tax date is
 *     ALWAYS the invoice date; an invoice date in a POSTED VAT period is refused with the owner's
 *     sentence (O-3, O-8 — the correction path, nothing caught up); after «Abrechnung bis» no
 *     reverse-charge tax (D-4).
 *
 * The requests: source `creditor`, ref = the invoice id, key `creditor:{id}` (expense) and
 * `creditor:{id}:vat` (tax entry), text «Rechnung … · Kreditor». Nothing here posts.
 */
final class DirectPosting implements CreditorPostingStrategy
{
    public const KEY = CreditorInvoice::VARIANT_DIRECT;

    private ?ReverseChargeCodes $reverseChargeCodes = null;

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
     * rule, `VatSettlement::inputTaxDeferral()` (decided by the PAYMENT date, O-1 — never asked for a
     * reverse-charge line): within the calendar year the expense is posted without a code with its
     * tax on the transit account, and a second entry (`creditor:{id}:vat`) moves the tax to the input
     * account on the first day of the running period (`GrossEntryBuilder::buildSplit()`); across the
     * year the invoice is refused with the owner's sentence (F-1).
     *
     * Reverse-charge lines (ADR-041 addendum 2026-11-25 point 10, ADR-059 addendum 2026-11-25): the
     * tax date T is the INVOICE date ({@see reverseChargeDate()}):
     *
     *   - the pair was posted BEFORE the payment (`tax_entry_ref` set — the run took the unpaid
     *     invoice, {@see reverseChargeEntry()}) → only the expense, the reverse-charge lines without a
     *     code;
     *   - T = «bezahlt am» → ONE entry in the ADR shape: Dr expense [code, base, tax] · Dr input · Cr
     *     owed · Cr payment (`build(…, reverseCharge: true)`);
     *   - T ≠ «bezahlt am» → the expense without the code at «bezahlt am» and the pair alone at T;
     *   - input tax caught up as well: on the same day ONE tax entry with both, on different days the
     *     invoice is refused — capture it as two invoices (R6);
     *   - after «Abrechnung bis» → the reverse-charge lines without a code, no tax (D-4).
     */
    public function paymentPosting(CreditorInvoice $invoice, array $lines): ?PaymentEntries
    {
        $paidOn = $invoice->getPaidOn();
        $credit = $invoice->getCreditAccount();
        if ($paidOn === null || $credit === null || $lines === []) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . ($paidOn === null ? 'nicht bezahlt — zuerst «Bezahlt am …».' : 'es fehlt: ' . ($credit === null ? 'Bezahlt mit' : 'Konto') . '.'));
        }
        $reverse = [];   // index of each reverse-charge line in $lines
        $coded   = false;
        foreach (array_values($lines) as $i => $line) {
            if ($line->getAmount() === null || ($line->getAccount() === null && !$line->isTaxOnly())) {
                throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': Zeile ' . ($line->getPosition() + 1) . ' ohne Konto oder Betrag.');
            }
            if ($this->codes()->isReverseCharge($line->getTaxCode())) {
                $reverse[] = $i;
            } else {
                $coded = $coded || $line->getTaxCode() !== null;
            }
        }
        $lines    = array_values($lines);
        $rateDate = $invoice->getInvoiceDate() ?? $paidOn;
        $liable   = (new CreditorProfileService($this->em))->liableToVat();
        // The input-tax rule — asked only for input codes, never for a reverse-charge line (O-4).
        $deferral = $coded ? (new VatSettlement($this->em))->inputTaxDeferral($paidOn) : InputTaxDeferral::none();
        if ($deferral->isRefused()) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . $deferral->refusalText());
        }

        // The reverse-charge date — its OWN rule, by the invoice date (O-2).
        $taxDate  = null;     // T: the day of the pair; null = no pair (none, or posted before the payment, or after «Abrechnung bis»)
        $afterEnd = false;
        if ($reverse !== [] && $invoice->getTaxEntryRef() === null) {
            $taxDate  = $this->reverseChargeDate($invoice);
            $afterEnd = $taxDate === null;
        }
        if ($reverse !== [] && $deferral->isCatchUp() && ($invoice->getTaxEntryRef() !== null || ($taxDate !== null && $taxDate->format('Y-m-d') !== $deferral->taxDate?->format('Y-m-d')))) {
            // R6: one tax entry per invoice — input tax caught up and the reverse-charge pair on different days.
            $pair = $taxDate ?? $invoice->getInvoiceDate();
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': Vorsteuer nachholen am ' . $deferral->taxDate?->format('d.m.Y')
                . ' und Bezugsteuer am ' . $pair?->format('d.m.Y') . ' — eine Rechnung hat nur eine Steuerbuchung; als zwei Rechnungen erfassen (Bezugsteuer und Vorsteuer getrennt).');
        }

        // The parts: a reverse-charge line with its base (the «ESTV-Kurs» of a foreign invoice) when its
        // pair is built here, else without its code (the pair lies in the tax entry, or there is none).
        $bases = $taxDate !== null ? $this->bases($invoice, $lines, $reverse) : [];
        $parts = [];
        foreach ($lines as $i => $line) {
            $withCode = !in_array($i, $reverse, true) || $taxDate !== null;
            $parts[]  = new GrossPart((string) $line->getAccount(), $line->getAmount(), $withCode ? $line->getTaxCode() : null, $line->getText() !== '' ? $line->getText() : null, null, $line->getKind(), $bases[$i] ?? null);
        }
        $oneDay  = $taxDate !== null && $taxDate->format('Y-m-d') === $paidOn->format('Y-m-d') && !$deferral->isCatchUp();
        $builder = GrossEntryBuilder::of($this->em);
        try {
            if ($deferral->isCatchUp()) {
                // Input tax caught up (and, on the same day, the reverse-charge pair with it): ONE tax entry.
                $entry = $builder->buildSplit($paidOn, $credit, $parts, $liable, [], $rateDate, $taxDate !== null);
                $pair  = $entry;
            } elseif ($taxDate === null || $oneDay) {
                // The usual one entry — with the reverse-charge pair in it when T = «bezahlt am».
                $entry = $builder->build($paidOn, $credit, $parts, $liable, [], $oneDay ? $taxDate : $rateDate, $oneDay);
                $pair  = null;
            } else {
                // T ≠ «bezahlt am»: the expense without the reverse-charge code, the pair alone at T.
                $expenseParts = $parts;
                foreach ($reverse as $i) {
                    $expenseParts[$i] = new GrossPart($parts[$i]->account, $parts[$i]->gross, null, $parts[$i]->text);
                }
                $entry = $builder->build($paidOn, $credit, $expenseParts, $liable, [], $rateDate);
                $pair  = $builder->buildSplit($taxDate, $credit, array_values(array_map(static fn(int $i): GrossPart => $parts[$i], $reverse)), $liable, [], $taxDate, true);
            }
        } catch (GrossEntryRefusedException $e) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . self::field($e) . $e->getMessage(), $e);
        }
        $expense = PostingRequest::generated($paidOn, self::text($invoice), CreditorInvoice::SOURCE_TYPE, (string) $invoice->getId(), $invoice->idempotencyKey(), $entry->lines);
        $rcTax   = $taxDate !== null ? self::reverseChargeTax($oneDay ? $entry : ($pair ?? $entry)) : null;

        if ($deferral->isCatchUp() && $entry->taxLines !== [] && $deferral->taxDate !== null) {
            $moved = self::caughtUpTax($entry);
            $tax   = PostingRequest::generated($deferral->taxDate, $rcTax !== null ? self::pairText($invoice) : self::taxText($invoice), CreditorInvoice::SOURCE_TYPE, (string) $invoice->getId(), $invoice->taxIdempotencyKey(), $entry->taxLines);

            return new PaymentEntries($expense, $tax, $deferral->settledPeriod, $moved ?? Money::zero($expense->currency()), $rcTax, $afterEnd);
        }
        if ($pair !== null && $pair->taxLines !== [] && $taxDate !== null && !$deferral->isCatchUp()) {
            $tax = PostingRequest::generated($taxDate, self::pairText($invoice), CreditorInvoice::SOURCE_TYPE, (string) $invoice->getId(), $invoice->taxIdempotencyKey(), $pair->taxLines);

            return new PaymentEntries($expense, $tax, null, null, $rcTax, $afterEnd);
        }

        // One entry (nothing to move, a tax of 0.00, or the reverse-charge pair inside it).
        return new PaymentEntries($expense, null, null, null, $oneDay ? $rcTax : null, $afterEnd);
    }

    /**
     * The REVERSE-CHARGE tax entry of $invoice as it should read now — the pair alone (the coded line
     * on the input account its code names [code, rate, base, tax] against VAT owed), dated at the
     * INVOICE date, key `creditor:{id}:vat`, text «Rechnung … · Kreditor · Bezugsteuer»
     * (`GrossEntryBuilder::buildSplit(…, reverseCharge: true)` — its tax lines only). Null when the
     * invoice has no reverse-charge line, its invoice date lies after «Abrechnung bis» (D-4), or the
     * tax rounds to 0.00.
     *
     * $judgeDate (the run, for an invoice not paid in it): the invoice date is judged by financial's
     * reverse-charge rule — a POSTED period refuses with the owner's sentence (O-8); before «Abrechnung
     * ab» the entry is built and the ledger's period rule refuses it with its sentence. Without
     * (a save that re-posts the entry it posted earlier): the date is not judged here — the ledger's
     * freeze decides on `amend()` (an unchanged entry passes, a changed one in a settled period is
     * refused).
     *
     * Needs: the creditor (the entry names it) and the «Rechnungsdatum»; per reverse-charge line an account; on a foreign invoice the
     * «ESTV-Kurs» (the base = the printed amount × the rate, shared out over the lines by their CHF
     * amounts — `Money::allocate()`; a single line takes it all, also before its CHF amount is known);
     * on a CHF invoice the line amount (= the base). The builder needs a counter account although the
     * pair names none: «Bezahlt mit»'s account, else the mandator's VAT settlement account — its lines
     * are not used.
     *
     * @param list<CreditorInvoiceLine> $lines
     * @throws CreditorRefusedException NOT_POSTABLE with the German reason
     */
    public function reverseChargeEntry(CreditorInvoice $invoice, array $lines, bool $judgeDate = true): ?PostingRequest
    {
        $lines   = array_values($lines);
        $reverse = array_keys(array_filter($lines, fn(CreditorInvoiceLine $l): bool => $this->codes()->isReverseCharge($l->getTaxCode())));
        if ($reverse === []) {
            return null;
        }
        $invoiceDate = $invoice->getInvoiceDate();
        $missing     = array_values(array_filter([$invoice->getCreditor() === null ? 'Kreditor' : null, $invoiceDate === null ? 'Rechnungsdatum' : null]));
        if ($missing !== []) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': es fehlt: ' . implode(', ', $missing) . '.');
        }
        if ($judgeDate) {
            $taxDate = $this->reverseChargeDate($invoice);
        } else {
            $taxDate = $this->afterEnd($invoiceDate) ? null : $invoiceDate->setTime(0, 0);
        }
        if ($taxDate === null) {
            return null;
        }
        $bases = $this->bases($invoice, $lines, $reverse);
        $parts = [];
        foreach ($reverse as $i) {
            $line = $lines[$i];
            if ($line->getAccount() === null) {
                throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': Zeile ' . ($line->getPosition() + 1) . ' ohne Konto.');
            }
            $gross = $line->getAmount() ?? $bases[$i] ?? null;
            if ($gross === null) {
                throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': Zeile ' . ($line->getPosition() + 1) . ' ohne Betrag.');
            }
            $parts[] = new GrossPart($line->getAccount(), $gross, $line->getTaxCode(), $line->getText() !== '' ? $line->getText() : null, null, $line->getKind(), $bases[$i] ?? null);
        }
        $counter = $invoice->getCreditAccount() ?? $this->settlementAccount();
        if ($counter === null) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': es fehlt: Bezahlt mit.');
        }
        try {
            $pair = GrossEntryBuilder::of($this->em)->buildSplit($taxDate, $counter, $parts, (new CreditorProfileService($this->em))->liableToVat(), [], $taxDate, true);
        } catch (GrossEntryRefusedException $e) {
            $line = $e->part !== null && isset($reverse[$e->part]) ? $lines[$reverse[$e->part]]->getPosition() : null;
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . self::field($e, $line) . $e->getMessage(), $e);
        }
        if ($pair->taxLines === []) {
            return null;
        }

        return PostingRequest::generated($taxDate, self::pairText($invoice), CreditorInvoice::SOURCE_TYPE, (string) $invoice->getId(), $invoice->taxIdempotencyKey(), $pair->taxLines);
    }

    /**
     * Whether the reverse-charge lines of an invoice dated $invoiceDate get NO tax because the date
     * lies after «Abrechnung bis» (owner D-4: z77 posts and requires none; a grey line names it).
     */
    public function reverseChargeAfterEnd(\DateTimeImmutable $invoiceDate): bool
    {
        return $this->afterEnd($invoiceDate);
    }

    /** The reverse-charge codes this strategy reads (module-vat's file data, once). */
    public function codes(): ReverseChargeCodes
    {
        return $this->reverseChargeCodes ??= new ReverseChargeCodes($this->em);
    }

    /** «Rechnung 2608-117 · Druckerei Beispiel AG · Vorsteuer nachgeholt» — the tax entry's text (board S5-3). */
    public static function taxText(CreditorInvoice $invoice): string
    {
        $suffix = ' · Vorsteuer nachgeholt';

        return mb_substr(self::text($invoice), 0, CreditorInvoiceLine::TEXT_LENGTH - mb_strlen($suffix)) . $suffix;
    }

    /** «Rechnung INV-55120 · Pixelsoft Inc. · Bezugsteuer» — the text of the reverse-charge tax entry (board S6-3). */
    public static function pairText(CreditorInvoice $invoice): string
    {
        $suffix   = ' · Bezugsteuer';
        $creditor = $invoice->getCreditor()?->getContact()?->displayName();

        return mb_substr($invoice->label() . ($creditor !== null && $creditor !== '' ? ' · ' . $creditor : ''), 0, CreditorInvoiceLine::TEXT_LENGTH - mb_strlen($suffix)) . $suffix;
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

    /** «7.17» — an amount as the run's lines name it. */
    public static function amount(Money $amount): string
    {
        return AmountFormat::of($amount);
    }

    /**
     * The tax date of the reverse charge of $invoice — financial's reverse-charge rule ALONE
     * (`ReverseChargeTiming::forInvoiceDate()`, the INVOICE date, O-2; never the input-tax rule):
     *
     *   - `refused` (the invoice date lies in a POSTED VAT period, same calendar year or not) → the
     *     owner's sentence (O-8), nothing of the invoice posted;
     *   - `none` after «Abrechnung bis» → null: no reverse-charge tax (D-4);
     *   - `post-at`, `before-start`, any other `none` (no «Abrechnung ab», the ordinary rules) → the
     *     invoice date; before «Abrechnung ab» the ledger's period rule refuses the tax lines with its
     *     sentence and the way out «ohne MWST» (S56-9).
     *
     * @throws CreditorRefusedException NOT_POSTABLE
     */
    private function reverseChargeDate(CreditorInvoice $invoice): ?\DateTimeImmutable
    {
        $invoiceDate = $invoice->getInvoiceDate();
        if ($invoiceDate === null) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': es fehlt: Rechnungsdatum.');
        }
        $answer = $this->reverseChargeAnswer($invoiceDate);
        if ($answer->isRefused()) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': ' . $answer->refusalText());
        }
        if ($answer->isAfterEnd()) {
            return null;
        }

        return $answer->taxDate ?? $invoiceDate->setTime(0, 0);
    }

    /**
     * After «Abrechnung bis» (owner D-4) — financial's reverse-charge rule says so on its answer
     * (`ReverseChargeDate::isAfterEnd()`); the date is never compared with «Abrechnung bis» here.
     */
    private function afterEnd(\DateTimeImmutable $invoiceDate): bool
    {
        return $this->reverseChargeAnswer($invoiceDate)->isAfterEnd();
    }

    /** The ONE place this module asks financial's reverse-charge rule (owner O-4: its own rule, by the invoice date). */
    private function reverseChargeAnswer(\DateTimeImmutable $invoiceDate): ReverseChargeDate
    {
        return (new ReverseChargeTiming($this->em))->forInvoiceDate($invoiceDate);
    }

    /**
     * The base of each reverse-charge line ($reverse = their indexes in $lines) in CHF when it is not
     * the line amount: a foreign invoice → the printed amount × the «ESTV-Kurs», shared out over ALL
     * lines by their CHF amounts (`Money::allocate()`; the shares of the reverse-charge lines); a
     * single line takes it all. A CHF invoice → [] (the amount paid is the base).
     *
     * @param list<CreditorInvoiceLine> $lines
     * @param list<int> $reverse
     * @return array<int, Money> line index → base
     * @throws CreditorRefusedException NOT_POSTABLE — no «ESTV-Kurs», a line without an amount among several
     */
    private function bases(CreditorInvoice $invoice, array $lines, array $reverse): array
    {
        if (!$invoice->isForeign() || $reverse === []) {
            return [];
        }
        $total = $invoice->estvBase();
        if ($total === null) {
            throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': es fehlt: ESTV-Kurs.');
        }
        if (count($lines) === 1) {
            return [0 => $total];
        }
        $weights = [];
        foreach ($lines as $line) {
            if ($line->getAmount() === null) {
                throw new CreditorRefusedException(CreditorRefusedException::NOT_POSTABLE, self::name($invoice) . ': Zeile ' . ($line->getPosition() + 1) . ' ohne Betrag.');
            }
            $weights[] = $line->getAmount()->minor;
        }
        $shares = $total->allocate($weights);
        $bases  = [];
        foreach ($reverse as $i) {
            $bases[$i] = $shares[$i];
        }

        return $bases;
    }

    /** The mandator's VAT settlement account — the counter of a pair built without «Bezahlt mit» (its lines are not used). */
    private function settlementAccount(): ?string
    {
        try {
            return (new LedgerService($this->em))->vatReturnAccount(LedgerService::VAT_SETTLEMENT_ACCOUNT);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The reverse-charge tax of an entry's splits (the pair's or the one entry's). */
    private static function reverseChargeTax(GrossEntry $entry): ?Money
    {
        $sum = null;
        foreach ($entry->splits as $split) {
            if ($split !== null && $split->reverseCharge && $split->account !== null) {
                $sum = $sum === null ? $split->tax : $sum->add($split->tax);
            }
        }

        return $sum;
    }

    /** The input tax a split entry moves from the transit account (the reverse-charge pair left out). */
    private static function caughtUpTax(GrossEntry $entry): ?Money
    {
        $sum = null;
        foreach ($entry->splits as $split) {
            if ($split !== null && !$split->reverseCharge && $split->account !== null) {
                $sum = $sum === null ? $split->tax : $sum->add($split->tax);
            }
        }

        return $sum;
    }

    /** The builder's field → its German name in a message («Zeile 2, MWST: …»); $position = the line's own position when the part index is not it. */
    private static function field(GrossEntryRefusedException $e, ?int $position = null): string
    {
        $index = $position ?? $e->part;
        $line  = $index !== null ? 'Zeile ' . ($index + 1) . ', ' : '';

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
