<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Ledger\GrossEntry;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\GrossSplit;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Calculation\VatCalculator;
use Z77\Module\Vat\Calculation\VatLine;
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
 * invoice, one part per invoice line). Nothing here posts: the caller wraps the lines in a
 * `PostingRequest` and hands it to `LedgerService::post()`.
 *
 * Shape: n gross parts on the Soll side (each: account, gross, tax code or none, text) against ONE
 * counter account on the Haben side (the bank, the cash, the payment method). Per part:
 *
 *   - the tax is COMPUTED from the gross at the rate valid on the entry date — `VatCalculator` in
 *     gross mode, tax = gross × rate / (10000 + rate), commercial rounding (ADR-041 decision 5),
 *     PER PART; a voucher's own tax amount is taken only within {@see correctionLimit()};
 *   - the NET line is the side whose account is an EXPENSE or REVENUE account (owner 2026-09-22);
 *     neither side a P&L account → the code's category decides (input-tax codes Soll, every other
 *     Haben); both → refused (which side is net is not decidable — a Sammelbuchung carries it);
 *   - the net line carries the tax data, the tax line goes on the SAME side, on the mandator's
 *     VAT account of the category ({@see LedgerService::vatAccountFor()}); zero-rated and exempt
 *     codes put the code on the net line with tax 0.00 and write NO tax line, as does a taxed code
 *     whose tax rounds to 0.00; reverse-charge codes are refused (tax on top to both sides is not
 *     a gross split);
 *   - signs: base and tax are positive when the net line stands on the code's NATURAL side
 *     (Soll for an input code, Haben for every other), negated otherwise.
 *
 * Lines in order: per part its line(s) — [net Soll, tax Soll] or, net on the counter side,
 * [gross Soll, net Haben, tax Haben] — then ONE counter line with the sum of the parts whose net
 * stood on the Soll side. One part reproduces the one-line entry's canonical order exactly.
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
     * limit there is 0.30. A different tax belongs in a Sammelbuchung.
     */
    public const TAX_CORRECTION_MAX     = '1.00';
    public const TAX_CORRECTION_MIN     = '0.05';
    public const TAX_CORRECTION_PERCENT = 1;

    /** Categories whose natural side is Soll (debit): input tax. Every other taxed category is Haben. */
    private const INPUT_CATEGORIES = [TaxCategory::InputMaterial, TaxCategory::InputOther];
    /** Categories posted with the code on the net line but without a tax line. */
    private const UNTAXED_CATEGORIES = [TaxCategory::Zero, TaxCategory::Exempt];

    public function __construct(
        private readonly string $currency,
        private readonly AccountRepository $accounts,
        private readonly TaxCodeRepository $codes,
        private readonly VatRates $rates,
        private readonly LedgerService $ledger,
    ) {}

    /** The builder in the installation's base currency — what a module uses. */
    public static function of(UnifiedEntityManager $em): self
    {
        return new self(
            LedgerService::baseCurrency(),
            $em->getRepository(Account::class),
            $em->getRepository(TaxCode::class),
            VatRates::from($em),
            new LedgerService($em),
        );
    }

    /**
     * The lines of $parts (Soll) against $counter (Haben) on $date.
     *
     * @param list<GrossPart> $parts
     * @param bool|null       $liableToVat the mandator's VAT liability: false refuses every tax code;
     *                                     null = not applied by this caller
     * @param list<string>    $keptCodes   codes accepted although inactive (an edited entry keeps its code)
     * @throws GrossEntryRefusedException
     */
    public function build(\DateTimeImmutable $date, string $counter, array $parts, ?bool $liableToVat = null, array $keptCodes = []): GrossEntry
    {
        if ($parts === []) {
            throw new GrossEntryRefusedException(GrossEntryRefusedException::ENTRY, 'Keine Zeile — mindestens ein Betrag ist nötig.');
        }
        $parts    = array_values($parts);
        $accounts = [];
        foreach ($parts as $i => $part) {
            $accounts[$i] = $this->resolve($part->account, GrossEntryRefusedException::ACCOUNT, $i);
        }
        $counterAccount = $this->resolve($counter, GrossEntryRefusedException::COUNTER, null);
        foreach ($parts as $i => $part) {
            if ($accounts[$i]->getNumber() === $counterAccount->getNumber()) {
                throw new GrossEntryRefusedException(GrossEntryRefusedException::COUNTER, 'Soll und Haben sind dasselbe Konto.', $i);
            }
            foreach ([$part->gross, $part->voucherTax] as $amount) {
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
        }

        $splits = [];
        foreach ($parts as $i => $part) {
            $splits[$i] = $part->taxCode === null ? null : $this->split($part, $i, $date, $accounts[$i], $counterAccount, $keptCodes);
        }

        return new GrossEntry($this->lines($parts, $splits, $accounts, $counterAccount->getNumber()), $splits);
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

    /** min(MAX, max(MIN, PERCENT % of the computed tax)) — see {@see TAX_CORRECTION_MAX}. */
    private function correctionLimit(Money $computed): Money
    {
        $max   = Money::fromDecimal(self::TAX_CORRECTION_MAX, $this->currency);
        $min   = Money::fromDecimal(self::TAX_CORRECTION_MIN, $this->currency);
        $share = $computed->multiplyByRatio(self::TAX_CORRECTION_PERCENT, 100);
        $limit = $share->greaterThan($min) ? $share : $min;

        return $limit->greaterThan($max) ? $max : $limit;
    }

    private function resolve(string $value, string $field, ?int $part): Account
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

        return $account;
    }

    /**
     * The VAT of one part: code checked, the net side decided, rate by the date, the tax computed
     * in gross mode or taken from the voucher within the limit, the tax account by the category.
     *
     * @param list<string> $keptCodes
     * @throws GrossEntryRefusedException
     */
    private function split(GrossPart $part, int $i, \DateTimeImmutable $date, Account $debit, Account $credit, array $keptCodes): GrossSplit
    {
        $refuse = static fn(string $field, string $message, ?\Throwable $previous = null) => new GrossEntryRefusedException($field, $message, $i, $previous);
        $gross  = $part->gross;

        $code = $this->codes->findByCode($part->taxCode);
        if ($code === null) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'MWST-Code ' . $part->taxCode . ' gibt es nicht.');
        }
        if (!$code->isActive() && !in_array($code->getCode(), $keptCodes, true)) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'MWST-Code ' . $code->getCode() . ' ist inaktiv — nur bestehende Buchungen behalten ihn.');
        }
        $category = $code->category();
        if ($category === TaxCategory::ReverseCharge || $category === null) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, $category === null
                ? 'MWST-Code ' . $code->getCode() . ' hat eine unbekannte Kategorie «' . $code->getCategory() . '».'
                : 'Bezugsteuer (' . $code->getCode() . ') wird auf beiden Seiten gebucht — als Sammelbuchung erfassen.');
        }
        $input = in_array($category, self::INPUT_CATEGORIES, true);

        // The net side: the P&L account (owner, 2026-09-22); neither → the category; both → refused.
        $debitPl  = self::isProfitAndLoss($debit);
        $creditPl = self::isProfitAndLoss($credit);
        if ($debitPl && $creditPl) {
            throw $refuse(GrossEntryRefusedException::ENTRY, 'Soll und Haben sind beides Aufwand- oder Ertragskonten — welche Seite netto ist, ist nicht eindeutig. Als Sammelbuchung erfassen.');
        }
        $netOnDebit = $debitPl || (!$creditPl && $input);

        try {
            $result = (new VatCalculator($this->rates))->calculate($this->currency, [new VatLine('gross-part', $gross, $code->getCode())], $date, PriceMode::Gross);
        } catch (VatException $e) {
            throw $refuse(GrossEntryRefusedException::TAX_CODE, 'Für ' . $code->getCode() . ' gilt am ' . $date->format('d.m.Y') . ' kein Satz.', $e);
        }
        $rate     = $result->lines[0]->rate->rate;
        $computed = $result->tax();
        $untaxed  = in_array($category, self::UNTAXED_CATEGORIES, true);

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
            $limit     = $this->correctionLimit($computed);
            if ($deviation->greaterThan($limit) || $deviation->negate()->greaterThan($limit)) {
                throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'Steuerbetrag ' . AmountFormat::of($entered) . ' weicht um ' . AmountFormat::of($deviation) . ' vom berechneten '
                    . AmountFormat::of($computed) . ' ab — erlaubt sind höchstens ' . AmountFormat::of($limit) . ' (Rundung laut Beleg). Andere Steuer als Sammelbuchung erfassen.');
            }
            $tax = $entered;
        }
        if (!$tax->lessThan($gross)) {
            throw $refuse(GrossEntryRefusedException::TAX_AMOUNT, 'Die Steuer muss kleiner als der Betrag sein.');
        }

        $account = null;
        if (!$untaxed) {
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
        }

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
     * The lines in the documented order (class comment).
     *
     * @param list<GrossPart>        $parts
     * @param list<GrossSplit|null>  $splits
     * @param list<Account>          $accounts
     * @return list<PostingLine>
     */
    private function lines(array $parts, array $splits, array $accounts, string $counter): array
    {
        $lines   = [];
        $onCount = Money::zero($this->currency);   // the gross the counter line carries
        foreach ($parts as $i => $part) {
            $account = $accounts[$i]->getNumber();
            $split   = $splits[$i];
            if ($split === null) {
                $lines[] = PostingLine::debit($account, $part->gross, $part->text);
                $onCount = $onCount->add($part->gross);
                continue;
            }
            $taxData = [$split->code, $split->rate, $split->natural ? $split->net : $split->net->negate(), $split->natural ? $split->tax : $split->tax->negate()];
            if ($split->netOnDebit) {
                $lines[] = PostingLine::debit($account, $split->net, $part->text, ...$taxData);
                if ($split->account !== null) {
                    $lines[] = PostingLine::debit($split->account, $split->tax);
                }
                $onCount = $onCount->add($part->gross);
                continue;
            }
            $lines[] = PostingLine::debit($account, $part->gross, $part->text);
            $lines[] = PostingLine::credit($counter, $split->net, null, ...$taxData);
            if ($split->account !== null) {
                $lines[] = PostingLine::credit($split->account, $split->tax);
            }
        }
        if ($onCount->isPositive()) {
            $lines[] = PostingLine::credit($counter, $onCount);
        }

        return $lines;
    }

    private static function isProfitAndLoss(Account $account): bool
    {
        return in_array(AccountType::tryFrom($account->getType()), [AccountType::Expense, AccountType::Revenue], true);
    }
}
