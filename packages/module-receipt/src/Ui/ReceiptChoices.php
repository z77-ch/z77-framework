<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Services\LedgerService,
    Z77\Module\Receipt\Services\ReceiptFields,
    Z77\Module\Vat\Entities\TaxCategory,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Persistence\Resolver\UnifiedEntityManager
;

/**
 * What the receipt screens OFFER in their account and VAT fields — the receipt window, the
 * template window and the payment-method window read the same lists, and module-creditor's
 * profile and invoice windows too:
 *
 *   - {@see accounts()} — the accounts a field may name (postable and active, in chart order), for
 *     the shared `<datalist>` (the one-line entry's choice, `OneLineEntryForm::postableAccounts()`) —
 *     without the mandator's VAT accounts while they are locked (from «Abrechnung ab» on z77 writes
 *     them from the tax codes only, ADR-042 addendum 2 of 2026-11-25, point 7);
 *     {@see offeredAccount()} drops such an account from a stored default when it is READ (a
 *     template, a learned creditor account: the field loads empty);
 *   - {@see taxCodes()} — the codes a receipt may carry: active (plus the record's own), reverse
 *     charge only when the caller opts in (`$reverseCharge`; by default `ReceiptFields::taxCode()`
 *     refuses it), never an input-tax correction VE / VK / VN (the Sammelbuchung's only);
 *   - {@see taxChoices()} — the VAT select as option GROUPS (board S5-1, part 2: «ohne MWST»,
 *     «Vorsteuer», «Nur Steuer», «Umsatzsteuer»; «Bezugsteuer» opt-in, stage S6), the ONE building
 *     block of the receipt window, the template window and the creditor's invoice lines (Rule 9);
 *     its values are what `ReceiptFields::vatChoice()` parses.
 *
 * An offer only: the save judges every value again with `ReceiptFields` (Rule 7).
 */
final class ReceiptChoices
{
    /** The VAT select's value for the explicit «ohne MWST» — never a tax code (codes are letters and digits). */
    public const WITHOUT_VAT = ReceiptFields::WITHOUT_VAT;

    /** The group label of the «nur Steuer» options (board S5-1, part 2). */
    public const GROUP_TAX_ONLY = 'Nur Steuer (Einfuhrsteuer, nachgeholte Vorsteuer)';

    /** The group label of the reverse-charge options (stage S6; the Sammelbuchung's select names it the same). */
    public const GROUP_REVERSE_CHARGE = 'Bezugsteuer';

    /** What «Soll» says under itself while «nur Steuer» locks it (board S5-1, parts 1 and 3). */
    public const AUTOMATIC = 'automatisch';

    /** The hidden `debit_kept` of a locked «Soll»: this mark + what was typed before the lock ({@see debitLock()}). */
    public const DEBIT_KEPT_MARK = '=';

    private ?ReceiptFields $fields = null;

