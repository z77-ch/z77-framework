<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Calculation\VatCalculator;
use Z77\Module\Vat\Calculation\VatLine;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Services\VatException;
use Z77\Module\Vat\Services\VatRates;
use Z77\Shared\Money\Money;

/**
 * The ONE-LINE manual entry — the default capture form (owner, 2026-09-22;
 * P2 exit check 3b): `Soll | Datum | Bu-Nr | Text | Haben | Betrag`, the wdv
 * row. The Soll account receives, the Haben account gives, the amount is the
 * GROSS amount of the voucher or the bank. The Bu-Nr is the journal number,
 * drawn gaplessly by the ledger at post time — the form shows «neu» and never
 * predicts or reserves one. Real splits go to the multi-line form
 * («Sammelbuchung», {@see ManualEntryForm}).
 *
 * Optional VAT row (a checkbox reveals it with CSS only — Rule 7): a tax code
 * and a tax amount. The system then writes the tax line itself:
 *
 *   - the tax is COMPUTED from the gross amount at the rate valid on the
 *     entry date — `VatCalculator` in gross mode, tax = gross × rate /
 *     (10000 + rate), commercial rounding (ADR-041 decision 5); never by hand;
 *   - an ENTERED tax amount is the voucher's value and is taken as is, but
 *     only within {@see correctionLimit()} of the computed one: it exists to
 *     match the voucher's rounding, not to post arbitrary tax;
 *   - the NET line is the side whose account is an EXPENSE or REVENUE account
 *     (owner, 2026-09-22) — Soll or Haben. It carries the tax data; the tax
 *     line (the mandator's VAT account of the category) goes on the SAME side; the other
 *     side takes the gross. So a purchase 6500/1020 puts the net on 6500, a
 *     customer credit note 3200/1100 puts the net on 3200 in Soll and the
 *     output VAT on 2200 in Soll, a supplier refund 1020/4200 the net on 4200
 *     in Haben and the input tax on 1170 in Haben;
 *   - neither side a P&L account (a fixed asset 1500/1020): the tax-code
 *     category decides — input-tax codes (`input-material`, `input-other`)
 *     Soll, every other code Haben;
 *   - both sides P&L accounts: refused — which one is net is not decidable,
 *     the Sammelbuchung carries it;
 *   - zero-rated and exempt codes (`zero`, `exempt`): the code on the net line
 *     with base = gross and tax 0.00 (the return needs the turnover per code)
 *     and NO tax line; an entered tax other than 0 is refused. A taxed code
 *     whose computed tax is 0.00 (a few Rappen) writes no tax line either;
 *   - reverse-charge codes are refused here: Bezugsteuer posts tax on top of
 *     the amount to BOTH sides (owed and deducted), which is not a gross
 *     split — the Sammelbuchung carries it.
 *
 * Signs follow {@see ManualEntryForm} exactly: base and amount are positive
 * when the net line stands on the code's NATURAL side (debit for an input
 * code, credit for every other), negative on the opposite side — a credit
 * note negates them, and the VAT return (P5) sums them per code + rate.
 *
 * The tax account comes from the MANDATOR record by the code's category
 * ({@see LedgerService::vatAccountFor()} — owner decision E2, 2026-09-23;
 * before that `financialConfig → vatAccounts`); a missing number or an
 * account that is not a posting target ({@see LedgerService::accountExists()})
 * is refused with a message naming the mandator screen.
 *
 * The form only builds a manual {@see PostingRequest}; the write path is
 * `ManualEntryService` like every manual entry (no new write path, no
 * ledger rule of its own).
 */
