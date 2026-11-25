<?php

namespace Z77\Module\Receipt\Services;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Ui\ManualEntryForm;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The value checks every writer of the receipt journal shares — the capture (phone), the
 * correction (backend) and the template validator — so a value means the same wherever it
 * arrives. Each check takes the raw string, returns the value or null, and on refusal writes ONE
 * German message under its field into $errors.
 *
 * The parsing is financial's (`ManualEntryForm::parseDate()` / `parseAmount()` — the journal's
 * own form rules); the account rule is the one the one-line entry applies (exists, postable,
 * active) plus the lock of the VAT accounts (from «Abrechnung ab» on, the mandator's VAT accounts
 * are written by z77 from the tax codes only — ADR-042 addendum 2 of 2026-11-25, point 7); a tax
 * code must exist, be active and split a gross amount (no reverse-charge — the one-line entry
 * refuses it, so a receipt with it could never post — and no input-tax correction VE / VK / VN,
 * which only the Sammelbuchung takes).
 *
 * {@see vatChoice()} is the ONE parser of the VAT select's value the receipt window, the template
 * window and the creditor's invoice lines share (`ReceiptChoices::taxChoices()` builds the
 * options): «ohne MWST», a code, or a code with «nur Steuer» (`VM:nur-steuer`).
 */
final class ReceiptFields
{
    /** The VAT select's value for the explicit «ohne MWST» — never a tax code (codes are letters and digits). */
    public const WITHOUT_VAT = '-';

    /**
     * The suffix of the VAT select's value for «nur Steuer» — `VM:nur-steuer`: the line's amount IS
     * the tax of that input code (ADR-041 addendum 2026-11-25, point 6).
     */
    public const TAX_ONLY_SUFFIX = ':nur-steuer';
    /** The config key under `receiptConfig` that lists the foreign currencies (receipt FX plan F7). */
    public const FOREIGN_CURRENCIES_KEY = 'foreignCurrencies';

    /**
     * The shipped list — what `receiptConfig['foreignCurrencies']` says, used when no module config
     * is wired (a script, a test) or a project's override of `receiptConfig` lacks the key
     * (BOOT-CONFIG-001: the override replaces the whole file).
     */
    public const DEFAULT_FOREIGN_CURRENCIES = ['EUR', 'USD'];

    /**
     * Currencies without two minor places — `Money` has a fixed scale of 2, so a listed one is a
     * config error (receipt FX plan T3).
     */
    public const NOT_TWO_DECIMALS = ['JPY', 'KRW', 'ISK', 'CLP', 'VND', 'PYG', 'UGX', 'XOF', 'XAF', 'BHD', 'KWD', 'OMR', 'JOD', 'TND', 'LYD', 'IQD'];

    /** Decimal places of a derived exchange rate — `exchange_rate NUMERIC(12,6)`, debtor parity. */
    public const RATE_DECIMALS = 6;

    /** Read once per instance — the mandator's VAT accounts and on which days they are locked. */
    private ?VatAccountMap $vatAccounts = null;

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** The base currency every receipt amount is in ({@see LedgerService::baseCurrency()}). */
    public static function currency(): string
    {
        return LedgerService::baseCurrency();
    }

