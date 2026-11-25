<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Ledger\GrossEntry;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\GrossPartKind;
use Z77\Module\Financial\Ledger\GrossSplit;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Calculation\VatCalculator;
use Z77\Module\Vat\Calculation\VatLine;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\CountryPack\UnmappedTaxCodeException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Services\VatException;
use Z77\Module\Vat\Services\VatRates;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The posting lines of an entry captured from GROSS amounts — the VAT split exists HERE, once
 * (creditor plan §2.5, RECEIPT-BUILD-001). Callers: the one-line manual entry
 * (`Ui\OneLineEntryForm`), module-receipt (`ReceiptPostingBuilder`), module-creditor (one entry per
 * invoice, one part per invoice line), the bank (`Bank\BankPostingService`). Nothing here posts:
 * the caller wraps the lines in a `PostingRequest` and hands it to `LedgerService::post()`.
 *
 * Shape: n gross parts on the Soll side (each: account, gross, tax code or none, text, kind)
 * against ONE counter account on the Haben side (the bank, the cash, the payment method). Per part:
 *
 *   - a STANDARD part: the tax is COMPUTED from the gross at the rate valid on the RATE DATE (the
 *     entry date unless the caller names another — a creditor's invoice date, ADR-041 addendum
 *     2026-11-25, point 7) — `VatCalculator` in gross mode, tax = gross × rate / (10000 + rate),
 *     commercial rounding (ADR-041 decision 5), PER PART; a voucher's own tax amount is taken only
 *     within {@see taxCorrectionLimit()};
 *   - the NET line is the side whose account is an EXPENSE or REVENUE account (owner 2026-09-22);
 *     neither side a P&L account → the code's natural side decides
 *     ({@see TaxCategory::isDebitNatural()}: input codes Soll, every other Haben); both → refused
 *     (which side is net is not decidable — a Sammelbuchung carries it);
 *   - the net line carries the tax data, the tax line goes on the SAME side, on the mandator's
 *     VAT account of the category ({@see LedgerService::vatAccountFor()}); zero-rated and exempt
 *     codes put the code on the net line with tax 0.00 and write NO tax line, as does a taxed code
 *     whose tax rounds to 0.00;
 *   - a TAX-ONLY part ({@see GrossPartKind::TaxOnly}, «nur Steuer» — ADR-041 addendum 2026-11-25,
 *     point 6): input codes only; its amount IS the tax; ONE line on the input account the code maps
 *     to, base 0.00 and the code's rate at the rate date; the part's account is ignored (derived,
 *     never typed); no voucher tax;
 *   - REVERSE CHARGE (only when the caller opts in with `reverseCharge: true` — the receipt, the
 *     one-line entry and the bank do not take it yet, and keep their refusal): the part's amount is
 *     the AMOUNT PAID = the base (no Swiss VAT inside) unless the part names a `taxBase`; tax = base ×
 *     rate on top (`VatCalculator` in net mode), per part; no voucher tax. The single-entry shape of
 *     ADR-041 (addendum 2026-11-24, point 8): Dr expense = paid [code, rate, base, tax] · Dr the input
 *     account the code's input field names = tax · Cr VAT owed = tax — the two tax lines from
 *     {@see VatAccountMap::taxLines()} at the rate date;
 *   - the three input-tax corrections (VE / VK / VN — {@see TaxCategory::isManualOnly()}) are
 *     refused: manual entries only;
 *   - signs: base and tax are positive when the coded line stands on the code's NATURAL side,
 *     negated otherwise.
 *
 * Lines in order: per part its line(s) — [net Soll, tax Soll] or, net on the counter side,
 * [gross Soll, net Haben, tax Haben]; a tax-only part [tax Soll]; reverse charge [paid Soll, input
 * Soll, owed Haben] — then ONE counter line with the sum of the parts whose coded line stood on the
 * Soll side. One part reproduces the one-line entry's canonical order exactly.
 *
 * {@see buildSplit()} builds the same parts as TWO entries — an expense entry and a tax entry on a
 * date the CALLER names. It is construction only: whether and when to split is decided elsewhere
 * (input tax: `VatReturn\VatSettlement::inputTaxDeferral()`; reverse charge: its own rule, by the
 * invoice date).
 *
 * The VAT accounts (ADR-042 addendum 2 of 2026-11-25, point 7): an account the lock guards on the
 * entry's date ({@see VatAccountMap::lockedAccounts()}) is refused as a part's account and as the
 * counter account — a tax line comes from a code, never from a typed account.
 *
 * Amounts are in the base currency only (`LedgerService::baseCurrency()`); a foreign currency is
 * refused until the ledger carries one. VAT liability: `build($liableToVat: false)` refuses every
 * tax code (owner 2026-11-15, creditor: not liable forces «ohne MWST»); null leaves it to the
 * caller (module-receipt, RECEIPT-VAT-001).
 *
 * Every refusal is a {@see GrossEntryRefusedException} with a German message and the field.
 */
