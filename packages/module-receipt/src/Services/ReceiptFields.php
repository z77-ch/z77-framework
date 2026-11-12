<?php

namespace Z77\Module\Receipt\Services;

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
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** The base currency every receipt amount is in ({@see LedgerService::baseCurrency()}). */
    public static function currency(): string
    {
        return LedgerService::baseCurrency();
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