    /**
     * The foreign currencies a document may carry (receipt FX plan F7, T3) — the ONE reader of
     * `receiptConfig['foreignCurrencies']`; module-creditor reads the same list (creditor stage
     * A0, owner 2026-11-15). Each entry three upper-case letters, never the base currency, never a
     * currency without two minor places — anything else is a config error and throws.
     *
     * @return list<string>
     * @throws \LogicException a listed entry is not allowed
     */
    public static function foreignCurrencies(): array
    {
        try {
            $value = DI::getModuleManager()->getModuleConfig('receipt')?->get(self::FOREIGN_CURRENCIES_KEY, null);
        } catch (\Throwable) {
            $value = null;
        }
        $list = is_array($value) ? array_values($value) : self::DEFAULT_FOREIGN_CURRENCIES;
        foreach ($list as $code) {
            if (!is_string($code) || !preg_match('/^[A-Z]{3}$/', $code) || $code === self::currency() || in_array($code, self::NOT_TWO_DECIMALS, true)) {
                throw new \LogicException("receiptConfig['" . self::FOREIGN_CURRENCIES_KEY . "'] lists '" . (is_scalar($code) ? $code : get_debug_type($code))
                    . "' — only ISO 4217 codes in upper case, not the base currency, with two minor places");
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * The currency of a document: '' or the base currency → null (a base-currency document);
     * otherwise upper-cased and in {@see foreignCurrencies()} OR equal to $kept (the document's own
     * currency — a currency removed from the config later still saves on it).
     *
     * @param array<string, string> $errors
     */
    public function foreignCurrency(string $value, string $field, array &$errors, ?string $kept = null): ?string
    {
        $code = strtoupper(trim($value));
        if ($code === '' || $code === self::currency()) {
            return null;
        }
        $allowed = self::foreignCurrencies();
        if (in_array($code, $allowed, true) || ($kept !== null && $code === $kept)) {
            return $code;
        }
        $errors[$field] = 'Währung ' . mb_substr($code, 0, 8) . ' ist nicht freigegeben (erlaubt: ' . implode(', ', [self::currency(), ...$allowed]) . ').';

        return null;
    }

    /**
     * The amount printed on a foreign-currency document, in $currency — required with a foreign
     * currency, positive; given without one it is refused (receipt FX plan T3).
     *
     * @param array<string, string> $errors
     */
    public function originalAmount(string $value, ?string $currency, string $field, array &$errors): ?Money
    {
        $value = trim($value);
        if ($currency === null) {
            if ($value !== '') {
                $errors[$field] = 'Betrag in Fremdwährung ohne Währung — eine Währung wählen oder das Feld leeren.';
            }

            return null;
        }
        if ($value === '') {
            $errors[$field] = 'Betrag in ' . $currency . ' fehlt.';

            return null;
        }
        $amount = ManualEntryForm::parseAmount($value, $currency);
        if ($amount === null) {
            $errors[$field] = 'Kein Betrag (z.B. 9.00, höchstens ' . ManualEntryForm::AMOUNT_INTEGER_DIGITS . ' Stellen vor dem Punkt).';

            return null;
        }
        if (!$amount->isPositive()) {
            $errors[$field] = 'Der Betrag muss grösser als 0 sein.';

            return null;
        }

        return $amount;
    }

    /**
     * A rate TYPED by the office — base currency per ONE unit of the foreign currency, as the bank's
     * app shows it («0.9342»). Up to {@see RATE_DECIMALS} places, point or comma, greater than 0.
     * Answers it normalised («0.9342»), or null for an empty field / with the error set.
     *
     * @param array<string, string> $errors
     */
    public function rate(string $value, string $field, array &$errors): ?string
    {
        $value = str_replace(',', '.', (string) preg_replace('/[\s\'’]/u', '', $value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,5})(?:\.(\d{1,' . self::RATE_DECIMALS . '}))?$/', $value, $m) !== 1) {
            $errors[$field] = 'Kein Kurs (z.B. 0.9342, höchstens ' . self::RATE_DECIMALS . ' Stellen nach dem Punkt).';

            return null;
        }
        $int  = ltrim($m[1], '0');
        $frac = rtrim($m[2] ?? '', '0');
        if ($int === '' && $frac === '') {
            $errors[$field] = 'Der Kurs muss grösser als 0 sein.';

            return null;
        }

        return ($int === '' ? '0' : $int) . '.' . str_pad($frac, 4, '0');
    }

    /**
     * What $original is in the base currency at $rate (base currency per ONE unit, as {@see rate()}
     * answers it or the database stores it): amount × rate, rounded half away from zero to the minor
     * unit. Integer arithmetic, no float.
     */
    public static function convert(Money $original, string $rate): Money
    {
        [$int, $frac] = array_pad(explode('.', $rate, 2), 2, '');
        if (!ctype_digit($int) || ($frac !== '' && !ctype_digit($frac)) || strlen($frac) > self::RATE_DECIMALS) {
            throw new \LogicException('A rate is a positive decimal with at most ' . self::RATE_DECIMALS . ' places');
        }
        $scaled = (int) ($int . str_pad($frac, self::RATE_DECIMALS, '0'));

        return Money::of($original->multiplyByRatio($scaled, 10 ** self::RATE_DECIMALS)->minor, self::currency());
    }

    /** A stored rate as the screen shows it: trailing zeros trimmed to at least four places («0.9342», «0.9500»). */
    public static function formatRate(string $rate): string
    {
        [$int, $frac] = array_pad(explode('.', $rate, 2), 2, '');

        return $int . '.' . str_pad(rtrim($frac, '0'), 4, '0');
    }

    /**
     * The rate a base-currency amount implies for a foreign one — base currency per ONE unit,
     * {@see RATE_DECIMALS} places, rounded half away from zero, trailing zeros trimmed to at least
     * four places («0.902222», «0.9500»). DERIVED and never stored — a rate the office types is {@see rate()} (creditor stage A0: the CHF amount is
     * what the bank debited, fees included — receipt FX plan F12). Integer long division, no float.
     */
    public static function derivedRate(Money $base, Money $original): string
    {
        if (!$base->isPositive() || !$original->isPositive()) {
            throw new \LogicException('A derived rate needs two positive amounts');
        }
        $num   = $base->minor;
        $den   = $original->minor;
        $units = intdiv($num, $den);
        $rest  = $num % $den;
        $frac  = '';
        for ($i = 0; $i <= self::RATE_DECIMALS; $i++) {   // one digit more, for the rounding
            $rest *= 10;
            $frac .= (string) intdiv($rest, $den);
            $rest %= $den;
        }
        $digits = (string) $units . substr($frac, 0, self::RATE_DECIMALS);
        if ((int) $frac[self::RATE_DECIMALS] >= 5) {
            // + 1 on the last place, carried through the digit string
            $carry = 1;
            for ($i = strlen($digits) - 1; $i >= 0 && $carry === 1; $i--) {
                $d          = (int) $digits[$i] + 1;
                $carry      = $d === 10 ? 1 : 0;
                $digits[$i] = (string) ($d % 10);
            }
            if ($carry === 1) {
                $digits = '1' . $digits;
            }
        }
        $int  = ltrim(substr($digits, 0, -self::RATE_DECIMALS), '0');
        $frac = rtrim(substr($digits, -self::RATE_DECIMALS), '0');

        return ($int === '' ? '0' : $int) . '.' . str_pad($frac, 4, '0');
    }

    /**
     * A `YYYY-MM-DD` date, not after $today (a receipt is a past event — E6: today by default,
     * an earlier day for a receipt captured late).
     *
     * @param array<string, string> $errors
     */
    public function date(string $value, string $field, array &$errors, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $date = ManualEntryForm::parseDate(trim($value));
        if ($date === null) {
            $errors[$field] = 'Datum: ein gültiges Datum (JJJJ-MM-TT).';

            return null;
        }
        if ($date > $today->setTime(0, 0)) {
            $errors[$field] = 'Das Belegdatum liegt in der Zukunft.';

            return null;
        }

        return $date;
    }

    /**
     * The gross amount — required (E5), positive, in the base currency.
     *
     * @param array<string, string> $errors
     */
    public function amount(string $value, string $field, array &$errors): ?Money
    {
        $value = trim($value);
        if ($value === '') {
            $errors[$field] = 'Betrag fehlt.';

            return null;
        }
        $amount = ManualEntryForm::parseAmount($value, self::currency());
        if ($amount === null) {
            $errors[$field] = 'Kein Betrag (z.B. 45.50, höchstens ' . ManualEntryForm::AMOUNT_INTEGER_DIGITS . ' Stellen vor dem Punkt).';

            return null;
        }
        if (!$amount->isPositive()) {
            $errors[$field] = 'Der Betrag muss grösser als 0 sein.';

            return null;
        }

        return $amount;
    }

    /**
     * The posting text — trimmed, control characters removed, at most the journal's length. '' is
     * allowed (the receipt is then incomplete).
     *
     * @param array<string, string> $errors
     */
    public function text(string $value, string $field, array &$errors): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        if (mb_strlen($value) > Receipt::TEXT_LENGTH) {
            $errors[$field] = 'Text darf maximal ' . Receipt::TEXT_LENGTH . ' Zeichen lang sein.';
        }

        return $value;
    }

    /**
     * An account NUMBER that can take a new posting — exists, postable (not a group), active, and
     * not one of the mandator's VAT accounts while they are locked on $date ({@see vatAccountRefusal()};
     * the pickers do not offer them, this is the server's own check — Rule 7). '' = not set (null,
     * no error). A leading number is enough («1020 Bank» → 1020), as in the one-line entry.
     *
     * @param array<string, string>   $errors
     * @param \DateTimeImmutable|null $date the day the account is booked on; null = today (a template, a
     *                                      payment method, a creditor default: what is chosen from now on)
     */
    public function account(string $value, string $field, array &$errors, ?\DateTimeImmutable $date = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^([0-9]{1,' . Account::NUMBER_LENGTH . '})(\s.*)?$/u', $value, $m)) {
            $errors[$field] = 'Kontonummer eingeben (z.B. 1020).';

            return null;
        }
        $account = $this->em->getRepository(Account::class)->findOneBy(['number' => $m[1]]);
        if ($account === null) {
            $errors[$field] = 'Konto ' . $m[1] . ' gibt es nicht.';
        } elseif (!$account->isPostable()) {
            $errors[$field] = '«' . $account->label() . '» ist eine Gruppe — nicht bebuchbar.';
        } elseif (!$account->isActive()) {
            $errors[$field] = '«' . $account->label() . '» ist inaktiv.';
        } elseif (($refusal = $this->vatAccountRefusal($account->getNumber(), $date)) !== null) {
            $errors[$field] = $refusal;
        } else {
            return $account->getNumber();
        }

        return null;
    }

