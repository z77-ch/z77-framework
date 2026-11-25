<?php
namespace Z77\Module\Financial\Ui;

use Z77\Shared\Money\AmountFormat;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
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
 * and a tax amount. The system then writes the tax line itself — the split is
 * {@see GrossEntryBuilder}'s (one gross part against the Haben account; the
 * same builder module-receipt and module-creditor post with), in short:
 *
 *   - the tax is COMPUTED from the gross amount at the rate valid on the
 *     entry date (gross mode, commercial rounding); never by hand;
 *   - an ENTERED tax amount is the voucher's value and is taken as is, but
 *     only within the builder's correction limit ({@see TAX_CORRECTION_MAX}):
 *     it exists to match the voucher's rounding, not to post arbitrary tax;
 *   - the NET line is the side whose account is an EXPENSE or REVENUE account
 *     (owner, 2026-09-22) — Soll or Haben — with the tax line on the SAME
 *     side. So a purchase 6500/1020 puts the net on 6500, a customer credit
 *     note 3200/1100 the net on 3200 in Soll and the output VAT on 2200 in
 *     Soll, a supplier refund 1020/4200 the net on 4200 in Haben and the input
 *     tax on 1170 in Haben; neither side P&L → the code's category decides;
 *     both → refused;
 *   - zero-rated and exempt codes: the code on the net line, NO tax line;
 *     reverse-charge codes are refused (two tax lines on both sides — the
 *     Sammelbuchung, a receipt or a creditor invoice carries them), the
 *     input-tax corrections VE / VK / VN too (the Sammelbuchung's only).
 *
 * The mandator's VAT accounts (ADR-042 addendum 2 of 2026-11-25, point 7):
 * from «Abrechnung ab» to «Abrechnung bis» z77 writes them only from the tax
 * codes — the datalist does not offer them on the entry's date
 * ({@see postableAccounts()}) and a Soll or Haben naming one is refused at the
 * field; an account the edited entry already carries is left to the ledger,
 * which lets an unchanged old entry through (owner S4-6). Without a
 * {@see VatAccountMap} (a caller that passes none) the form behaves as before
 * and only the ledger judges.
 *
 * The form checks its fields (and puts a refusal of the builder under the
 * field it is about); it only builds a manual {@see PostingRequest}; the write
 * path is `ManualEntryService` like every manual entry (no new write path, no
 * ledger rule of its own).
 */
final class OneLineEntryForm
{
    /** The voucher-tax limit — the builder's, named here for the help page ({@see GrossEntryBuilder::TAX_CORRECTION_MAX}). */
    public const TAX_CORRECTION_MAX     = GrossEntryBuilder::TAX_CORRECTION_MAX;
    public const TAX_CORRECTION_MIN     = GrossEntryBuilder::TAX_CORRECTION_MIN;
    public const TAX_CORRECTION_PERCENT = GrossEntryBuilder::TAX_CORRECTION_PERCENT;

    /** The builder's refusal field → the form field; ENTRY (and anything else) goes above the row. */
    private const FIELD_OF = [
        GrossEntryRefusedException::ACCOUNT    => 'debit',
        GrossEntryRefusedException::COUNTER    => 'credit',
        GrossEntryRefusedException::AMOUNT     => 'amount',
        GrossEntryRefusedException::TAX_CODE   => 'tax_code',
        GrossEntryRefusedException::TAX_AMOUNT => 'tax_amount',
    ];

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

    /** @var array<string, true> accounts the edited entry carries — judged by the ledger, not refused here as VAT accounts */
    private array $keptAccounts = [];

    private readonly GrossEntryBuilder $builder;

    /**
     * @param VatAccountMap|null $vatAccounts the mandator's VAT accounts and their lock; null = not checked here (the ledger still does)
     */
    public function __construct(
        private readonly string $currency,
        private readonly AccountRepository $accounts,
        private readonly TaxCodeRepository $codes,
        VatRates $rates,
        LedgerService $ledger,
        private readonly ?VatAccountMap $vatAccounts = null,
    ) {
        $this->builder = new GrossEntryBuilder($currency, $accounts, $codes, $rates, $ledger);
    }

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
        $this->keptAccounts = [$this->debit => true, $this->credit => true];

        $rebuilt = $this->toRequest();
        if ($rebuilt === null || $rebuilt->fingerprint()['lines'] !== PostingRequest::fingerprintOf($entry->snapshot())['lines']) {
            $this->debit = $this->credit = $this->amount = $this->taxCode = $this->taxAmount = $this->text = '';
            $this->vat = false;
            $this->errors = $this->generalErrors = [];
            $this->vatHint = '';
            $this->keptAccounts = [];

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
        $debit  = $this->unlockedAccount('debit', $this->account('debit', $this->debit), $date);
        $credit = $this->unlockedAccount('credit', $this->account('credit', $this->credit), $date);
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

        // The VAT row is checked together with the other fields, so its errors show beside theirs.
        $lines = null;
        if ($this->vat && $this->taxCode === '') {
            $this->errors['tax_code'] = 'MWST-Code wählen — oder die MwSt-Zeile ausschalten.';
        } elseif ($this->vat && ($refusal = $this->codeRefusal($this->taxCode)) !== null) {
            $this->errors['tax_code'] = $refusal;
        } elseif ($this->vat && $date !== null && $gross !== null && $gross->isPositive() && $debit !== null && $credit !== null && $debit !== $credit) {
            $lines = $this->lines($date, $debit, $credit, $gross);
        }
        if ($this->hasErrors() || $date === null || $debit === null || $credit === null || $gross === null) {
            return null;
        }
        $lines ??= $this->lines($date, $debit, $credit, $gross);
        if ($lines === null) {
            return null;
        }

        try {
            return PostingRequest::manual($date, $this->text, $lines);
        } catch (\InvalidArgumentException $e) {
            $this->generalErrors[] = $e->getMessage();

            return null;
        }
    }

    /**
     * The lines of the row from {@see GrossEntryBuilder} — one gross part (Soll) against the Haben
     * account; with the VAT row its code and the voucher's tax amount. A refusal goes under its
     * field (or above the row) and answers null; on success the hint says what is posted.
     *
     * @return list<PostingLine>|null
     */
    private function lines(\DateTimeImmutable $date, Account $debit, Account $credit, Money $gross): ?array
    {
        $voucher = null;
        $voucherError = false;
        if ($this->vat && $this->taxAmount !== '') {
            $voucher = ManualEntryForm::parseAmount($this->taxAmount, $this->currency);
            if ($voucher === null || $voucher->isNegative()) {
                // Checked after the code (as it always was): a wrong code is the first thing to fix.
                [$voucher, $voucherError] = [null, true];
            }
        }
        try {
            $entry = $this->builder->build(
                $date,
                $credit->getNumber(),
                [new GrossPart($debit->getNumber(), $gross, $this->vat ? $this->taxCode : null, null, $voucher)],
                null,
                array_keys($this->keptCodes),
            );
        } catch (GrossEntryRefusedException $e) {
            $field = self::FIELD_OF[$e->field] ?? null;
            if ($field === null) {
                $this->generalErrors[] = $e->getMessage();
            } else {
                $this->errors[$field] = $e->getMessage();
            }

            return null;
        }
        $split = $entry->splits[0];
        if ($voucherError) {
            $this->computedTax = $split?->computed;
            $this->errors['tax_amount'] = 'Kein Steuerbetrag (z.B. 29.97) — leer lassen, um ihn zu berechnen.';

            return null;
        }
        if ($split !== null) {
            $this->computedTax = $split->computed;
            $this->vatHint = 'MWST ' . $split->code . ' ' . ManualEntryForm::percent($split->rate) . ' in ' . AmountFormat::of($gross) . ': '
                . AmountFormat::of($split->tax) . ($split->tax->equals($split->computed) ? ' (berechnet)' : ' (laut Beleg, berechnet ' . AmountFormat::of($split->computed) . ')')
                . ' — netto ' . AmountFormat::of($split->net) . ' im ' . ($split->netOnDebit ? 'Soll' : 'Haben')
                . ($split->account === null ? ', keine Steuerzeile' : ', Steuerzeile auf ' . $split->account);
        }

        return $entry->lines;
    }

    /**
     * An account field: the number (a leading number is enough — «1020 Bank»
     * as the datalist may show it is read as 1020), existing, postable and
     * active ({@see GrossEntryBuilder::account()}). Null with the field error
     * set otherwise.
     */
    private function account(string $field, string $value): ?Account
    {
        try {
            return $this->builder->account($value);
        } catch (GrossEntryRefusedException $e) {
            $this->errors[$field] = $e->getMessage();

            return null;
        }
    }

    /**
     * $account unless it is one of the mandator's VAT accounts locked on $date — then null with the
     * field's sentence (board S4-3, part 4). An account the edited entry already carries passes: the
     * ledger judges an old entry (unchanged lines pass, changed ones must be consistent — S4-6).
     */
    private function unlockedAccount(string $field, ?Account $account, ?\DateTimeImmutable $date): ?Account
    {
        if ($account === null || $date === null || isset($this->keptAccounts[$account->getNumber()])
            || !isset($this->lockedAccounts($date)[$account->getNumber()])) {
            return $account;
        }
        $this->errors[$field] = 'MWST-Konto ' . $account->getNumber() . ' — die Steuerzeile schreibt z77 (den MWST-Code auf die Aufwandzeile setzen). Einfuhrsteuer und Korrekturen als Sammelbuchung.';

        return null;
    }

    /**
     * The mandator's VAT accounts locked on $date, as a set of account numbers — empty without a
     * {@see VatAccountMap} or while no lock applies.
     *
     * @return array<string, true>
     */
    private function lockedAccounts(\DateTimeImmutable $date): array
    {
        if ($this->vatAccounts === null) {
            return [];
        }

        // The map's keys are account numbers — PHP turns the numeric ones into int.
        return array_fill_keys(array_map('strval', array_keys($this->vatAccounts->lockedAccounts($date))), true);
    }

    /**
     * The sentence that refuses $taxCode in the VAT row, or null: a reverse-charge code goes on both
     * sides (the Sammelbuchung, a receipt or a creditor invoice carries it), an input-tax correction
     * (VE / VK / VN) is the Sammelbuchung's only. Everything else is the builder's to judge.
     */
    private function codeRefusal(string $taxCode): ?string
    {
        $code     = $this->codes->findByCode($taxCode);
        $category = $code?->category();
        if ($category === TaxCategory::ReverseCharge) {
            return 'Bezugsteuer (' . $code->getCode() . ') wird auf beiden Seiten gebucht — als Sammelbuchung, Beleg oder Kreditorenrechnung erfassen.';
        }
        if ($category?->isManualOnly() === true) {
            return 'MWST-Code ' . $code->getCode() . ' ist eine Vorsteuerkorrektur — als Sammelbuchung erfassen.';
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
     * for the shared `<datalist>` — without the mandator's VAT accounts locked
     * on the entry's date (today while the date field is empty or invalid);
     * the edited entry's own accounts stay offered.
     *
     * @return list<Account>
     */
    public function postableAccounts(): array
    {
        $locked = array_diff_key($this->lockedAccounts(ManualEntryForm::parseDate($this->date) ?? new \DateTimeImmutable('today')), $this->keptAccounts);

        return array_values(array_filter($this->accounts->allInOrder(), static fn(Account $a) => $a->isPostable() && $a->isActive() && !isset($locked[$a->getNumber()])));
    }

    /**
     * The tax codes the VAT row offers: active codes that split a gross
     * amount (no reverse-charge, no input-tax correction — `selectable()`
     * leaves those out), plus the edited entry's own code. Sorted by code.
     *
     * @return list<TaxCode>
     */
    public function selectableCodes(): array
    {
        return array_values(array_filter($this->codes->selectable(array_keys($this->keptCodes)), fn(TaxCode $code) => $code->category() !== TaxCategory::ReverseCharge));
    }
}