final class GrossEntryBuilder
{
    /**
     * How far a voucher's tax amount may deviate from the computed one (orchestrator decision
     * 2026-09-22; PERCENT from 10 to 1 on 2026-09-23, owner, P2 exit check 7): the limit is
     * min(MAX, max(MIN, PERCENT % of the computed tax)), in the base currency. It is for VOUCHER
     * ROUNDING only — a few Rappen against the rate-exact value; at 10 % the flat MAX of 1.00
     * accepted 30.80 (400 × 7.7 % on top, the old rate) against the correct 29.97. At 1 % the
     * limit there is 0.30. A different tax belongs in a Sammelbuchung. The ONE tolerance — the
     * Sammelbuchung's column «Steuer» reads it through {@see taxCorrectionLimit()}.
     */
    public const TAX_CORRECTION_MAX     = '1.00';
    public const TAX_CORRECTION_MIN     = '0.05';
    public const TAX_CORRECTION_PERCENT = 1;

    private ?VatAccountMap $vatAccounts;

    /**
     * @param VatAccountMap|null $vatAccounts the VAT accounts (lock and reverse-charge lines); null =
     *                                        neither is consulted: the lock of a typed VAT account is
     *                                        left to the ledger, reverse charge cannot be built
     *                                        ({@see of()} always passes one)
     */
    public function __construct(
        private readonly string $currency,
        private readonly AccountRepository $accounts,
        private readonly TaxCodeRepository $codes,
        private readonly VatRates $rates,
        private readonly LedgerService $ledger,
        ?VatAccountMap $vatAccounts = null,
    ) {
        $this->vatAccounts = $vatAccounts;
    }

    /** The builder in the installation's base currency — what a module uses. */
    public static function of(UnifiedEntityManager $em): self
    {
        $ledger = new LedgerService($em);

        return new self(
            LedgerService::baseCurrency(),
            $em->getRepository(Account::class),
            $em->getRepository(TaxCode::class),
            VatRates::from($em),
            $ledger,
            new VatAccountMap($em, $ledger),
        );
    }

    /**
     * The lines of $parts (Soll) against $counter (Haben) on $date — ONE entry.
     *
     * @param list<GrossPart> $parts
     * @param bool|null       $liableToVat   the mandator's VAT liability: false refuses every tax code;
     *                                       null = not applied by this caller
     * @param list<string>    $keptCodes     codes accepted although inactive (an edited entry keeps its code)
     * @param \DateTimeImmutable|null $rateDate the day whose rate applies (and, for reverse charge,
     *                                       whose form edition names the input account); null = $date
     * @param bool            $reverseCharge the caller takes reverse-charge codes (the class comment);
     *                                       false refuses them as before
     * @throws GrossEntryRefusedException
     */
    public function build(
        \DateTimeImmutable $date,
        string $counter,
        array $parts,
        ?bool $liableToVat = null,
        array $keptCodes = [],
        ?\DateTimeImmutable $rateDate = null,
        bool $reverseCharge = false,
    ): GrossEntry {
        [$parts, $accounts, $counterAccount, $splits] = $this->prepare($date, $counter, $parts, $liableToVat, $keptCodes, $rateDate ?? $date, $reverseCharge);

        return new GrossEntry($this->lines($parts, $splits, $accounts, $counterAccount->getNumber()), $splits);
    }