    /**
     * The sentence that refuses $number in an account field of a receipt, a template, a payment
     * method or a creditor (profile default, invoice line) — or null when it is no VAT account of
     * the mandator locked on $date (null = today). From «Abrechnung ab» to «Abrechnung bis» of a
     * liable mandator the VAT accounts carry only the tax lines z77 computes from the tax codes
     * (ADR-042 addendum 2 of 2026-11-25, point 7: pickers do not offer them, the server refuses
     * them); before that day, and for a mandator that does not settle VAT in z77, nothing changes.
     */
    public function vatAccountRefusal(string $number, ?\DateTimeImmutable $date = null): ?string
    {
        $number = trim($number);
        if ($number === '' || !in_array($number, $this->lockedVatAccounts($date), true)) {
            return null;
        }

        return 'Konto ' . $number . ' ist ein MWST-Konto — die Steuer schreibt z77 aus dem MWST-Code. Ein anderes Konto wählen und den MWST-Code setzen.';
    }

    /**
     * The mandator's VAT accounts locked on $date (null = today), as account numbers — empty while
     * the lock does not apply (`VatAccountMap::lockedAccounts()`, financial's one answer).
     *
     * @return list<string>
     */
    public function lockedVatAccounts(?\DateTimeImmutable $date = null): array
    {
        $this->vatAccounts ??= new VatAccountMap($this->em);

        // The map's keys are account numbers — PHP turns the numeric ones into int.
        return array_map('strval', array_keys($this->vatAccounts->lockedAccounts($date ?? new \DateTimeImmutable('today'))));
    }

