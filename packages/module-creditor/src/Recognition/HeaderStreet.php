<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * The creditor's STREET from the invoice's letterhead when the QR payment part has none (owner
 * 2026-11-15: a QR-bill may carry only «Swisscom (Schweiz) AG / 3050 Bern» — the standard allows
 * it —, and a new contact needs a street). Pure: header text in, the street line or null out.
 *
 * Taken ONLY when three consecutive lines of the header (the text above the payment part,
 * {@see SwissQrBillText::textBefore()}) read, in the same column (± {@see TOLERANCE} characters,
 * so `pdftotext -layout` with other columns beside works as well as `-raw`):
 *
 *     <the creditor name as the slip prints it>      «Swisscom (Schweiz) AG»
 *     <a street line>                                 «Alte Tiefenaustrasse 6»
 *     <the slip's postcode and town>                  «3050 Bern» (also «CH-3050 Bern»)
 *
 * Name and postcode + town must match EXACTLY (spaces and case aside); anything else — another
 * postcode, a «Postfach» line between, a line that is no street — leaves the street empty. Never
 * a guess: the owner completes the form instead.
 */
final class HeaderStreet
{
    private const TOLERANCE = 3;

    /** «Postfach 123», «Case postale», «Casella postale», «P.O. Box» — an address line, not a street. */
    private const PO_BOX = '/^(postfach|case postale|casella postale|p\.?\s?o\.?\s?box)\b/iu';

    public static function find(string $header, string $name, string $zip, string $town): ?string
    {
        $name = self::key($name);
        $town = self::key($zip . ' ' . $town);
        if ($name === '' || trim($zip) === '' || $town === '') {
            return null;
        }
        $lines = explode("\n", str_replace(["\r\n", "\r", "\f"], "\n", $header));
        foreach ($lines as $i => $line) {
            foreach (self::segments($line) as [$col, $text]) {
                if (self::key($text) !== $name) {
                    continue;
                }
                $street = self::segmentAt($lines[$i + 1] ?? '', $col);
                $place  = self::segmentAt($lines[$i + 2] ?? '', $col);
                if ($street === null || $place === null || !in_array(self::key($place), [$town, 'ch-' . $town, 'ch ' . $town], true)) {
                    continue;
                }
                if (preg_match('/\p{L}/u', $street) !== 1 || preg_match(self::PO_BOX, $street) === 1 || self::key($street) === $town || mb_strlen($street) > 120) {
                    continue;
                }

                return $street;
            }
        }

        return null;
    }

    /** The segment of $line that starts in column $col (± TOLERANCE), or null. */
    private static function segmentAt(string $line, int $col): ?string
    {
        foreach (self::segments($line) as [$start, $text]) {
            if (abs($start - $col) <= self::TOLERANCE) {
                return $text;
            }
        }

        return null;
    }

    /** @return list<array{0: int, 1: string}> column → text of each run of words joined by single spaces */
    private static function segments(string $line): array
    {
        $line = str_replace(["\t", "\u{00A0}"], ' ', $line);
        preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($matches[0] as [$text, $offset]) {
            $out[] = [mb_strlen(substr($line, 0, $offset)), $text];
        }

        return $out;
    }

    private static function key(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
