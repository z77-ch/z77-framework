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
 *   - {@see taxCodes()} — the codes a receipt may carry: active (plus the record's own), never
 *     reverse charge (`ReceiptFields::taxCode()` refuses it, a receipt with it could never post),
 *     never an input-tax correction VE / VK / VN (the Sammelbuchung's only);
 *   - {@see taxChoices()} — the VAT select as option GROUPS (board S5-1, part 2: «ohne MWST»,
 *     «Vorsteuer», «Nur Steuer», «Umsatzsteuer»), the ONE building block of the receipt window, the
 *     template window and the creditor's invoice lines (Rule 9); its values are what
 *     `ReceiptFields::vatChoice()` parses.
 *
 * An offer only: the save judges every value again with `ReceiptFields` (Rule 7).
 */
final class ReceiptChoices
{
    /** The VAT select's value for the explicit «ohne MWST» — never a tax code (codes are letters and digits). */
    public const WITHOUT_VAT = ReceiptFields::WITHOUT_VAT;

    /** The group label of the «nur Steuer» options (board S5-1, part 2). */
    public const GROUP_TAX_ONLY = 'Nur Steuer (Einfuhrsteuer, nachgeholte Vorsteuer)';

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
     * @param list<string> $keep the record's own code(s) — offered even when inactive
     * @return list<TaxCode>
     */
    public function taxCodes(array $keep = []): array
    {
        return array_values(array_filter(
            $this->em->getRepository(TaxCode::class)->selectable(array_values(array_filter($keep, static fn($c) => is_string($c) && $c !== ''))),
            static fn(TaxCode $code) => $code->category() !== null && $code->category() !== TaxCategory::ReverseCharge && !$code->category()->isManualOnly()
        ));
    }

    /**
     * The VAT select as option groups, in the board's order: «ohne MWST» (no group), «Vorsteuer»
     * (the input codes), «Nur Steuer (…)» (one «VM — nur Steuer» per input code — an own «VM2»
     * appears there by itself), «Umsatzsteuer» (the output, zero-rated and exempt codes). Reverse
     * charge is not offered (a later stage adds its group); the corrections VE / VK / VN never. A
     * mandator NOT liable to VAT gets «ohne MWST» only. Each option's `value` is what
     * `ReceiptFields::vatChoice()` parses; a group without an option is left out. The placeholder
     * «— wählen —» is the template's.
     *
     * @param list<string> $keep the record's own code(s) — offered even when inactive
     * @return list<array{group: string, options: list<array{value: string, label: string}>}> group '' = no `<optgroup>`
     */
    public function taxChoices(array $keep = [], bool $liableToVat = true): array
    {
        $groups = [['group' => '', 'options' => [['value' => ReceiptFields::WITHOUT_VAT, 'label' => 'ohne MWST']]]];
        if (!$liableToVat) {
            return $groups;
        }
        $input = $taxOnly = $output = [];
        foreach ($this->taxCodes($keep) as $code) {
            $label = $code->getCode() . ' — ' . $code->getLabel();
            if ($code->category()->allowsTaxOnly()) {
                $input[]   = ['value' => ReceiptFields::vatToken($code->getCode(), false), 'label' => $label];
                $taxOnly[] = ['value' => ReceiptFields::vatToken($code->getCode(), false, true), 'label' => $code->getCode() . ' — nur Steuer'];
            } else {
                $output[] = ['value' => ReceiptFields::vatToken($code->getCode(), false), 'label' => $label];
            }
        }
        foreach (['Vorsteuer' => $input, self::GROUP_TAX_ONLY => $taxOnly, 'Umsatzsteuer' => $output] as $group => $options) {
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