final class OneLineEntryForm
{
    /**
     * How far an entered tax amount may deviate from the computed one
     * (orchestrator decision 2026-09-22, review of the one-line entry): the
     * limit is min(MAX, max(MIN, PERCENT % of the computed tax)), in the base
     * currency. It is for VOUCHER ROUNDING only — a few Rappen against the
     * rate-exact value; a flat franc would let 0.00 pass on a 10.00 purchase.
     * More than that is a different tax, which belongs in a Sammelbuchung.
     *
     * PERCENT went from 10 to 1 on 2026-09-23 (owner decision, P2 exit check
     * case 7 in z77.ch). At 10 % the percentage only ever tightened a tax
     * BELOW 10.00 — above it the flat MAX of 1.00 won, so `400.00 VM` allowed
     * a full franc and accepted the wdv value 30.80 (400 × 7.7 % on top, the
     * old rate) against the correct 29.97: 0.83 off, waved through in silence.
     * That is the very error this form was built to end. At 1 % the limit
     * there is 0.30 — still generous for the Rappen a per-line voucher
     * rounding produces, and closed against a different rate or a tax on top.
     */
    public const TAX_CORRECTION_MAX     = '1.00';
    public const TAX_CORRECTION_MIN     = '0.05';
    public const TAX_CORRECTION_PERCENT = 1;

    /** Categories whose natural side is Soll (debit): input tax. Every other taxed category is Haben. */
    private const INPUT_CATEGORIES = [TaxCategory::InputMaterial, TaxCategory::InputOther];
    /** Categories posted with the code on the net line but without a tax line. */
    private const UNTAXED_CATEGORIES = [TaxCategory::Zero, TaxCategory::Exempt];

    private string $debit = '';
    private string $date = '';
    private string $text = '';
    private string $credit = '';
    private string $amount = '';
    private bool $vat = false;
    private string $taxCode = '';
    private string $taxAmount = '';

    /** @var array<string, string> field → message */
    private array $errors = [];

    /** @var list<string> */
    private array $generalErrors = [];

    /** What the system will post / posted for the VAT row — shown under it. */
    private string $vatHint = '';

    /** The gross-mode tax of the last validation — the edit decides by it whether the stored tax is a voucher value. */
    private ?Money $computedTax = null;

    /** @var array<string, true> codes the edited entry carries — still selectable when deactivated */
    private array $keptCodes = [];

    public function __construct(
        private readonly string $currency,
        private readonly AccountRepository $accounts,
        private readonly TaxCodeRepository $codes,
        private readonly VatRates $rates,
        private readonly LedgerService $ledger,
    ) {}

    /** A blank row for a new entry on $date. */
    public function startBlank(\DateTimeImmutable $date): self
    {
        $this->date = $date->format('Y-m-d');

        return $this;
    }

    /**
     * Pre-fill from an existing manual entry when it is EXACTLY what this form
     * writes: the fields are read from the lines in the canonical order the
     * form writes them, and the form must rebuild the stored lines 1:1 (same
     * order, sides, amounts, tax data, no line text, active accounts, the
     * current VAT accounts of the mandator). Anything else → false, and the caller shows
     * the Sammelbuchung — the one-line form never silently re-sorts or
     * rewrites an entry.
     *
     * The tax amount is pre-filled only when the stored value differs from
     * the gross-mode value at the entry date — then it is a voucher value.
     * Otherwise the field stays empty («berechnet») and the hint shows the
     * stored value, so a changed amount recomputes the tax.
     */
    public function startFrom(JournalEntry $entry): bool
    {
        $fields = self::fieldsOf($entry);
        if ($fields === null) {
            return false;
        }
        $this->date      = $entry->getDate()->format('Y-m-d');
        $this->text      = $entry->getText();
        [$this->debit, $this->credit, $this->amount, $this->taxCode, $this->taxAmount] = $fields;
        $this->vat       = $this->taxCode !== '';
        if ($this->vat) {
            $this->keptCodes[$this->taxCode] = true;
        }

        $rebuilt = $this->toRequest();
        if ($rebuilt === null || $rebuilt->fingerprint()['lines'] !== PostingRequest::fingerprintOf($entry->snapshot())['lines']) {
            $this->debit = $this->credit = $this->amount = $this->taxCode = $this->taxAmount = $this->text = '';
            $this->vat = false;
            $this->errors = $this->generalErrors = [];
            $this->vatHint = '';

            return false;
        }
        if ($this->vat && $this->computedTax !== null && $this->taxAmount === $this->computedTax->toDecimal()) {
            $this->taxAmount = '';
            $this->vatHint   = 'Gespeichert: ' . $this->vatHint;
        }

        return true;
    }

