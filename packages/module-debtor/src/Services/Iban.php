<?php

namespace Z77\Module\Debtor\Services;

/**
 * What an IBAN is, checked rather than assumed — pure functions, no state,
 * no storage (plan §6.1, {@see \Z77\Module\Debtor\Entities\PaymentTarget}).
 *
 * Three questions, and they are deliberately separate:
 *
 *   - {@see isWellFormed()} — shape only: two country letters, two check
 *     digits, then letters and digits, 15 to 34 characters (the ISO 13616
 *     range), and the exact length the country registry gives when we know
 *     it (CH and LI: 21);
 *   - {@see hasValidCheckDigits()} — the ISO 7064 MOD-97-10 checksum, which
 *     is what catches a transposed pair of digits. Computed on the DIGIT
 *     STRING in chunks, never as an integer: a 34-character IBAN expands to
 *     ~70 digits and would overflow `int` on any platform;
 *   - {@see isQrIban()} — a Swiss QR-IBAN, recognised by its institution
 *     identification (IID, positions 5–9) lying in **30000–31999**, the
 *     range SIX reserved for QR-IBANs. A QR-IBAN carries a QR reference
 *     (QRR); a normal IBAN carries a creditor reference (SCOR) or none —
 *     which is why the two must be told apart before a QR-bill is printed
 *     (P3 part 2).
 *
 * The QR-bill is issued in Switzerland: a payment target's IBAN must be a
 * CH or LI one ({@see isSwissArea()}), and the validator says so.
 */
final class Iban
{
    /** The IID range SIX reserved for QR-IBANs (inclusive). */
    public const QR_IID_MIN = 30000;
    public const QR_IID_MAX = 31999;

    /** Country code → the exact IBAN length, for the countries a payment target may use. */
    private const LENGTHS = ['CH' => 21, 'LI' => 21];

    /** Upper-cased, every space and non-alphanumeric character removed — how an IBAN is stored and compared. */
    public static function normalize(string $iban): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($iban)));
    }

    /** «CH93 0076 2011 6238 5295 7» — grouped in fours for a screen or a document. */
    public static function format(string $iban): string
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }

    /** Shape only — the checksum is {@see hasValidCheckDigits()}. */
    public static function isWellFormed(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $expected = self::LENGTHS[substr($iban, 0, 2)] ?? null;

        return $expected === null || strlen($iban) === $expected;
    }

    /** ISO 7064 MOD-97-10: move the first four characters to the end, letters → 10..35, remainder must be 1. */
    public static function hasValidCheckDigits(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $digits     = '';
        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_digit($character) ? $character : (string) (ord($character) - 55);
        }

        // Chunked modulo — a 34-character IBAN is ~70 digits and overflows int.
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (((string) $remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }

    /** A CH or LI IBAN — the only ones a QR-bill may name as the creditor account. */
    public static function isSwissArea(string $iban): bool
    {
        return in_array(substr(self::normalize($iban), 0, 2), ['CH', 'LI'], true);
    }

    /**
     * The institution identification (IID), positions 5–9 of a CH / LI
     * IBAN. Null for any other country or a shape that has no IID.
     */
    public static function iid(string $iban): ?int
    {
        $iban = self::normalize($iban);
        if (!self::isSwissArea($iban) || !preg_match('/^[A-Z]{2}\d{7}/', $iban)) {
            return null;
        }

        return (int) substr($iban, 4, 5);
    }

    /** A QR-IBAN: a CH / LI IBAN whose IID lies in 30000–31999 (it carries a QR reference, QRR). */
    public static function isQrIban(string $iban): bool
    {
        $iid = self::iid($iban);

        return $iid !== null && $iid >= self::QR_IID_MIN && $iid <= self::QR_IID_MAX;
    }
}
