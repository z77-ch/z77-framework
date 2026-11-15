<?php

namespace Z77\Module\Creditor\Recognition;

use Z77\Module\Mandator\Services\Uid;

/**
 * The Swico «S1» billing information of a QR-bill (Swico «Syntaxdefinition der
 * Rechnungsinformationen (S1) bei QR-Rechnungen» v1.3) as printed under «Zusätzliche
 * Informationen»: `//S1/10/<invoice no>/11/<date YYMMDD>/20/<customer ref>/30/<UID digits>/
 * 31/<VAT date(s)>/32/<VAT details>/40/<terms>`. A `/` inside a value is written `\/`.
 *
 * Read here: 10 invoice number, 11 invoice date, 20 customer reference, 30 the UID (validated
 * through module-mandator's `Uid` — a wrong check digit drops it), 31 VAT date or period,
 * 32 VAT details (`rate` or `rate:net;rate:net`), 40 terms (`percent:days;…`, `0:30` = 30 days
 * net) → {@see dueDate()}. Unknown tags (33 import tax, later versions) are kept in
 * {@see $raw} only. A value that does not fit its tag's syntax is dropped with a warning —
 * only validated values are taken (plan §4).
 */
final class SwicoBillingInfo
{
    public const PREFIX = '//S1/';

    /**
     * @param list<array{rate: string, net: ?string}> $vatRates
     * @param list<array{percent: string, days: int}> $terms
     * @param list<string> $warnings German
     */
    private function __construct(
        public readonly string $raw,
        public readonly ?string $invoiceNumber,
        public readonly ?\DateTimeImmutable $invoiceDate,
        public readonly ?string $customerReference,
        public readonly ?string $uid,
        public readonly ?\DateTimeImmutable $vatDateFrom,
        public readonly ?\DateTimeImmutable $vatDateTo,
        public readonly array $vatRates,
        public readonly array $terms,
        public readonly array $warnings,
    ) {}

    /** $text starts with {@see PREFIX}; whitespace inside (line wraps of the print) is removed. Null when it does not. */
    public static function parse(string $text): ?self
    {
        $raw = (string) preg_replace('/\s+/u', '', $text);
        if (!str_starts_with($raw, self::PREFIX)) {
            return null;
        }
        $parts = preg_split('~(?<!\\\\)/~', substr($raw, strlen(self::PREFIX)));
        $values = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            if (!preg_match('/^\d{2}$/', $parts[$i])) {
                break;
            }
            $values[$parts[$i]] = str_replace('\\/', '/', $parts[$i + 1]);
        }

        $warnings = [];
        $date     = static function (string $tag, string $yymmdd) use (&$warnings): ?\DateTimeImmutable {
            $parsed = self::date($yymmdd);
            if ($parsed === null) {
                $warnings[] = "Swico-Angabe {$tag}: Datum «{$yymmdd}» ungültig";
            }

            return $parsed;
        };

        $invoiceDate = isset($values['11']) ? $date('11', $values['11']) : null;
        $uid         = null;
        if (isset($values['30'])) {
            $candidate = Uid::normalize('CHE' . $values['30']);
            if (Uid::hasValidCheckDigit($candidate)) {
                $uid = $candidate;
            } else {
                $warnings[] = "Swico-Angabe 30: UID «{$values['30']}» ungültig";
            }
        }
        $vatFrom = $vatTo = null;
        if (isset($values['31'])) {
            if (preg_match('/^(\d{6})(\d{6})?$/', $values['31'], $m)) {
                $vatFrom = $date('31', $m[1]);
                $vatTo   = isset($m[2]) ? $date('31', $m[2]) : null;
            } else {
                $warnings[] = 'Swico-Angabe 31: MWST-Datum ungültig';
            }
        }

        return new self(
            $raw,
            self::text($values['10'] ?? null),
            $invoiceDate,
            self::text($values['20'] ?? null),
            $uid,
            $vatFrom,
            $vatTo,
            isset($values['32']) ? self::vatRates($values['32'], $warnings) : [],
            isset($values['40']) ? self::terms($values['40'], $warnings) : [],
            $warnings,
        );
    }

    /** The invoice date plus the net term (the `0:<days>` entry, else the longest term); null without both. */
    public function dueDate(): ?\DateTimeImmutable
    {
        if ($this->invoiceDate === null || $this->terms === []) {
            return null;
        }
        $days = null;
        foreach ($this->terms as $term) {
            if ((float) $term['percent'] === 0.0) {
                $days = $term['days'];
            }
        }
        $days ??= max(array_column($this->terms, 'days'));

        return $this->invoiceDate->modify('+' . $days . ' days');
    }

    private static function date(string $yymmdd): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m) || !checkdate((int) $m[2], (int) $m[3], 2000 + (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('20%s-%s-%s', $m[1], $m[2], $m[3]));
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param list<string> $warnings
     * @return list<array{rate: string, net: ?string}>
     */
    private static function vatRates(string $value, array &$warnings): array
    {
        $rates = [];
        foreach (explode(';', $value) as $entry) {
            if (!preg_match('/^(\d{1,2}(?:\.\d{1,2})?)(?::(\d+(?:\.\d{1,2})?))?$/', $entry, $m)) {
                $warnings[] = "Swico-Angabe 32: MWST «{$entry}» ungültig";

                return [];
            }
            $rates[] = ['rate' => $m[1], 'net' => $m[2] ?? null];
        }

        return $rates;
    }

    /**
     * @param list<string> $warnings
     * @return list<array{percent: string, days: int}>
     */
    private static function terms(string $value, array &$warnings): array
    {
        $terms = [];
        foreach (explode(';', $value) as $entry) {
            if (!preg_match('/^(\d{1,2}(?:\.\d{1,2})?):(\d{1,3})$/', $entry, $m)) {
                $warnings[] = "Swico-Angabe 40: Kondition «{$entry}» ungültig";

                return [];
            }
            $terms[] = ['percent' => $m[1], 'days' => (int) $m[2]];
        }

        return $terms;
    }
}