    /**
     * The one-line fields read from the lines in the form's canonical order:
     * 2 lines [Soll, Haben]; 3 lines [net Soll, tax Soll, gross Haben] or
     * [gross Soll, net Haben, tax Haben]. Only the taxed line may carry tax
     * data. Null for any other layout.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null debit, credit, gross, tax code, tax amount (magnitude)
     */
    private static function fieldsOf(JournalEntry $entry): ?array
    {
        $lines = $entry->getLines();
        $isDebit = static fn(JournalLine $l) => $l->getDebit()->isPositive();
        $taxed   = array_values(array_filter($lines, static fn(JournalLine $l) => $l->hasTax()));
        if (count($taxed) > 1) {
            return null;
        }
        if (count($lines) === 2 && $isDebit($lines[0]) && !$isDebit($lines[1])) {
            [$soll, $haben, $gross, $net] = [$lines[0], $lines[1], $lines[0]->getDebit(), $taxed[0] ?? null];
        } elseif (count($lines) === 3 && $lines[0]->hasTax() && $isDebit($lines[0]) && $isDebit($lines[1]) && !$isDebit($lines[2])) {
            [$soll, $haben, $gross, $net] = [$lines[0], $lines[2], $lines[2]->getCredit(), $lines[0]];
        } elseif (count($lines) === 3 && $lines[1]->hasTax() && $isDebit($lines[0]) && !$isDebit($lines[1]) && !$isDebit($lines[2])) {
            [$soll, $haben, $gross, $net] = [$lines[0], $lines[1], $lines[0]->getDebit(), $lines[1]];
        } else {
            return null;
        }
        $tax = $net?->getTaxAmount();

        return [
            $soll->getAccount()->getNumber(),
            $haben->getAccount()->getNumber(),
            $gross->toDecimal(),
            (string) $net?->getTaxCode(),
            $tax === null ? '' : ($tax->isNegative() ? $tax->negate() : $tax)->toDecimal(),
        ];
    }

    /**
     * The form as posted: `debit`, `date`, `text`, `credit`, `amount`, `vat`
     * (the checkbox — present = checked), `tax_code`, `tax_amount`.
     *
     * @param array<string, mixed> $post
     */
    public function fromPost(array $post): self
    {
        $field = static fn(string $key): string => is_string($post[$key] ?? null) ? trim($post[$key]) : '';

        $this->debit     = $field('debit');
        $this->date      = $field('date');
        $this->text      = $field('text');
        $this->credit    = $field('credit');
        $this->amount    = $field('amount');
        $this->vat       = $field('vat') !== '';
        $this->taxCode   = $field('tax_code');
        $this->taxAmount = $field('tax_amount');

        return $this;
    }

    /** Validate and build the request; null with the errors set when anything is wrong. */
    public function toRequest(): ?PostingRequest
    {
        $this->errors = $this->generalErrors = [];
        $this->vatHint = '';
        $this->computedTax = null;

        $date = ManualEntryForm::parseDate($this->date);
        if ($date === null) {
            $this->errors['date'] = 'Datum ist ein Pflichtfeld (gültiges Datum).';
        }
        if ($this->text === '') {
            $this->errors['text'] = 'Buchungstext ist ein Pflichtfeld.';
        } elseif (mb_strlen($this->text) > JournalEntry::TEXT_LENGTH) {
            $this->errors['text'] = 'Buchungstext darf maximal ' . JournalEntry::TEXT_LENGTH . ' Zeichen lang sein.';
        }
        $debit  = $this->account('debit', $this->debit);
        $credit = $this->account('credit', $this->credit);
        if ($debit !== null && $credit !== null && $debit === $credit) {
            $this->errors['credit'] = 'Soll und Haben sind dasselbe Konto.';
        }
        $gross = ManualEntryForm::parseAmount($this->amount, $this->currency);
        if ($this->amount === '') {
            $this->errors['amount'] = 'Betrag fehlt.';
        } elseif ($gross === null) {
            $this->errors['amount'] = 'Kein Betrag (z.B. 100.50, höchstens ' . ManualEntryForm::AMOUNT_INTEGER_DIGITS . ' Stellen vor dem Punkt).';
        } elseif (!$gross->isPositive()) {
            $this->errors['amount'] = 'Der Betrag muss grösser als 0 sein — für die Gegenrichtung Soll und Haben tauschen.';
        }

        $tax = null;
        if ($this->vat && $this->taxCode === '') {
            $this->errors['tax_code'] = 'MWST-Code wählen — oder die MwSt-Zeile ausschalten.';
        } elseif ($this->vat && $date !== null && $gross !== null && $gross->isPositive() && $debit !== null && $credit !== null) {
            $tax = $this->tax($gross, $date, $debit, $credit);
        }
        if ($this->hasErrors() || $date === null || $debit === null || $credit === null || $gross === null) {
            return null;
        }

        try {
            return PostingRequest::manual($date, $this->text, $this->lines($debit->getNumber(), $credit->getNumber(), $gross, $tax));
        } catch (\InvalidArgumentException $e) {
            $this->generalErrors[] = $e->getMessage();

            return null;
        }
    }

