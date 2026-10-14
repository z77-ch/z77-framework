<?php

namespace Z77\Module\Article\Validators;

use Z77\Module\Article\Entities\AbstractProduct;
use Z77\Module\Article\Entities\AbstractVariant;
use Z77\Module\Article\Entities\ProductGroup;
use Z77\Module\Article\Services\ArticleDraft;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Money\Money;

/**
 * The rules of ONE article save (plan §4.1), over the {@see ArticleDraft} — the TYPED values,
 * before anything managed is touched (ADR-039 decision 9). Pure where it can be: the three
 * questions that need data are asked through closures the service builds, so the harness checks
 * every rule without a database (`tests/module-article.php` A):
 *
 *   - `$numberTaken(string $number): bool` — another variant carries this number already
 *     (the unique index decides under a race; this is the early field error);
 *   - `$taxCode(string $code): ?TaxCode` — module-vat's row for the code, null = unknown;
 *   - `$accountProblem(string $number): ?string` — the German refusal of the soft account check
 *     (`LedgerAccountCheck`: not postable / not «Ertrag» / not «auf Belegen wählbar»), null =
 *     accepted OR not checkable (financial absent — soft, nothing is refused then).
 *
 *   - `$group(int $id): ?ProductGroup` — the product group of the placement, null = unknown
 *     (A1, owner 2026-10-14: mandatory, any node).
 *
 * The reference rule (ADR-043 decision 19): a NEW reference needs an ACTIVE tax code; an
 * article that KEEPS its code ($keptTaxCode) keeps it although it was deactivated since. The
 * same for the group: an inactive group is not offered, the article that hangs there keeps it
 * ($keptGroup). The
 * revenue account has no such exception — like an invoice draft, a save with an account the
 * chart no longer offers on documents is refused until it is corrected (debtor.md).
 */
class ArticleValidator extends EntityValidator
{
    /** The price as the field takes it: no sign, at most eleven digits, two decimals; «'» groups. */
    private const PRICE_PATTERN = '/^\d{1,11}(\.\d{1,2})?$/';

    /** A chart number: digits only, as many as the column holds. */
    private const ACCOUNT_PATTERN = '/^[0-9]{1,' . AbstractProduct::ACCOUNT_LENGTH . '}$/';

    /**
     * @param \Closure(string): bool|null         $numberTaken
     * @param \Closure(string): ?TaxCode|null     $taxCode
     * @param \Closure(string): ?string|null      $accountProblem
     * @param \Closure(int): ?ProductGroup|null   $group
     */
    public function __construct(
        ArticleDraft $draft,
        private readonly ?\Closure $numberTaken = null,
        private readonly ?\Closure $taxCode = null,
        private readonly ?\Closure $accountProblem = null,
        private readonly string $keptTaxCode = '',
        private readonly ?\Closure $group = null,
        private readonly int $keptGroup = 0,
    ) {
        parent::__construct($draft);
    }

    /** A race on the unique index mapped onto the field the form shows. */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    /** «1'234.50», «1234,5», «120» → the decimal string; null when it is no price. */
    public static function normalizePrice(string $value): ?string
    {
        $normalized = str_replace(["'", '’', ' '], '', str_replace(',', '.', trim($value)));

        return preg_match(self::PRICE_PATTERN, $normalized) ? $normalized : null;
    }

    /** The validated price as money in $currency — call only after {@see isValid()}. */
    public static function price(ArticleDraft $draft, string $currency): Money
    {
        $decimal = self::normalizePrice($draft->price) ?? throw new \LogicException('The price was not validated');

        return Money::fromDecimal($decimal, $currency);
    }