    /**
     * The same parts as TWO entries — construction only; the caller decides that and when to split
     * (see the class comment) and posts each entry under its own key:
     *
     *   - the EXPENSE entry ({@see GrossEntry::$lines}) on $date, the voucher's own date, WITHOUT any
     *     tax code: a standard input part posts its net on its account and its tax on the transit
     *     account «Vorsteuer, noch nicht abgerechnet» (`vat-input-pending`); a tax-only part posts its
     *     amount on the transit account; a reverse-charge part posts the amount paid on its account;
     *     a part «ohne MWST» as in {@see build()};
     *   - the TAX entry ({@see GrossEntry::$taxLines}) on the caller's tax date, per part with tax:
     *     the catch-up of input tax — a tax-only line on the input account [code, rate, 0.00, tax]
     *     against the transit account; the reverse-charge pair alone — the coded line on the input
     *     account its code names [code, rate, base, tax] against VAT owed.
     *
     * The rate (and a reverse-charge code's form edition) is that of $rateDate — null = $date, the
     * voucher's. A part whose tax rounds to 0.00 has nothing to move: it is posted uncoded in the
     * expense entry. Zero-rated, exempt and output codes are refused — only input tax and reverse
     * charge are split. Reverse-charge parts need `reverseCharge: true`, as in {@see build()}.
     *
     * @param list<GrossPart> $parts
     * @param list<string>    $keptCodes
     * @throws GrossEntryRefusedException
     */
    public function buildSplit(
        \DateTimeImmutable $date,
        string $counter,
        array $parts,
        ?bool $liableToVat = null,
        array $keptCodes = [],
        ?\DateTimeImmutable $rateDate = null,
        bool $reverseCharge = false,
    ): GrossEntry {
        [$parts, $accounts, $counterAccount, $splits] = $this->prepare($date, $counter, $parts, $liableToVat, $keptCodes, $rateDate ?? $date, $reverseCharge);

        $transit = null;
        foreach ($splits as $i => $split) {
            if ($split === null) {
                continue;
            }
            if (!$split->reverseCharge && $this->codes->findByCode($split->code)?->category()?->isDebitNatural() !== true) {
                throw new GrossEntryRefusedException(GrossEntryRefusedException::TAX_CODE, 'MWST-Code ' . $split->code
                    . ' lässt sich nicht getrennt buchen — nur Vorsteuer und Bezugsteuer.', $i);
            }
            if (!$split->reverseCharge && $split->account !== null) {
                $transit ??= $this->transitAccount($i);
            }
        }

        return $this->splitLines($parts, $splits, $accounts, $counterAccount->getNumber(), $transit);
    }

    /**
     * An account a posting can name: a number — a leading number is enough («1020 Bankguthaben» is
     * read as 1020, as a datalist shows it) — that exists, is postable and active.
     *
     * @throws GrossEntryRefusedException ACCOUNT with the German reason
     */
    public function account(string $value): Account
    {
        return $this->resolve($value, GrossEntryRefusedException::ACCOUNT, null);
    }

    /**
     * min(MAX, max(MIN, PERCENT % of the computed tax)) in the currency of $computed — see
     * {@see TAX_CORRECTION_MAX}. The ONE tolerance for a typed tax against the computed one.
     */
    public static function taxCorrectionLimit(Money $computed): Money
    {
        $max   = Money::fromDecimal(self::TAX_CORRECTION_MAX, $computed->currency);
        $min   = Money::fromDecimal(self::TAX_CORRECTION_MIN, $computed->currency);
        $share = $computed->multiplyByRatio(self::TAX_CORRECTION_PERCENT, 100);
        $limit = $share->greaterThan($min) ? $share : $min;

        return $limit->greaterThan($max) ? $max : $limit;
    }

    /**
     * The checks every entry shape shares, then the split of each part.
     *
     * @param list<GrossPart> $parts
     * @param list<string>    $keptCodes
     * @return array{0: list<GrossPart>, 1: array<int, Account|null>, 2: Account, 3: list<GrossSplit|null>}
     * @throws GrossEntryRefusedException
     */
    private function prepare(\DateTimeImmutable $date, string $counter, array $parts, ?bool $liableToVat, array $keptCodes, \DateTimeImmutable $rateDate, bool $reverseCharge): array
    {
        if ($parts === []) {
            throw new GrossEntryRefusedException(GrossEntryRefusedException::ENTRY, 'Keine Zeile — mindestens ein Betrag ist nötig.');
        }
        $parts    = array_values($parts);
        $accounts = [];
        foreach ($parts as $i => $part) {
            // A tax-only part names no account of its own: the VAT account is derived from the code.
            $accounts[$i] = $part->kind === GrossPartKind::TaxOnly ? null : $this->resolve($part->account, GrossEntryRefusedException::ACCOUNT, $i, $date);
        }
        $counterAccount = $this->resolve($counter, GrossEntryRefusedException::COUNTER, null, $date);
        foreach ($parts as $i => $part) {
            if ($accounts[$i]?->getNumber() === $counterAccount->getNumber()) {
                throw new GrossEntryRefusedException(GrossEntryRefusedException::COUNTER, 'Soll und Haben sind dasselbe Konto.', $i);
            }
            foreach ([$part->gross, $part->voucherTax, $part->taxBase] as $amount) {
                if ($amount !== null && $amount->currency !== $this->currency) {
                    throw new GrossEntryRefusedException(GrossEntryRefusedException::AMOUNT, 'Betrag in ' . $amount->currency . ' — gebucht wird nur in der Buchungswährung '
                        . $this->currency . ', Fremdwährung ist noch nicht möglich.', $i);
                }
            }
            if (!$part->gross->isPositive()) {
                throw new GrossEntryRefusedException(GrossEntryRefusedException::AMOUNT, 'Der Betrag muss grösser als 0 sein.', $i);
            }
            if ($liableToVat === false && $part->taxCode !== null) {
                throw new GrossEntryRefusedException(GrossEntryRefusedException::TAX_CODE, 'Der Mandant ist nicht MWST-pflichtig — nur «ohne MWST» ist möglich (MWST-Code '
                    . $part->taxCode . ' entfernen).', $i);
            }
            if ($part->kind === GrossPartKind::TaxOnly && $part->taxCode === null) {
                throw new GrossEntryRefusedException(GrossEntryRefusedException::TAX_CODE, '«Nur Steuer» braucht einen Vorsteuer-Code (VM oder VI).', $i);
            }
        }

        $splits = [];
        foreach ($parts as $i => $part) {
            $splits[$i] = $part->taxCode === null ? null : $this->split($part, $i, $rateDate, $accounts[$i], $counterAccount, $keptCodes, $reverseCharge);
        }

        return [$parts, $accounts, $counterAccount, $splits];
    }