    /**
     * The VAT of the row: code checked, the net side decided, rate by the
     * entry date, the tax computed in gross mode or taken from the voucher
     * within the limit, the tax account by the category. Null with the
     * errors set when refused.
     *
     * @return array{code: string, rate: int, netOnDebit: bool, natural: bool, net: Money, tax: Money, account: ?string}|null
     */
    private function tax(Money $gross, \DateTimeImmutable $date, Account $debit, Account $credit): ?array
    {
        $code = $this->codes->findByCode($this->taxCode);
        if ($code === null) {
            $this->errors['tax_code'] = 'MWST-Code ' . $this->taxCode . ' gibt es nicht.';

            return null;
        }
        if (!$code->isActive() && !isset($this->keptCodes[$code->getCode()])) {
            $this->errors['tax_code'] = 'MWST-Code ' . $code->getCode() . ' ist inaktiv — nur bestehende Buchungen behalten ihn.';

            return null;
        }
        $category = $code->category();
        if ($category === TaxCategory::ReverseCharge || $category === null) {
            $this->errors['tax_code'] = $category === null
                ? 'MWST-Code ' . $code->getCode() . ' hat eine unbekannte Kategorie «' . $code->getCategory() . '».'
                : 'Bezugsteuer (' . $code->getCode() . ') wird auf beiden Seiten gebucht — als Sammelbuchung erfassen.';

            return null;
        }
        $input = in_array($category, self::INPUT_CATEGORIES, true);

        // The net side: the P&L account (owner, 2026-09-22); neither → the category; both → refused.
        $debitPl  = self::isProfitAndLoss($debit);
        $creditPl = self::isProfitAndLoss($credit);
        if ($debitPl && $creditPl) {
            $this->generalErrors[] = 'Soll und Haben sind beides Aufwand- oder Ertragskonten — welche Seite netto ist, ist nicht eindeutig. Als Sammelbuchung erfassen.';

            return null;
        }
        $netOnDebit = $debitPl || (!$creditPl && $input);

        try {
            $result = (new VatCalculator($this->rates))->calculate($this->currency, [new VatLine('one-line', $gross, $code->getCode())], $date, PriceMode::Gross);
        } catch (VatException) {
            $this->errors['tax_code'] = 'Für ' . $code->getCode() . ' gilt am ' . $date->format('d.m.Y') . ' kein Satz.';

            return null;
        }
        $rate     = $result->lines[0]->rate->rate;
        $computed = $result->tax();
        $this->computedTax = $computed;
        $untaxed  = in_array($category, self::UNTAXED_CATEGORIES, true);

        $tax = $computed;
        if ($this->taxAmount !== '') {
            $entered = ManualEntryForm::parseAmount($this->taxAmount, $this->currency);
            if ($entered === null || $entered->isNegative()) {
                $this->errors['tax_amount'] = 'Kein Steuerbetrag (z.B. 29.97) — leer lassen, um ihn zu berechnen.';

                return null;
            }
            if ($untaxed && !$entered->isZero()) {
                $this->errors['tax_amount'] = 'MWST-Code ' . $code->getCode() . ' ist steuerfrei — kein Steuerbetrag.';

                return null;
            }
            if ($entered->isZero() && !$computed->isZero()) {
                $this->errors['tax_amount'] = 'Steuer 0.00 geht nur, wenn die berechnete Steuer 0.00 ist (hier ' . AmountFormat::of($computed) . ') — sonst die MwSt-Zeile ausschalten.';

                return null;
            }
            $deviation = $entered->subtract($computed);
            $limit     = $this->correctionLimit($computed);
            if ($deviation->greaterThan($limit) || $deviation->negate()->greaterThan($limit)) {
                $this->errors['tax_amount'] = 'Steuerbetrag ' . AmountFormat::of($entered) . ' weicht um ' . AmountFormat::of($deviation) . ' vom berechneten '
                    . AmountFormat::of($computed) . ' ab — erlaubt sind höchstens ' . AmountFormat::of($limit) . ' (Rundung laut Beleg). Andere Steuer als Sammelbuchung erfassen.';

                return null;
            }
            $tax = $entered;
        }
        if (!$tax->lessThan($gross)) {
            $this->errors['tax_amount'] = 'Die Steuer muss kleiner als der Betrag sein.';

            return null;
        }

        $account = null;
        if (!$untaxed) {
            try {
                $account = $this->ledger->vatAccountFor($category->value);
            } catch (VatAccountUnavailableException $e) {
                // A leftover config key or an unreadable mandator: the sentence says what to do (review 2026-09-23, P5/P6).
                $this->generalErrors[] = $e->getMessage();

                return null;
            }
            if ($account === null) {
                $this->generalErrors[] = 'Für MWST-Kategorie «' . $category->value . '» (Code ' . $code->getCode() . ') ist kein Steuerkonto hinterlegt — ' . LedgerService::MANDATOR_HINT . '.';

                return null;
            }
            if (!$this->ledger->accountExists($account)) {
                $this->generalErrors[] = 'Das Steuerkonto ' . $account . ' (Mandant, Kategorie ' . $category->value . ') gibt es nicht, oder es ist eine Gruppe oder inaktiv — ' . LedgerService::MANDATOR_HINT . '.';

                return null;
            }
        }
        $net = $gross->subtract($tax);
        $this->vatHint = 'MWST ' . $code->getCode() . ' ' . ManualEntryForm::percent($rate) . ' in ' . AmountFormat::of($gross) . ': '
            . AmountFormat::of($tax) . ($tax->equals($computed) ? ' (berechnet)' : ' (laut Beleg, berechnet ' . AmountFormat::of($computed) . ')')
            . ' — netto ' . AmountFormat::of($net) . ' im ' . ($netOnDebit ? 'Soll' : 'Haben')
            . ($tax->isZero() || $account === null ? ', keine Steuerzeile' : ', Steuerzeile auf ' . $account);

        return [
            'code'       => $code->getCode(),
            'rate'       => $rate,
            'netOnDebit' => $netOnDebit,
            'natural'    => $netOnDebit === $input,   // the net line on the code's natural side → positive base and amount
            'net'        => $net,
            'tax'        => $tax,
            'account'    => $tax->isZero() ? null : $account,
        ];
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

    /**
     * The posting lines in the canonical order {@see fieldsOf()} reads back:
     * [Soll, Haben] without VAT; [net Soll, tax Soll, gross Haben] or
     * [gross Soll, net Haben, tax Haben] with it.
     *
     * @param array{code: string, rate: int, netOnDebit: bool, natural: bool, net: Money, tax: Money, account: ?string}|null $tax
     * @return list<PostingLine>
     */
    private function lines(string $debit, string $credit, Money $gross, ?array $tax): array
    {
        if ($tax === null) {
            return [PostingLine::debit($debit, $gross), PostingLine::credit($credit, $gross)];
        }
        $taxData = [$tax['code'], $tax['rate'], $tax['natural'] ? $tax['net'] : $tax['net']->negate(), $tax['natural'] ? $tax['tax'] : $tax['tax']->negate()];
        if ($tax['netOnDebit']) {
            $lines = [PostingLine::debit($debit, $tax['net'], null, ...$taxData)];
            if ($tax['account'] !== null) {
                $lines[] = PostingLine::debit($tax['account'], $tax['tax']);
            }
            $lines[] = PostingLine::credit($credit, $gross);

            return $lines;
        }
        $lines = [PostingLine::debit($debit, $gross), PostingLine::credit($credit, $tax['net'], null, ...$taxData)];
        if ($tax['account'] !== null) {
            $lines[] = PostingLine::credit($tax['account'], $tax['tax']);
        }

        return $lines;
    }

    private static function isProfitAndLoss(Account $account): bool
    {
        return in_array(AccountType::tryFrom($account->getType()), [AccountType::Expense, AccountType::Revenue], true);
    }

    /**
     * An account field: the number (a leading number is enough — «1020 Bank»
     * as the datalist may show it is read as 1020), existing, postable and
     * active. Null with the field error set otherwise.
     */
    private function account(string $field, string $value): ?Account
    {
        if ($value === '') {
            $this->errors[$field] = 'Konto fehlt.';

            return null;
        }
        if (!preg_match('/^([0-9]{1,' . Account::NUMBER_LENGTH . '})(\s.*)?$/u', $value, $m)) {
            $this->errors[$field] = 'Kontonummer eingeben (z.B. 1020).';

            return null;
        }
        $account = $this->accounts->findOneBy(['number' => $m[1]]);
        if ($account === null) {
            $this->errors[$field] = 'Konto ' . $m[1] . ' gibt es nicht.';
        } elseif (!$account->isPostable()) {
            $this->errors[$field] = '«' . $account->label() . '» ist eine Gruppe — nicht bebuchbar.';
        } elseif (!$account->isActive()) {
            $this->errors[$field] = '«' . $account->label() . '» ist inaktiv.';
        } else {
            return $account;
        }

        return null;
    }

    /** A refusal from the service (period closed, …), shown above the row. */
    public function addGeneralError(string $message): void
    {
        $this->generalErrors[] = $message;
    }

    public function debit(): string { return $this->debit; }
    public function date(): string { return $this->date; }
    public function text(): string { return $this->text; }
    public function credit(): string { return $this->credit; }
    public function amount(): string { return $this->amount; }
    public function vat(): bool { return $this->vat; }
    public function taxCode(): string { return $this->taxCode; }
    public function taxAmount(): string { return $this->taxAmount; }
    public function vatHint(): string { return $this->vatHint; }

    public function error(string $field): string { return $this->errors[$field] ?? ''; }

    /** @return list<string> */
    public function generalErrors(): array { return $this->generalErrors; }

    public function hasErrors(): bool
    {
        return $this->errors !== [] || $this->generalErrors !== [];
    }

    /**
     * The name of the account a field names, for the muted line under it
     * («Bankguthaben») — empty when the field is empty or names nothing.
     */
    public function accountName(string $value): string
    {
        if (!preg_match('/^([0-9]{1,' . Account::NUMBER_LENGTH . '})\b/', $value, $m)) {
            return '';
        }

        return $this->accounts->findOneBy(['number' => $m[1]])?->getName() ?? '';
    }

    /**
     * The accounts a field may name — postable AND active, in chart order,
     * for the shared `<datalist>`.
     *
     * @return list<Account>
     */
    public function postableAccounts(): array
    {
        return array_values(array_filter($this->accounts->allInOrder(), static fn(Account $a) => $a->isPostable() && $a->isActive()));
    }

    /**
     * The tax codes the VAT row offers: active codes that split a gross
     * amount (no reverse-charge), plus the edited entry's own code. Sorted by
     * code.
     *
     * @return list<TaxCode>
     */
    public function selectableCodes(): array
    {
        return array_values(array_filter($this->codes->allSorted(), fn(TaxCode $code) => ($code->isActive() || isset($this->keptCodes[$code->getCode()]))
            && $code->category() !== TaxCategory::ReverseCharge));
    }
}
