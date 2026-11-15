<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Shared\Payment\QrReference;

/**
 * What the 26 payload digits of a debtor's QR reference (QRR) MEAN — the
 * standard itself (27 digits, the modulo-10-recursive check digit, the
 * printed blocks) is the kernel's {@see QrReference} since 2026-11-15
 * (creditor plan §2.5; this class was `Invoicing\QrReference` until then).
 * z77 prints QRR with a QR-IBAN and NON with a plain IBAN, no SCOR (owner
 * 2026-09-23).
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
final class QrReferenceLayout
{
    /** The three payload fields, in digits — 10 + 6 + 10 = 26. */
    public const BANK_DIGITS     = 10;
    public const CUSTOMER_DIGITS = 6;
    public const DOCUMENT_DIGITS = 10;

    /**
     * The 27-digit reference of document $documentNumber to the debtor with
     * $customerNumber.
     *
     * @throws \InvalidArgumentException a number below 1, or one that does not fit its field
     */
    public static function forDocument(int $customerNumber, int $documentNumber): string
    {
        return QrReference::withCheckDigit(
            str_repeat('0', self::BANK_DIGITS)
            . self::field($customerNumber, self::CUSTOMER_DIGITS, 'customer number')
            . self::field($documentNumber, self::DOCUMENT_DIGITS, 'document number')
        );
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