    /**
     * $value resolved to a postable, active account — and, with $date, not one of the VAT accounts
     * the lock guards on that day.
     *
     * @throws GrossEntryRefusedException
     */
    private function resolve(string $value, string $field, ?int $part, ?\DateTimeImmutable $date = null): Account
    {
        $value = trim($value);
        if ($value === '') {
            throw new GrossEntryRefusedException($field, 'Konto fehlt.', $part);
        }
        if (!preg_match('/^([0-9]{1,' . Account::NUMBER_LENGTH . '})(\s.*)?$/u', $value, $m)) {
            throw new GrossEntryRefusedException($field, 'Kontonummer eingeben (z.B. 1020).', $part);
        }
        $account = $this->accounts->findOneBy(['number' => $m[1]]);
        if ($account === null) {
            throw new GrossEntryRefusedException($field, 'Konto ' . $m[1] . ' gibt es nicht.', $part);
        }
        if (!$account->isPostable()) {
            throw new GrossEntryRefusedException($field, '«' . $account->label() . '» ist eine Gruppe — nicht bebuchbar.', $part);
        }
        if (!$account->isActive()) {
            throw new GrossEntryRefusedException($field, '«' . $account->label() . '» ist inaktiv.', $part);
        }
        // Numeric array keys come back as int — compare as strings.
        if ($date !== null && $this->vatAccounts !== null
            && in_array($account->getNumber(), array_map('strval', array_keys($this->vatAccounts->lockedAccounts($date))), true)) {
            throw new GrossEntryRefusedException($field, '«' . $account->label() . '» ist ein MWST-Konto — es nimmt nur die berechnete Steuer aus einem MWST-Code '
                . '(Zahlungen an die ESTV über das Abrechnungskonto MWST).', $part);
        }

        return $account;
    }