    /** `Y-m-d` → the day; null when it is no date. */
    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    public function validateNumber(string $number): void
    {
        $this->validate('number', 'Artikelnummer', $number)->notEmpty()->maxLength(AbstractVariant::NUMBER_LENGTH);
        if ($this->hasFieldError('number')) {
            return;
        }
        if (preg_match('/[\x00-\x1F\x7F]|\s/u', $number)) {
            $this->addFieldError('number', 'Die Artikelnummer enthält keine Leerzeichen (z.B. 1001 oder WEIN-2024-75).');
            return;
        }
        if ($this->numberTaken !== null && ($this->numberTaken)($number)) {
            $this->addFieldError('number', 'Die Artikelnummer «' . $number . '» ist schon vergeben.');
        }
    }

    public function validateName(string $name): void
    {
        $this->validate('name', 'Bezeichnung', $name)->notEmpty()->maxLength(AbstractProduct::NAME_LENGTH);
    }

    public function validateUnit(string $unit): void
    {
        $this->validate('unit', 'Einheit', $unit)->maxLength(AbstractVariant::UNIT_LENGTH);
    }

    public function validatePrice(string $price): void
    {
        $this->validate('price', 'Preis', $price)->notEmpty();
        if ($this->hasFieldError('price')) {
            return;
        }
        if (self::normalizePrice($price) === null) {
            $this->addFieldError('price', str_starts_with(trim($price), '-')
                ? 'Der Preis ist nicht negativ — eine Rücknahme ist eine Position mit negativer Menge.'
                : 'Der Preis ist kein Betrag (z.B. 120.00 oder 1\'250.50).');
        }
    }

    public function validatePriceMode(string $mode): void
    {
        if (PriceMode::tryFrom($mode) === null) {
            $this->addFieldError('price_mode', 'Netto oder brutto wählen.');
        }
    }

    public function validateValidFrom(string $validFrom): void
    {
        $this->validate('valid_from', 'Gültig ab', $validFrom)->notEmpty();
        if (!$this->hasFieldError('valid_from') && self::parseDate($validFrom) === null) {
            $this->addFieldError('valid_from', 'Gültig ab ist kein Datum.');
        }
    }

    public function validateTaxCode(string $code): void
    {
        $this->validate('tax_code', 'MWST-Code', $code)->notEmpty()->maxLength(AbstractProduct::TAX_CODE_LENGTH);
        if ($this->hasFieldError('tax_code') || $this->taxCode === null) {
            return;
        }
        $row = ($this->taxCode)($code);
        if ($row === null) {
            $this->addFieldError('tax_code', 'Den MWST-Code «' . $code . '» gibt es nicht.');
            return;
        }
        if (!$row->isActive() && TaxCode::normalizeCode($this->keptTaxCode) !== $row->getCode()) {
            $this->addFieldError('tax_code', 'Der MWST-Code «' . $code . '» ist inaktiv — nur ein Artikel, der ihn schon trägt, behält ihn.');
        }
    }

    public function validateRevenueAccount(string $account): void
    {
        $this->validate('revenue_account', 'Ertragskonto', $account)->notEmpty();
        if ($this->hasFieldError('revenue_account')) {
            return;
        }
        if (!preg_match(self::ACCOUNT_PATTERN, $account)) {
            $this->addFieldError('revenue_account', 'Das Ertragskonto ist eine Kontonummer (nur Ziffern, z.B. 3200).');
            return;
        }
        $problem = $this->accountProblem !== null ? ($this->accountProblem)($account) : null;
        if ($problem !== null) {
            $this->addFieldError('revenue_account', $problem);
        }
    }

    public function validateProductGroup(int $groupId): void
    {
        if ($groupId <= 0) {
            $this->addFieldError('product_group', 'Eine Warengruppe wählen — jeder Artikel hängt in genau einer.');
            return;
        }
        if ($this->group === null) {
            return;
        }
        $group = ($this->group)($groupId);
        if ($group === null) {
            $this->addFieldError('product_group', 'Die gewählte Warengruppe gibt es nicht mehr — bitte neu wählen.');
            return;
        }
        if (!$group->isActive() && $groupId !== $this->keptGroup) {
            $this->addFieldError('product_group', 'Die Warengruppe «' . $group->getName() . '» ist inaktiv — nur ein Artikel, der schon darin hängt, bleibt darin.');
        }
    }
}
