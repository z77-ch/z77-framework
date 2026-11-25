<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\CountryPack\FormEdition;
use Z77\Module\Vat\CountryPack\FormMapping;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\CountryPack\UnmappedTaxCodeException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Services\UnknownTaxCodeException;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Which VAT account the tax of a coded line belongs to, and with which SIGN — the ONE table the
 * VAT return's reconciliation ({@see \Z77\Module\Financial\VatReturn\VatAccountExpectation}), the
 * lock of the VAT accounts (`PostingRules`) and the writers that compute tax lines (the
 * Sammelbuchung, `GrossEntryBuilder`) read, so return and lock can never disagree (ADR-042
 * addendum 2 of 2026-11-25, point 7).
 *
 *   - {@see taxLines()} — per tax code: the mandator key, the account number and the sign
 *     (debit − credit per unit of POSITIVE tax). Output codes: owed −1. Input codes: their input
 *     account +1. The subsequent deduction (410): `vat-input-other` +1; correction and reduction
 *     (415 / 420): `vat-input-other` −1 (ADR-041 addendum 2026-11-25, point 3). Reverse charge:
 *     owed −1 AND the input account its code names +1 — found through the form's input field of
 *     the code ({@see FormEdition::inputCategoryOf()}) at the rate date. Zero and exempt: none.
 *     «movement of a VAT account = Σ sign × tax of the coded lines mapping to it» is the equation
 *     of the lock and of the reconciliation alike.
 *   - {@see mayCarryCode()} — the predicate: a coded line may itself SIT on a VAT account only when
 *     that account is the input-side account its code maps to (a tax-only line, an input-tax
 *     correction, the reverse-charge pair line); an output, zero or exempt code never.
 *   - {@see lockedAccounts()} — the VAT accounts the lock guards on a day: empty while the mandator
 *     is not liable, without «Abrechnung ab», before it and after «Abrechnung bis», and when the
 *     record cannot be read. {@see accounts()} — the same accounts without a date, for pickers.
 *
 * Reads accounts through {@see LedgerService} only (`vatAccountFor()`, `vatSettings()`) — no
 * caller here asks the mandator record. One instance caches the codes, the accounts, the settings
 * and the form mapping it read; build a new one per request.
 */
final class VatAccountMap
{
    private readonly LedgerService $ledger;

    private ?VatSettings $settings;

    private bool $settingsRead;

    private ?FormMapping $mapping;

    /** @var array<string, ?string>|null mandator key → account number (null = empty on the record) */
    private ?array $numbers = null;

    /** @var array<string, ?TaxCode> normalised code → code row */
    private array $codes = [];

    /**
     * @param LedgerService|null $ledger   the reader of the accounts; null = a new one on $em
     * @param VatSettings|null   $settings the mandator's VAT settings (a harness passes its own); null = read through {@see LedgerService::vatSettings()}
     * @param FormMapping|null   $mapping  the country pack; null = `CH`, read on first use
     */
    public function __construct(
        private readonly UnifiedEntityManager $em,
        ?LedgerService $ledger = null,
        ?VatSettings $settings = null,
        ?FormMapping $mapping = null,
    ) {
        $this->ledger       = $ledger ?? new LedgerService($em);
        $this->settings     = $settings;
        $this->settingsRead = $settings !== null;
        $this->mapping      = $mapping;
    }

    /**
     * The tax lines a coded line of $taxCode maps to, in this order: the owed account first, then
     * the input account. `key` is the mandator key ({@see LedgerService::VAT_OWED_ACCOUNT}, …),
     * `account` its number (null while the mandator leaves it empty), `sign` debit − credit per
     * unit of positive tax (+1 or −1). Empty for a zero-rated or exempt code and for a code whose
     * stored category is not one of the model's (VAT-CAT-001 — the return refuses that code).
     *
     * $rateDate picks the form edition a reverse-charge code's input field is read from (the
     * voucher's rate date — the entry date, a creditor's invoice date, the last day of a corrected
     * period); every other category ignores it.
     *
     * @return list<array{key: string, account: ?string, sign: int}>
     * @throws UnknownTaxCodeException      no such tax code
     * @throws FormMappingException         reverse charge: no form edition covers $rateDate
     * @throws UnmappedTaxCodeException     reverse charge: the code has no input field in that edition
     * @throws VatAccountUnavailableException the mandator record cannot be read, or the legacy config key is present
     */
    public function taxLines(string $taxCode, \DateTimeImmutable $rateDate): array
    {
        $code     = $this->code($taxCode);
        $category = $code->category();
        if ($category === null || !$category->carriesTax()) {
            return [];
        }
        if ($category === TaxCategory::ReverseCharge) {
            $edition = $this->edition($rateDate);
            $input   = $edition->inputCategoryOf($code->getCode());
            if ($input === null) {
                throw new UnmappedTaxCodeException($code->getCode(), $category->value, 0, $edition->name, 'no input field for this reverse-charge code');
            }

            return [
                $this->line(LedgerService::VAT_OWED_ACCOUNT, -1),
                $this->line((string) LedgerService::vatAccountKey($input->value), 1),
            ];
        }

        return [$this->line((string) LedgerService::vatAccountKey($category->value), $category->isDebitNatural() ? 1 : -1)];
    }