    /**
     * The VAT of one part: code checked, the kind's branch, rate by the rate date, the tax and the
     * tax account(s).
     *
     * @param list<string> $keptCodes
     * @throws GrossEntryRefusedException
     */
    private function split(GrossPart $part, int $i, \DateTimeImmutable $rateDate, ?Account $debit, Account $credit, array $keptCodes, bool $reverseCharge): GrossSplit
    {
        $refuse = static fn(string $field, string $message, ?\Throwable $previous = null) => new GrossEntryRefusedException($field, $message, $i, $previous);

        $code = $this->codes->findByCode($part->taxCode);
        if ($code === null) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'MWST-Code ' . $part->taxCode . ' gibt es nicht.');
        }
        if (!$code->isActive() && !in_array($code->getCode(), $keptCodes, true)) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'MWST-Code ' . $code->getCode() . ' ist inaktiv — nur bestehende Buchungen behalten ihn.');
        }
        $category = $code->category();
        if ($category === null || ($category === TaxCategory::ReverseCharge && !$reverseCharge)) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, $category === null
                ? 'MWST-Code ' . $code->getCode() . ' hat eine unbekannte Kategorie «' . $code->getCategory() . '».'
                : 'Bezugsteuer (' . $code->getCode() . ') wird auf beiden Seiten gebucht — als Sammelbuchung erfassen.');
        }
        if ($category->isManualOnly()) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'MWST-Code ' . $code->getCode() . ' ist eine Vorsteuerkorrektur — nur als Sammelbuchung.');
        }
        if ($part->taxBase !== null && $category !== TaxCategory::ReverseCharge) {
            throw $refuse(GrossEntryRefusedException::AMOUNT, 'Eine eigene Bemessungsgrundlage gibt es nur bei Bezugsteuer.');
        }
        if ($part->kind === GrossPartKind::TaxOnly) {
            return $this->taxOnly($part, $code, $category, $rateDate, $credit, $refuse);
        }
        if ($category === TaxCategory::ReverseCharge) {
            return $this->reverseCharge($part, $code, $rateDate, $debit, $credit, $refuse);
        }

        return $this->standard($part, $code, $category, $rateDate, $debit, $credit, $refuse);
    }

    /**
     * A standard part: gross split into net and tax at the rate date.
     *
     * @param \Closure(string, string, ?\Throwable=): GrossEntryRefusedException $refuse
     * @throws GrossEntryRefusedException
     */
    private function standard(GrossPart $part, TaxCode $code, TaxCategory $category, \DateTimeImmutable $rateDate, Account $debit, Account $credit, \Closure $refuse): GrossSplit
    {
        $gross      = $part->gross;
        $input      = $category->isDebitNatural();
        $netOnDebit = $this->netOnDebit($debit, $credit, $input, $refuse);

        try {
            $result = (new VatCalculator($this->rates))->calculate($this->currency, [new VatLine('gross-part', $gross, $code->getCode())], $rateDate, PriceMode::Gross);
        } catch (VatException $e) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'Für ' . $code->getCode() . ' gilt am ' . $rateDate->format('d.m.Y') . ' kein Satz.', $e);
        }
        $rate     = $result->lines[0]->rate->rate;
        $computed = $result->tax();
        $untaxed  = !$category->carriesTax();

        $tax = $computed;
        if ($part->voucherTax !== null) {
            $entered = $part->voucherTax;
            if ($entered->isNegative()) {
                throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'Kein Steuerbetrag (z.B. 29.97) — leer lassen, um ihn zu berechnen.');
            }
            if ($untaxed && !$entered->isZero()) {
                throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'MWST-Code ' . $code->getCode() . ' ist steuerfrei — kein Steuerbetrag.');
            }
            if ($entered->isZero() && !$computed->isZero()) {
                throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'Steuer 0.00 geht nur, wenn die berechnete Steuer 0.00 ist (hier ' . AmountFormat::of($computed) . ') — sonst die MwSt-Zeile ausschalten.');
            }
            $deviation = $entered->subtract($computed);
            $limit     = self::taxCorrectionLimit($computed);
            if ($deviation->greaterThan($limit) || $deviation->negate()->greaterThan($limit)) {
                throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'Steuerbetrag ' . AmountFormat::of($entered) . ' weicht um ' . AmountFormat::of($deviation) . ' vom berechneten '
                    . AmountFormat::of($computed) . ' ab — erlaubt sind höchstens ' . AmountFormat::of($limit) . ' (Rundung laut Beleg). Andere Steuer als Sammelbuchung erfassen.');
            }
            $tax = $entered;
        }
        if (!$tax->lessThan($gross)) {
            throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'Die Steuer muss kleiner als der Betrag sein.');
        }

        $account = $untaxed ? null : $this->vatAccount($code, $category, $refuse);

        return new GrossSplit(
            $code->getCode(),
            $rate,
            $computed,
            $tax,
            $gross->subtract($tax),
            $netOnDebit,
            $netOnDebit === $input,   // the net line on the code's natural side → positive base and amount
            $tax->isZero() ? null : $account,
        );
    }

    /**
     * A tax-only part: the amount IS the tax, on the input account the code maps to.
     *
     * @param \Closure(string, string, ?\Throwable=): GrossEntryRefusedException $refuse
     * @throws GrossEntryRefusedException
     */
    private function taxOnly(GrossPart $part, TaxCode $code, TaxCategory $category, \DateTimeImmutable $rateDate, Account $credit, \Closure $refuse): GrossSplit
    {
        if (!$category->allowsTaxOnly()) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, '«Nur Steuer» geht nur mit einem Vorsteuer-Code (VM oder VI) — nicht mit ' . $code->getCode() . '.');
        }
        if ($part->voucherTax !== null) {
            throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, '«Nur Steuer»: der Betrag ist die Steuer — kein eigener Steuerbetrag.');
        }
        try {
            $rate = $this->rates->resolve($code->getCode(), $rateDate)->rate;
        } catch (VatException $e) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'Für ' . $code->getCode() . ' gilt am ' . $rateDate->format('d.m.Y') . ' kein Satz.', $e);
        }
        $account = $this->vatAccount($code, $category, $refuse);
        if ($account === $credit->getNumber()) {
            throw $refuse(GrossEntryRefusedException::COUNTER, 'Soll und Haben sind dasselbe Konto.');
        }
        $zero = Money::zero($this->currency);

        return new GrossSplit($code->getCode(), $rate, $part->gross, $part->gross, $zero, true, true, $account, GrossPartKind::TaxOnly);
    }

    /**
     * A reverse-charge part: the amount paid is the base (or the part's `taxBase`), the tax on top,
     * one tax line on the input account the code's input field names and one on VAT owed.
     *
     * @param \Closure(string, string, ?\Throwable=): GrossEntryRefusedException $refuse
     * @throws GrossEntryRefusedException
     */
    private function reverseCharge(GrossPart $part, TaxCode $code, \DateTimeImmutable $rateDate, Account $debit, Account $credit, \Closure $refuse): GrossSplit
    {
        if ($part->voucherTax !== null) {
            throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'Bezugsteuer wird berechnet — kein Steuerbetrag laut Beleg.');
        }
        $base = $part->taxBase ?? $part->gross;
        if (!$base->isPositive()) {
            throw $refuse(GrossEntryRefusedException::AMOUNT, 'Die Bemessungsgrundlage der Bezugsteuer muss grösser als 0 sein.');
        }
        $netOnDebit = $this->netOnDebit($debit, $credit, true, $refuse);

        try {
            $result = (new VatCalculator($this->rates))->calculate($this->currency, [new VatLine('reverse-charge', $base, $code->getCode())], $rateDate, PriceMode::Net);
        } catch (VatException $e) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'Für ' . $code->getCode() . ' gilt am ' . $rateDate->format('d.m.Y') . ' kein Satz.', $e);
        }
        $tax = $result->tax();

        $owed  = null;
        $input = null;
        try {
            foreach ($this->vatAccountMap()->taxLines($code->getCode(), $rateDate) as $line) {
                if ($line['account'] === null) {
                    throw $refuse(GrossEntryRefusedException::ENTRY, 'Für die Bezugsteuer (Code ' . $code->getCode() . ') fehlt ein MWST-Konto — ' . LedgerService::MANDATOR_HINT . '.');
                }
                $number = (string) $line['account'];
                if (!$this->ledger->accountExists($number)) {
                    throw $refuse(GrossEntryRefusedException::ENTRY, 'Das Steuerkonto ' . $number . ' (Mandant, Bezugsteuer ' . $code->getCode() . ') gibt es nicht, oder es ist eine Gruppe oder inaktiv — ' . LedgerService::MANDATOR_HINT . '.');
                }
                if ($line['key'] === LedgerService::VAT_OWED_ACCOUNT) {
                    $owed = $number;
                } else {
                    $input = $number;
                }
            }
        } catch (UnmappedTaxCodeException $e) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'Bezugsteuer-Code ' . $code->getCode() . ' hat im MWST-Formular kein Vorsteuerfeld — BM, BI, BM2 oder BI2 verwenden.', $e);
        } catch (FormMappingException $e) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'Für den ' . $rateDate->format('d.m.Y') . ' gibt es kein MWST-Formular — Bezugsteuer ' . $code->getCode() . ' geht dort nicht.', $e);
        } catch (VatAccountUnavailableException $e) {
            throw $refuse(GrossEntryRefusedException::ENTRY, $e->getMessage(), $e);
        }
        if ($owed === null || $input === null) {
            throw $refuse(GrossEntryRefusedException::ENTRY, 'Für die Bezugsteuer (Code ' . $code->getCode() . ') fehlt ein MWST-Konto — ' . LedgerService::MANDATOR_HINT . '.');
        }

        return new GrossSplit(
            $code->getCode(),
            $result->lines[0]->rate->rate,
            $tax,
            $tax,
            $part->gross,
            $netOnDebit,
            $netOnDebit,              // reverse charge is debit-natural
            $tax->isZero() ? null : $input,
            GrossPartKind::Standard,
            true,
            $tax->isZero() ? null : $owed,
            $base,
        );
    }

    /**
     * The net side: the P&L account (owner, 2026-09-22); neither → the code's natural side; both → refused.
     *
     * @param \Closure(string, string, ?\Throwable=): GrossEntryRefusedException $refuse
     * @throws GrossEntryRefusedException
     */
    private function netOnDebit(Account $debit, Account $credit, bool $debitNatural, \Closure $refuse): bool
    {
        $debitPl  = self::isProfitAndLoss($debit);
        $creditPl = self::isProfitAndLoss($credit);
        if ($debitPl && $creditPl) {
            throw $refuse(GrossEntryRefusedException::ENTRY, 'Soll und Haben sind beides Aufwand- oder Ertragskonten — welche Seite netto ist, ist nicht eindeutig. Als Sammelbuchung erfassen.');
        }

        return $debitPl || (!$creditPl && $debitNatural);
    }

    /**
     * The mandator's VAT account of a category with ONE tax account — refused when it is missing.
     *
     * @param \Closure(string, string, ?\Throwable=): GrossEntryRefusedException $refuse
     * @throws GrossEntryRefusedException
     */
    private function vatAccount(TaxCode $code, TaxCategory $category, \Closure $refuse): string
    {
        try {
            $account = $this->ledger->vatAccountFor($category->value);
        } catch (VatAccountUnavailableException $e) {
            // A leftover config key or an unreadable mandator: the sentence says what to do (review 2026-09-23, P5/P6).
            throw $refuse(GrossEntryRefusedException::ENTRY, $e->getMessage(), $e);
        }
        if ($account === null) {
            throw $refuse(GrossEntryRefusedException::ENTRY, 'Für MWST-Kategorie «' . $category->value . '» (Code ' . $code->getCode() . ') ist kein Steuerkonto hinterlegt — ' . LedgerService::MANDATOR_HINT . '.');
        }
        if (!$this->ledger->accountExists($account)) {
            throw $refuse(GrossEntryRefusedException::ENTRY, 'Das Steuerkonto ' . $account . ' (Mandant, Kategorie ' . $category->value . ') gibt es nicht, oder es ist eine Gruppe oder inaktiv — ' . LedgerService::MANDATOR_HINT . '.');
        }

        return $account;
    }

    /**
     * The transit account «Vorsteuer, noch nicht abgerechnet» of {@see buildSplit()} — refused when missing.
     *
     * @throws GrossEntryRefusedException
     */
    private function transitAccount(int $part): string
    {
        $missing = 'Für «Vorsteuer, noch nicht abgerechnet» ist kein Konto hinterlegt — ' . LedgerService::MANDATOR_HINT . '.';
        try {
            $account = $this->ledger->vatReturnAccount(LedgerService::VAT_INPUT_PENDING_ACCOUNT);
        } catch (\InvalidArgumentException|MandatorUnavailableException $e) {
            throw new GrossEntryRefusedException(GrossEntryRefusedException::ENTRY, $missing, $part, $e);
        }
        if ($account === null) {
            throw new GrossEntryRefusedException(GrossEntryRefusedException::ENTRY, $missing, $part);
        }
        if (!$this->ledger->accountExists($account)) {
            throw new GrossEntryRefusedException(GrossEntryRefusedException::ENTRY, 'Das Konto ' . $account . ' «Vorsteuer, noch nicht abgerechnet» (Mandant) gibt es nicht, oder es ist eine Gruppe oder inaktiv — '
                . LedgerService::MANDATOR_HINT . '.', $part);
        }

        return $account;
    }

    private function vatAccountMap(): VatAccountMap
    {
        return $this->vatAccounts ?? throw new \LogicException('Reverse charge needs the VAT accounts — build the GrossEntryBuilder through GrossEntryBuilder::of()');
    }

    /**
     * The lines of ONE entry in the documented order (class comment).
     *
     * @param list<GrossPart>          $parts
     * @param list<GrossSplit|null>    $splits
     * @param array<int, Account|null> $accounts
     * @return list<PostingLine>
     */
    private function lines(array $parts, array $splits, array $accounts, string $counter): array
    {
        $lines   = [];
        $onCount = Money::zero($this->currency);   // the gross the counter line carries
        foreach ($parts as $i => $part) {
            $split = $splits[$i];
            if ($split === null) {
                $lines[] = PostingLine::debit($accounts[$i]->getNumber(), $part->gross, $part->text);
                $onCount = $onCount->add($part->gross);
                continue;
            }
            if ($split->kind === GrossPartKind::TaxOnly) {
                $lines[] = PostingLine::debit((string) $split->account, $split->tax, $part->text, ...self::taxData($split));
                $onCount = $onCount->add($part->gross);
                continue;
            }
            $account = $accounts[$i]->getNumber();
            $taxData = self::taxData($split);
            if ($split->netOnDebit) {
                $lines[] = PostingLine::debit($account, $split->net, $part->text, ...$taxData);
                if ($split->account !== null) {
                    $lines[] = PostingLine::debit($split->account, $split->tax);
                }
                if ($split->owedAccount !== null) {
                    $lines[] = PostingLine::credit($split->owedAccount, $split->tax);
                }
                $onCount = $onCount->add($part->gross);
                continue;
            }
            $lines[] = PostingLine::debit($account, $part->gross, $part->text);
            $lines[] = PostingLine::credit($counter, $split->net, null, ...$taxData);
            if ($split->account !== null) {
                $lines[] = PostingLine::credit($split->account, $split->tax);
            }
            if ($split->owedAccount !== null) {
                $lines[] = PostingLine::debit($split->owedAccount, $split->tax);
            }
        }
        if ($onCount->isPositive()) {
            $lines[] = PostingLine::credit($counter, $onCount);
        }

        return $lines;
    }

    /**
     * The two entries of {@see buildSplit()}.
     *
     * @param list<GrossPart>          $parts
     * @param list<GrossSplit|null>    $splits
     * @param array<int, Account|null> $accounts
     */
    private function splitLines(array $parts, array $splits, array $accounts, string $counter, ?string $transit): GrossEntry
    {
        $lines    = [];
        $taxLines = [];
        $onCount  = Money::zero($this->currency);
        foreach ($parts as $i => $part) {
            $split   = $splits[$i];
            $account = $accounts[$i]?->getNumber();
            $moves   = $split !== null && $split->account !== null;   // a tax to post in the tax entry
            if ($split === null || ($split->kind !== GrossPartKind::TaxOnly && !$moves)) {
                // «ohne MWST», or a tax of 0.00: the gross uncoded (a coded line would lie in the expense entry's period).
                $lines[] = PostingLine::debit((string) $account, $part->gross, $part->text);
                if ($split === null || $split->netOnDebit) {
                    $onCount = $onCount->add($part->gross);
                } else {
                    $lines[] = PostingLine::credit($counter, $part->gross);
                }
                continue;
            }
            if ($split->kind === GrossPartKind::TaxOnly) {
                // Expense entry: the tax onto the transit account; tax entry: the tax-only line against it.
                $lines[]    = PostingLine::debit((string) $transit, $split->tax, $part->text);
                $onCount    = $onCount->add($part->gross);
                $taxLines[] = PostingLine::debit((string) $split->account, $split->tax, $part->text, ...self::taxData($split));
                $taxLines[] = PostingLine::credit((string) $transit, $split->tax);
                continue;
            }
            if ($split->reverseCharge) {
                // Expense entry: the amount paid without a code; tax entry: the pair alone — the coded line on the input account against VAT owed.
                $pairData = [$split->code, $split->rate, $split->natural ? $split->base : $split->base->negate(), $split->natural ? $split->tax : $split->tax->negate()];
                $lines[]  = PostingLine::debit((string) $account, $part->gross, $part->text);
                if ($split->netOnDebit) {
                    $onCount    = $onCount->add($part->gross);
                    $taxLines[] = PostingLine::debit((string) $split->account, $split->tax, $part->text, ...$pairData);
                    $taxLines[] = PostingLine::credit((string) $split->owedAccount, $split->tax);
                } else {
                    $lines[]    = PostingLine::credit($counter, $part->gross);
                    $taxLines[] = PostingLine::credit((string) $split->account, $split->tax, $part->text, ...$pairData);
                    $taxLines[] = PostingLine::debit((string) $split->owedAccount, $split->tax);
                }
                continue;
            }
            // Input tax caught up: the net on its account and the tax on the transit account, uncoded;
            // the tax entry moves it to the input account as a tax-only line [code, rate, 0.00, ±tax].
            $catchUp = [$split->code, $split->rate, Money::zero($this->currency), $split->natural ? $split->tax : $split->tax->negate()];
            if ($split->netOnDebit) {
                $lines[]    = PostingLine::debit((string) $account, $split->net, $part->text);
                $lines[]    = PostingLine::debit((string) $transit, $split->tax);
                $onCount    = $onCount->add($part->gross);
                $taxLines[] = PostingLine::debit((string) $split->account, $split->tax, $part->text, ...$catchUp);
                $taxLines[] = PostingLine::credit((string) $transit, $split->tax);
            } else {
                $lines[]    = PostingLine::debit((string) $account, $part->gross, $part->text);
                $lines[]    = PostingLine::credit($counter, $split->net);
                $lines[]    = PostingLine::credit((string) $transit, $split->tax);
                $taxLines[] = PostingLine::credit((string) $split->account, $split->tax, $part->text, ...$catchUp);
                $taxLines[] = PostingLine::debit((string) $transit, $split->tax);
            }
        }
        if ($onCount->isPositive()) {
            $lines[] = PostingLine::credit($counter, $onCount);
        }

        return new GrossEntry($lines, $splits, $taxLines);
    }

    /**
     * The tax data of a coded line — base and tax signed by the natural side.
     *
     * @return array{0: string, 1: int, 2: Money, 3: Money}
     */
    private static function taxData(GrossSplit $split): array
    {
        return [$split->code, $split->rate, $split->natural ? $split->base : $split->base->negate(), $split->natural ? $split->tax : $split->tax->negate()];
    }

    private static function isProfitAndLoss(Account $account): bool
    {
        return in_array(AccountType::tryFrom($account->getType()), [AccountType::Expense, AccountType::Revenue], true);
    }
}
