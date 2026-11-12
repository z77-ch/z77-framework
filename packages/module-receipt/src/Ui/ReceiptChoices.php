<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Module\Financial\Entities\Account,
    Z77\Module\Vat\Entities\TaxCategory,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Persistence\Resolver\UnifiedEntityManager
;

/**
 * What the receipt screens OFFER in their account and VAT fields — the receipt window, the
 * template window and the payment-method window read the same lists:
 *
 *   - {@see accounts()} — the accounts a field may name (postable and active, in chart order), for
 *     the shared `<datalist>` (the one-line entry's choice, `OneLineEntryForm::postableAccounts()`);
 *   - {@see taxCodes()} — the codes a receipt may carry: active (plus the record's own), never
 *     reverse charge (`ReceiptFields::taxCode()` refuses it, a receipt with it could never post).
 *
 * An offer only: the save judges every value again with `ReceiptFields` (Rule 7).
 */
final class ReceiptChoices
{
    /** The VAT select's value for the explicit «ohne MWST» — never a tax code (codes are letters and digits). */
    public const WITHOUT_VAT = '-';

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** @return list<Account> */
    public function accounts(): array
    {
        return array_values(array_filter($this->em->getRepository(Account::class)->allInOrder(), static fn(Account $a) => $a->isPostable() && $a->isActive()));
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
            static fn(TaxCode $code) => $code->category() !== null && $code->category() !== TaxCategory::ReverseCharge
        ));
    }

    /** «Vorsteuer Investitionen …» for 'VI', '' for an unknown code. */
    public function taxLabel(string $code): string
    {
        return $code === '' ? '' : ($this->em->getRepository(TaxCode::class)->findByCode($code)?->getLabel() ?? '');
    }
}