    /**
     * The VAT select's value — the ONE parser the receipt window, the template window and the
     * creditor's invoice lines share (`ReceiptChoices::taxChoices()` builds the options):
     *
     *   - '' → null, no error (not decided yet — the document is incomplete);
     *   - {@see WITHOUT_VAT} → «ohne MWST»;
     *   - a code → that code, judged by {@see taxCode()};
     *   - a code + {@see TAX_ONLY_SUFFIX} → «nur Steuer» with that code: an input code only (VM, VI or
     *     an own code of those two categories — `TaxCategory::allowsTaxOnly()`, never a correction code).
     *
     * A mandator NOT liable to VAT takes «ohne MWST» only. What a document then DOES with «nur
     * Steuer» is the caller's (the line kind of the receipt / the creditor line).
     *
     * @param array<string, string> $errors
     * @return array{code: ?string, withoutVat: bool, taxOnly: bool}|null
     */
    public function vatChoice(string $value, string $field, array &$errors, bool $liableToVat): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($value === self::WITHOUT_VAT) {
            return ['code' => null, 'withoutVat' => true, 'taxOnly' => false];
        }
        if (!$liableToVat) {
            $errors[$field] = 'Die Firma ist nicht MWST-pflichtig — «ohne MWST» wählen.';

            return null;
        }
        $taxOnly = str_ends_with(strtolower($value), self::TAX_ONLY_SUFFIX);
        $code    = $this->taxCode($taxOnly ? substr($value, 0, -strlen(self::TAX_ONLY_SUFFIX)) : $value, $field, $errors);
        if ($code === null) {
            if (!isset($errors[$field])) {
                $errors[$field] = 'MWST-Code wählen.';   // «:nur-steuer» alone
            }

            return null;
        }
        if ($taxOnly && $this->em->getRepository(TaxCode::class)->findByCode($code)?->category()?->allowsTaxOnly() !== true) {
            $errors[$field] = '«Nur Steuer» geht nur mit einem Vorsteuer-Code (VM, VI).';

            return null;
        }

