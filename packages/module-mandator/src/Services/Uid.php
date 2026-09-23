<?php

namespace Z77\Module\Mandator\Services;

/**
 * The Swiss Unternehmens-Identifikationsnummer (UID, eCH-0097): `CHE` and
 * nine digits, the last a check digit, printed `CHE-123.456.789` — with the
 * suffix ` MWST` when it names a VAT registration. Pure, like debtor's
 * `Iban`: three questions kept apart so the German message can say what is
 * actually wrong.
 *
 * Check digit (eCH-0097 §4.2, modulo 11): the first eight digits are weighted
 * 5, 4, 3, 2, 7, 6, 5, 4 from the left, the products summed, the sum taken
 * modulo 11; check = 11 − remainder; a result of 11 is written 0, a result
 * of 10 means the number is INVALID (such numbers are never assigned).
 * `CHE-109.322.551` → 5+0+27+6+14+12+25+20 = 109, 109 mod 11 = 10, check 1.
 */
final class Uid
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4];

    /**
     * `che-109322551`, `CHE 109.322.551 MWST`, `CHE-109.322.551` → the
     * canonical `CHE-109.322.551`. Anything that is not `CHE` plus nine
     * digits after stripping spaces, dots, hyphens and a trailing register
     * suffix (MWST / HR / IDE / IVA / TVA) comes back trimmed and upper-cased
     * as it was, so the validator can name the format.
     */
    public static function normalize(string $uid): string
    {
        $compact = mb_strtoupper(trim($uid));
        if ($compact === '') {
            return '';
        }
        $compact = preg_replace('/\s+(MWST|HR|IDE|IVA|TVA)$/u', '', $compact);
        $stripped = preg_replace('/[\s.\-]/u', '', $compact);

        if (!preg_match('/^CHE(\d{9})$/', $stripped, $m)) {
            return trim($uid) === '' ? '' : mb_strtoupper(trim($uid));
        }

        return 'CHE-' . substr($m[1], 0, 3) . '.' . substr($m[1], 3, 3) . '.' . substr($m[1], 6, 3);
    }

    /** Exactly the canonical shape `CHE-ddd.ddd.ddd`. */
    public static function isWellFormed(string $uid): bool
    {
        return preg_match('/^CHE-\d{3}\.\d{3}\.\d{3}$/', $uid) === 1;
    }

    /**
     * The modulo-11 check digit of a well-formed UID holds. `CHE-000.000.000`
     * is refused although its arithmetic works out (Σ = 0 → check 0): nine
     * zeros are no number, only a placeholder — and the field is optional,
     * so nobody needs one (review 2026-09-23, P9b). The number range is NOT
     * otherwise restricted: the register's actual range is not verified
     * here, and a wrong assumption would refuse real numbers.
     */
    public static function hasValidCheckDigit(string $uid): bool
    {
        if (!self::isWellFormed($uid)) {
            return false;
        }
        $digits = str_replace(['CHE-', '.'], '', $uid);
        if ($digits === '000000000') {
            return false;
        }
        $sum    = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }
        $check = 11 - ($sum % 11);
        if ($check === 10) {
            return false;
        }
        if ($check === 11) {
            $check = 0;
        }

        return (int) $digits[8] === $check;
    }
}
