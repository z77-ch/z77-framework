<?php

namespace Z77\Module\Receipt\Services;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Services\LedgerService;
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
 * active); a tax code must exist, be active and split a gross amount (no reverse-charge — the
 * one-line entry refuses it, so a receipt with it could never post).
 */
final class ReceiptFields
{
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
     * The rate a base-currency amount implies for a foreign one — base currency per ONE unit,
     * {@see RATE_DECIMALS} places, rounded half away from zero, trailing zeros trimmed to at least
     * four places («0.902222», «0.9500»). DERIVED, never typed (creditor stage A0: the CHF amount is
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
     * An account NUMBER that can take a new posting — exists, postable (not a group), active. ''
     * = not set (null, no error). A leading number is enough («1020 Bank» → 1020), as in the
     * one-line entry.
     *
     * @param array<string, string> $errors
     */
    public function account(string $value, string $field, array &$errors): ?string
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
        } else {
            return $account->getNumber();
        }

        return null;
    }

    /**
     * A module-vat tax code — exists, active, splits a gross amount. '' = not set (null, no error).
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

        return $code->getCode();
    }
}