        return ['code' => $code, 'withoutVat' => false, 'taxOnly' => $taxOnly];
    }

    /** The VAT select's value for a stored decision — the inverse of {@see vatChoice()} ('' = not decided). */
    public static function vatToken(?string $code, bool $withoutVat, bool $taxOnly = false): string
    {
        if ($withoutVat) {
            return self::WITHOUT_VAT;
        }
        if ($code === null || $code === '') {
            return '';
        }

        return $code . ($taxOnly ? self::TAX_ONLY_SUFFIX : '');
    }

    /**
     * A module-vat tax code — exists, active, splits a gross amount: no reverse charge (it goes on
     * both sides) and no input-tax correction VE / VK / VN (the Sammelbuchung's only — owner
     * 2026-11-25). '' = not set (null, no error).
     *
     * @param array<string, string> $errors
     */
    public function taxCode(string $value, string $field, array &$errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $code = $this->em->getRepository(TaxCode::class)->findByCode($value);
        if ($code === null) {
            $errors[$field] = 'MWST-Code ' . TaxCode::normalizeCode($value) . ' gibt es nicht.';

            return null;
        }
        if (!$code->isActive()) {
            $errors[$field] = 'MWST-Code ' . $code->getCode() . ' ist inaktiv.';

            return null;
        }
        $category = $code->category();
        if ($category === null || $category === TaxCategory::ReverseCharge) {
            $errors[$field] = $category === null
                ? 'MWST-Code ' . $code->getCode() . ' hat eine unbekannte Kategorie.'
                : 'Bezugsteuer (' . $code->getCode() . ') geht auf beide Seiten — als Sammelbuchung im Journal erfassen, nicht als Beleg.';

            return null;
        }
        if ($category->isManualOnly()) {
            $errors[$field] = 'MWST-Code ' . $code->getCode() . ' ist eine Vorsteuerkorrektur — nur als Sammelbuchung im Journal.';

            return null;
        }

        return $code->getCode();
    }
}
