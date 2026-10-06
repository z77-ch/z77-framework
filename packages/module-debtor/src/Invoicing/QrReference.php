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
 * The payload z77 puts in: the document NUMBER, right-aligned and padded with
 * zeros (`forNumber()`). The number is unique per kind and only an invoice
 * carries a payment part, so the reference identifies the invoice — which is
 * what the CAMT.054 matching of P4 reads back (plan §6.4). No customer or
 * bank prefix: a bank that requires one (a «BESR-ID» in front of the
 * reference) is not served yet — `debtor.md` known issues.
 */
final class QrReference
{
    public const LENGTH = 27;

    /** The recursive modulo-10 table (SIX / former ESR). */
    private const TABLE = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];

    /** The 27-digit reference of document number $number. */
    public static function forNumber(int $number): string
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('A QR reference is built from a document number from 1');
        }
        $payload = str_pad((string) $number, self::LENGTH - 1, '0', STR_PAD_LEFT);
        if (strlen($payload) !== self::LENGTH - 1) {
            throw new \InvalidArgumentException('Document number too long for a QR reference');
        }

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
}