    /** @var array<string, ?string> code → the account {@see taxOnlyAccount()} answered (read once per instance) */
    private array $taxOnlyAccounts = [];

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * @param \DateTimeImmutable|null $date the day the account is booked on (the receipt's date); null = today
     * @return list<Account>
     */
    public function accounts(?\DateTimeImmutable $date = null): array
    {
        $locked = array_fill_keys($this->fields()->lockedVatAccounts($date), true);

        return array_values(array_filter(
            $this->em->getRepository(Account::class)->allInOrder(),
            static fn(Account $a) => $a->isPostable() && $a->isActive() && !isset($locked[$a->getNumber()])
        ));
    }

    /**
     * A stored account as a field loads it: null when it names one of the mandator's VAT accounts
     * locked on $date (null = today) — a template or a learned default naming 1170 loads the field
     * empty (board S4-4, part 3); every other value unchanged.
     */
    public function offeredAccount(?string $number, ?\DateTimeImmutable $date = null): ?string
    {
        if ($number === null || trim($number) === '') {
            return $number;
        }

        return $this->fields()->vatAccountRefusal($number, $date) === null ? $number : null;
    }

    /** «Treibstoff» for '6210' (also «6210 Treibstoff»), '' when the number names no account. */
    public function accountName(?string $value): string
    {
        if ($value === null || !preg_match('/^([0-9]{1,' . Account::NUMBER_LENGTH . '})\b/', trim($value), $m)) {
            return '';
        }

        return $this->em->getRepository(Account::class)->findOneBy(['number' => $m[1]])?->getName() ?? '';
    }

    /**
     * @param list<string> $keep          the record's own code(s) — offered even when inactive
     * @param bool         $reverseCharge true = the reverse-charge codes too (opt-in, stage S6 — only a
     *                                    caller whose save takes them: `ReceiptFields::vatChoice(…, reverseCharge: true)`)
     * @return list<TaxCode>
     */
    public function taxCodes(array $keep = [], bool $reverseCharge = false): array
    {
        return array_values(array_filter(
            $this->em->getRepository(TaxCode::class)->selectable(array_values(array_filter($keep, static fn($c) => is_string($c) && $c !== ''))),
            static fn(TaxCode $code) => $code->category() !== null && ($reverseCharge || $code->category() !== TaxCategory::ReverseCharge) && !$code->category()->isManualOnly()
        ));
    }

    /**
     * The VAT select as option groups, in the board's order: «ohne MWST» (no group), «Vorsteuer»
     * (the input codes), «Nur Steuer (…)» (one «VM — nur Steuer» per input code — an own «VM2»
     * appears there by itself), «Bezugsteuer» (the reverse-charge codes of the country pack — BM, BI,
     * BM2, BI2 and an own code of that category — ONLY with `$reverseCharge`, default off: the save
     * of a caller that does not pass it refuses such a code), «Umsatzsteuer» (the output, zero-rated
     * and exempt codes). The corrections VE / VK / VN never. Every group of codes follows the same
     * rule — active plus `$keep` (`TaxCodeRepository::selectable()`), and only for a mandator liable
     * to VAT: one NOT liable gets «ohne MWST» only. Each option's `value` is what
     * `ReceiptFields::vatChoice()` parses (a reverse-charge option: its plain code, `BM`); a group
     * without an option is left out. The placeholder «— wählen —» is the template's.
     *
     * @param list<string> $keep          the record's own code(s) — offered even when inactive
     * @param bool         $reverseCharge true = offer the group «Bezugsteuer» (opt-in, default off)
     * @return list<array{group: string, options: list<array{value: string, label: string}>}> group '' = no `<optgroup>`
     */
    public function taxChoices(array $keep = [], bool $liableToVat = true, bool $reverseCharge = false): array
    {
        $groups = [['group' => '', 'options' => [['value' => ReceiptFields::WITHOUT_VAT, 'label' => 'ohne MWST']]]];
        if (!$liableToVat) {
            return $groups;
        }
        $input = $taxOnly = $rc = $output = [];
        foreach ($this->taxCodes($keep, $reverseCharge) as $code) {
            $label = $code->getCode() . ' — ' . $code->getLabel();
            if ($code->category() === TaxCategory::ReverseCharge) {
                $rc[] = ['value' => ReceiptFields::vatToken($code->getCode(), false), 'label' => $label];
            } elseif ($code->category()->allowsTaxOnly()) {
                $input[]   = ['value' => ReceiptFields::vatToken($code->getCode(), false), 'label' => $label];
                $taxOnly[] = ['value' => ReceiptFields::vatToken($code->getCode(), false, true), 'label' => $code->getCode() . ' — nur Steuer'];
            } else {
                $output[] = ['value' => ReceiptFields::vatToken($code->getCode(), false), 'label' => $label];
            }
        }
        foreach (['Vorsteuer' => $input, self::GROUP_TAX_ONLY => $taxOnly, self::GROUP_REVERSE_CHARGE => $rc, 'Umsatzsteuer' => $output] as $group => $options) {
            if ($options !== []) {
                $groups[] = ['group' => $group, 'options' => $options];
            }
        }

        return $groups;
    }

    /**
     * The account a «nur Steuer» receipt or template with $code posts on — the input account of the
     * code's category on the mandator (`LedgerService::vatAccountFor()`, the account the posting
     * derives: VM → 1170, VI → 1171). What «Soll · automatisch» SHOWS; nothing stores it. Null for a
     * code that takes no «nur Steuer», or while the mandator names no account.
     */
    public function taxOnlyAccount(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        $code = TaxCode::normalizeCode($code);
        if (array_key_exists($code, $this->taxOnlyAccounts)) {
            return $this->taxOnlyAccounts[$code];
        }
        $category = $this->em->getRepository(TaxCode::class)->findByCode($code)?->category();
        $account  = null;
        if ($category !== null && $category->allowsTaxOnly() && !$category->isManualOnly()) {
            try {
                $account = (new LedgerService($this->em))->vatAccountFor($category->value);
            } catch (\Throwable) {
                $account = null;   // an unreadable mandator or a leftover config key — the posting says why
            }
        }

        return $this->taxOnlyAccounts[$code] = $account;
    }

    /**
     * The core.js «fill» answer that locks «Soll» of the receipt window or the template window while
     * «nur Steuer» is chosen (S56-2; RECEIPT-TAXONLY-LOCK-001 — the one mechanism module-creditor uses
     * too, no script of its own) — DISPLAY ONLY: the save clears a typed Soll and the posting derives
     * the account (Rule 7). The window's fields, by `data-fill-key`:
     *
     *   - `debit` — «Soll»: locked, it shows the input account of the code ({@see taxOnlyAccount()},
     *     VM → 1170); freed, it gets back what was typed before the lock;
     *   - `debit_note` — the line under it: «automatisch» while locked, else the account's name;
     *   - `debit_kept` — a hidden field that keeps what was typed before the lock, as
     *     {@see DEBIT_KEPT_MARK} + value ('' = not locked; a second lock keeps the first value).
     *
     * $debit is what «Soll» holds now, $kept the hidden field — both ride along (`data-fill-with`).
     *
     * @return array{fill: array<string, string>, lock: array<string, bool>}
     */
    public function debitLock(string $token, string $debit, string $kept): array
    {
        $parts = ReceiptFields::vatTokenParts($token);
        if ($parts['taxOnly']) {
            return [
                'fill' => ['debit' => (string) $this->taxOnlyAccount($parts['code']), 'debit_note' => self::AUTOMATIC, 'debit_kept' => $kept !== '' ? $kept : self::DEBIT_KEPT_MARK . trim($debit)],
                'lock' => ['debit' => true],
            ];
        }
        if ($kept === '') {
            return ['fill' => [], 'lock' => ['debit' => false]];
        }
        $restored = substr($kept, strlen(self::DEBIT_KEPT_MARK));

        return ['fill' => ['debit' => $restored, 'debit_note' => $this->accountName($restored), 'debit_kept' => ''], 'lock' => ['debit' => false]];
    }

    /** «Vorsteuer Investitionen …» for 'VI', '' for an unknown code. */
    public function taxLabel(string $code): string
    {
        return $code === '' ? '' : ($this->em->getRepository(TaxCode::class)->findByCode($code)?->getLabel() ?? '');
    }

    private function fields(): ReceiptFields
    {
        return $this->fields ??= new ReceiptFields($this->em);
    }
}
