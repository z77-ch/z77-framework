<?php

namespace Z77\Module\Debtor\Invoicing;

/**
 * The QR reference (QRR) of a Swiss QR-bill — the only structured reference
 * z77 prints (owner 2026-09-23: QRR with a QR-IBAN, NON with a plain IBAN, no
 * SCOR). Pure functions.
 *
 * The shape (SIX, Swiss Implementation Guidelines QR-bill v2.x, «RmtInf /
 * Ref»): exactly 27 digits — 26 digits of payload and a check digit computed
 * MODULO 10 RECURSIVE over them (the algorithm of the former ESR reference,
 * table 0 9 4 6 8 2 7 1 3 5). A reference of zeros only is not valid.
 *
 * The payload is the layout of wdv-630 (`InvoiceManager::getReferenceNo()`,
 * owner 2026-10-06 «genau gleich»), three fixed fields:
 *
 *     positions  1–10   ten zeros — where wdv put its `esrBankAccount`
 *                       (the BESR-ID of the orange ESR slip); obsolete
 *                       since the QR-bill (owner 2026-10-06): the bank's
 *                       customer identification travels in the QR-IBAN,
 *                       the 26 payload digits are the biller's — debtor.md
 *                       DEBTOR-QRR-PREFIX-001 (closed)
 *     positions 11–16   the CUSTOMER NUMBER of the debtor
 *                       (`DebtorProfile::$customerNumber`), six digits
 *     positions 17–26   the DOCUMENT NUMBER, ten digits
 *     position  27      the check digit
 *
 * So the reference identifies the debtor AND the invoice — which is what the
 * CAMT.054 matching of P4 reads back (plan §6.4; wdv read the customer at
 * 11–16 and the invoice at 17–26 the same way). Both numbers are the bare
 * integers of their ranges; the padding happens here, never in
 * `number_range` (`persistence-doctrine.md`).
 */
final class QrReference
{
    public const LENGTH = 27;

    /** The three payload fields, in digits — 10 + 6 + 10 = 26. */
    public const BANK_DIGITS     = 10;
    public const CUSTOMER_DIGITS = 6;
    public const DOCUMENT_DIGITS = 10;

    /** The recursive modulo-10 table (SIX / former ESR). */
    private const TABLE = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];

    /**
     * The 27-digit reference of document $documentNumber to the debtor with
     * $customerNumber.
     *
     * @throws \InvalidArgumentException a number below 1, or one that does not fit its field
     */
    public static function forDocument(int $customerNumber, int $documentNumber): string
    {
        $payload = str_repeat('0', self::BANK_DIGITS)
            . self::field($customerNumber, self::CUSTOMER_DIGITS, 'customer number')
            . self::field($documentNumber, self::DOCUMENT_DIGITS, 'document number');

        return $payload . self::checkDigit($payload);
    }

    /** The check digit (modulo 10 recursive) of a digit string. */
    public static function checkDigit(string $digits): int
    {
        if (!preg_match('/^\d+$/', $digits)) {
            throw new \InvalidArgumentException('The QR check digit is computed over digits only');
        }
        $carry = 0;
        foreach (str_split($digits) as $digit) {
            $carry = self::TABLE[($carry + (int) $digit) % 10];
        }

        return (10 - $carry) % 10;
    }

    /** 27 digits, not all zero, the last one the check digit of the first 26. */
    public static function isValid(string $reference): bool
    {
        if (!preg_match('/^\d{' . self::LENGTH . '}$/', $reference) || trim($reference, '0') === '') {
            return false;
        }

        return self::checkDigit(substr($reference, 0, -1)) === (int) substr($reference, -1);
    }

    /** «21 00000 00003 13947 14300 09017» — blocks of five from the right, as the payment part prints it. */
    public static function format(string $reference): string
    {
        $head = strlen($reference) % 5;
        $out  = $head > 0 ? [substr($reference, 0, $head)] : [];

        return implode(' ', array_merge($out, str_split(substr($reference, $head), 5)));
    }

    /** One payload field: the number left-padded with zeros to $digits. */
    private static function field(int $number, int $digits, string $what): string
    {
        if ($number < 1) {
            throw new \InvalidArgumentException("A QR reference is built from a {$what} from 1");
        }
        $field = (string) $number;
        if (strlen($field) > $digits) {
            throw new \InvalidArgumentException(ucfirst($what) . " {$number} does not fit the {$digits} digits of the QR reference");
        }

        return str_pad($field, $digits, '0', STR_PAD_LEFT);
    }
}