    /**
     * The predicate of the lock: may a line coded $taxCode itself stand on $account? True only
     * when $account is the INPUT-side account the code maps to — a tax-only line (VM / VI), an
     * input-tax correction (VE / VK / VN), the reverse-charge pair line. An output, zero or exempt
     * code on a VAT account, an input code on the owed account or on the other input account: false.
     *
     * @throws UnknownTaxCodeException|FormMappingException|VatAccountUnavailableException see {@see taxLines()}
     */
    public function mayCarryCode(string $taxCode, string $account, \DateTimeImmutable $rateDate): bool
    {
        if ($account === '') {
            return false;
        }
        foreach ($this->taxLines($taxCode, $rateDate) as $line) {
            if ($line['key'] !== LedgerService::VAT_OWED_ACCOUNT && $line['account'] === $account) {
                return true;
            }
        }

        return false;
    }

    /**
     * The VAT accounts the lock guards on $date — account number → mandator key. Empty when the
     * lock does not apply on that day: the mandator is not liable, «Abrechnung ab» is not set or
     * lies after $date, «Abrechnung bis» lies before it, or the record cannot be read (an upgrade
     * half done must not stop every posting — `PostingRules::vatStart()`'s stance). The settlement
     * account and the transit account `vat-input-pending` are never in it.
     *
     * @return array<string, string>
     */
    public function lockedAccounts(\DateTimeImmutable $date): array
    {
        $s = $this->settings();
        if ($s === null || !$s->liable || $s->from === null) {
            return [];
        }
        $day = $date->format('Y-m-d');
        if ($day < $s->from->format('Y-m-d') || ($s->until !== null && $day > $s->until->format('Y-m-d'))) {
            return [];
        }

        return $this->accounts();
    }

    /**
     * The mandator's VAT accounts — owed, input material, input other — account number → mandator
     * key, without a date: what a picker leaves out. The fields the mandator leaves empty are not
     * in it; empty when the record cannot be read.
     *
     * @return array<string, string>
     */
    public function accounts(): array
    {
        try {
            $numbers = $this->numbers();
        } catch (VatAccountUnavailableException) {
            return [];
        }
        $accounts = [];
        foreach ($numbers as $key => $number) {
            if ($number !== null) {
                $accounts[$number] = $key;
            }
        }

        return $accounts;
    }

    /** @return array{key: string, account: ?string, sign: int} */
    private function line(string $key, int $sign): array
    {
        return ['key' => $key, 'account' => $this->numbers()[$key], 'sign' => $sign];
    }

    /**
     * The three VAT accounts, read ONCE through {@see LedgerService::vatAccountFor()} — by a
     * category of each key, the reader's only way in.
     *
     * @return array<string, ?string>
     * @throws VatAccountUnavailableException
     */
    private function numbers(): array
    {
        return $this->numbers ??= [
            LedgerService::VAT_OWED_ACCOUNT           => $this->ledger->vatAccountFor(TaxCategory::Standard->value),
            LedgerService::VAT_INPUT_MATERIAL_ACCOUNT => $this->ledger->vatAccountFor(TaxCategory::InputMaterial->value),
            LedgerService::VAT_INPUT_OTHER_ACCOUNT    => $this->ledger->vatAccountFor(TaxCategory::InputOther->value),
        ];
    }

    /** @throws UnknownTaxCodeException */
    private function code(string $taxCode): TaxCode
    {
        $normalised = TaxCode::normalizeCode($taxCode);
        if (!array_key_exists($normalised, $this->codes)) {
            /** @var TaxCodeRepository $repository */
            $repository = $this->em->getRepository(TaxCode::class);
            $this->codes[$normalised] = $repository->findByCode($normalised);
        }

        return $this->codes[$normalised] ?? throw new UnknownTaxCodeException($normalised);
    }

    /** @throws FormMappingException */
    private function edition(\DateTimeImmutable $rateDate): FormEdition
    {
        return ($this->mapping ??= FormMapping::forCountry(VatReturnService::COUNTRY))->edition($rateDate, $rateDate);
    }

    private function settings(): ?VatSettings
    {
        if (!$this->settingsRead) {
            $this->settings     = $this->ledger->vatSettings();
            $this->settingsRead = true;
        }

        return $this->settings;
    }
}
