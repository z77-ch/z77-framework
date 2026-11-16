<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * The STABLE features of a supplier's text layer that tell who sent the invoice when it carries
 * neither a known IBAN nor a Swiss UID (owner 2026-11-16: «er soll einen bestimmten Bereich einer
 * Rechnung, der immer gleich ist, definieren und ablegen, so dass er ihn erkennt» — the foreign
 * creditors Tinify (USD, no QR slip) and Allegion (NL) were not found although both exist). Pure:
 * text in, normalised values out; `CreditorLearningService` stores them per creditor and matches
 * them. Never a hash of the whole text — date, amount and number change on every invoice.
 *
 *   - {@see VAT} — a FOREIGN VAT number in its national format (`NL…B01`, `DE…`, `ATU…`, the OSS
 *     number `EU…`, `GB…`, …), normalised to upper case without separators. An IBAN is never one:
 *     a match must not be followed by a further digit, and the formats are fixed-length. Strong —
 *     one unique VAT number decides alone ({@see WEIGHT}).
 *   - {@see DOMAIN} — the registrable domain of a mail address, a URL or a bare host
 *     (`billing@tinify.com` → `tinify.com`); generic mail hosts and payment platforms
 *     ({@see GENERIC_DOMAINS}) and the installation's own domains ({@see OwnParty}) never count.
 *   - {@see SENDER} — a letterhead line: a column segment of the layout text that contains the
 *     creditor's NAME (`tinify b.v., kanaalweg 17`), normalised; never a segment of the own company,
 *     never one with a date or an amount (it changes), at most {@see MAX_SENDER} per invoice.
 *
 * Matching ({@see WEIGHT}, {@see DECIDES}): a feature counts only when exactly ONE creditor holds
 * it; a creditor is decided when its unique features weigh at least {@see DECIDES} — a VAT number,
 * or two of domain / sender line. A domain alone never decides (a payment platform the list does
 * not know, a reseller's link) — never a guess.
 */
final class SenderFeatures
{
    public const VAT    = 'vat';
    public const DOMAIN = 'domain';
    public const SENDER = 'sender';
    public const KINDS  = [self::VAT, self::DOMAIN, self::SENDER];

    /** What one unique feature adds towards deciding the creditor. */
    public const WEIGHT  = [self::VAT => 2, self::DOMAIN => 1, self::SENDER => 1];
    /** The weight that decides the creditor. */
    public const DECIDES = 2;

    /** The longest stored value (`creditor_learned.value`). */
    public const MAX_LENGTH = 160;
    /** The most sender lines learned from one invoice. */
    public const MAX_SENDER = 5;
    /** The most segments a text offers for matching (a long multi-page invoice). */
    private const MAX_SEGMENTS = 2000;

    /**
     * National VAT formats after the country prefix — digits may carry a space, dot or hyphen
     * between them; the whole number must not be followed by a further digit (an IBAN). CHE is the
     * Swiss UID, matched elsewhere (`InvoiceFacts::letterheadUid()`).
     */
    private const VAT_FORMATS = [
        'AT' => 'U[ ]?(?:\d[ .\-]?){7}\d',
        'BE' => '[01][ .\-]?(?:\d[ .\-]?){8}\d',
        'DE' => '(?:\d[ .\-]?){8}\d',
        'DK' => '(?:\d[ .\-]?){7}\d',
        'EL' => '(?:\d[ .\-]?){8}\d',
        'ES' => '[0-9A-Z]\d{7}[0-9A-Z]',
        'EU' => '(?:\d[ .\-]?){8}\d',
        'FI' => '(?:\d[ .\-]?){7}\d',
        'FR' => '[0-9A-HJ-NP-Z]{2}[ ]?(?:\d[ .\-]?){8}\d',
        'GB' => '(?:(?:\d[ .\-]?){11}\d|(?:\d[ .\-]?){8}\d)',
        'IE' => '\d[0-9A-Z+*]\d{5}[A-Z]{1,2}',
        'IT' => '(?:\d[ .\-]?){10}\d',
        'LU' => '(?:\d[ .\-]?){7}\d',
        'NL' => '(?:\d[ .\-]?){8}\d[ .]?B[ .]?\d{2}',
        'NO' => '(?:\d[ .\-]?){8}\d(?:[ ]?MVA)?',
        'PL' => '(?:\d[ .\-]?){9}\d',
        'PT' => '(?:\d[ .\-]?){8}\d',
        'SE' => '(?:\d[ .\-]?){11}\d',
        'XI' => '(?:\d[ .\-]?){8}\d',
    ];

    /** Mail hosts and billing platforms many suppliers share — a domain of theirs says nothing about who sent the invoice. */
    public const GENERIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'icloud.com', 'me.com', 'mac.com',
        'aol.com', 'gmx.ch', 'gmx.de', 'gmx.net', 'gmx.at', 'web.de', 't-online.de', 'bluewin.ch', 'sunrise.ch', 'hispeed.ch', 'proton.me',
        'protonmail.com', 'protonmail.ch', 'mail.com', 'yandex.com', 'zoho.com',
        'stripe.com', 'paypal.com', 'paddle.com', 'paddle.net', 'fastspring.com', 'chargebee.com', 'lemonsqueezy.com', 'gumroad.com',
        'recurly.com', 'braintreegateway.com', 'adyen.com', 'mollie.com', 'twint.ch', 'datatrans.ch', 'payrexx.com', 'sumup.com',
        'paymentstandards.ch', 'six-group.com', 'einfach-zahlen.ch', 'w3.org',
    ];

    /** Second-level labels under which the registrable domain has three labels (`example.co.uk`). */
    private const SECOND_LEVEL = ['co', 'com', 'org', 'net', 'ac', 'gv', 'or', 'gov'];

    /** Top-level domains a BARE host (no `@`, no `www.`, no scheme) is taken with — a file name like `invoice.pdf` never is one. */
    private const BARE_TLDS = 'com|net|org|io|ch|de|at|nl|eu|uk|fr|it|li|be|lu|se|dk|es|app|dev|co';

    /**
     * Every feature $text offers for MATCHING: the VAT numbers, the domains (generic and own ones
     * left out) and every column segment (the sender lines are looked up among them).
     *
     * @return array{vat: list<string>, domain: list<string>, sender: list<string>}
     */
    public static function inText(string $text, OwnParty $own): array
    {
        return [
            self::VAT    => self::vatNumbers($text),
            self::DOMAIN => self::domains($text, $own),
            self::SENDER => array_values(array_filter(self::segments($text), static fn(string $s): bool => !$own->isOwnSegment($s))),
        ];
    }

    /**
     * What a creditor learns from one invoice's $text, once the owner settled who sent it: its VAT
     * numbers, its domains and the sender lines that contain one of $names (the contact's company /
     * display name).
     *
     * @param list<string> $names
     * @return array{vat: list<string>, domain: list<string>, sender: list<string>}
     */
    public static function toLearn(string $text, array $names, OwnParty $own): array
    {
        $keys = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $names), static fn(string $n): bool => mb_strlen($n) >= 3)));
        $sender = [];
        foreach (self::segments($text) as $segment) {
            if (count($sender) >= self::MAX_SENDER) {
                break;
            }
            if ($own->isOwnSegment($segment) || self::changes($segment)) {
                continue;
            }
            foreach ($keys as $key) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($key, '/') . '(?![\p{L}\p{N}])/u', $segment)) {
                    $sender[] = $segment;
                    break;
                }
            }
        }

        return [self::VAT => self::vatNumbers($text), self::DOMAIN => self::domains($text, $own), self::SENDER => $sender];
    }

    /** @return list<string> the foreign VAT numbers $text prints, normalised (`NL123456789B01`), each once */
    public static function vatNumbers(string $text): array
    {
        $found = [];
        foreach (self::VAT_FORMATS as $country => $format) {
            if (!preg_match_all('/(?<![A-Za-z0-9])' . $country . '[ ]?(' . $format . ')(?![ .\-]?\d)(?![A-Za-z0-9])/u', $text, $m)) {
                continue;
            }
            foreach ($m[1] as $number) {
                $found[$country . strtoupper((string) preg_replace('/[ .\-]/', '', $number))] = true;
            }
        }

        return array_keys($found);
    }

    /** @return list<string> the registrable domains of the mail addresses, URLs and bare hosts in $text — generic and own ones left out */
    public static function domains(string $text, OwnParty $own): array
    {
        $hosts = [];
        if (preg_match_all('/[A-Za-z0-9._%+\-]+@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+)/u', $text, $m)) {
            array_push($hosts, ...$m[1]);
        }
        if (preg_match_all('#(?:https?://|(?<![\w.\-@/])www\.)([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+)#iu', $text, $m)) {
            array_push($hosts, ...$m[1]);
        }
        if (preg_match_all('/(?<![\w.\-@\/])((?:[a-z0-9\-]+\.)*[a-z0-9\-]*[a-z][a-z0-9\-]*\.(?:' . self::BARE_TLDS . '))(?![\w\-]|\.\w)/iu', $text, $m)) {
            array_push($hosts, ...$m[1]);
        }
        $out = [];
        foreach ($hosts as $host) {
            $domain = self::registrableDomain($host);
            if ($domain !== '' && !in_array($domain, self::GENERIC_DOMAINS, true) && !$own->isOwnDomain($domain)) {
                $out[$domain] = true;
            }
        }

        return array_keys($out);
    }

    /** `Billing.Tinify.com.` → `tinify.com`; `shop.example.co.uk` → `example.co.uk`; '' for no domain. */
    public static function registrableDomain(string $host): string
    {
        $labels = array_values(array_filter(explode('.', strtolower(trim($host, " .\t\n\r"))), static fn(string $l): bool => $l !== ''));
        if (count($labels) < 2) {
            return '';
        }
        $take = count($labels) >= 3 && strlen($labels[count($labels) - 1]) === 2 && in_array($labels[count($labels) - 2], self::SECOND_LEVEL, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /**
     * The column segments of a layout text, normalised and each once: every line split where two or
     * more spaces separate columns. Segments shorter than 3 or longer than {@see MAX_LENGTH} are left out.
     *
     * @return list<string>
     */
    public static function segments(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R|\f/u', $text) ?: [] as $line) {
            foreach (preg_split('/\s{2,}|\t/u', $line) ?: [] as $segment) {
                $segment = self::normalize($segment);
                $length  = mb_strlen($segment);
                if ($length >= 3 && $length <= self::MAX_LENGTH && preg_match('/\p{L}/u', $segment)) {
                    $out[$segment] = true;
                    if (count($out) >= self::MAX_SEGMENTS) {
                        break 2;
                    }
                }
            }
        }

        return array_map('strval', array_keys($out));
    }

    /** Lower case, every run of white space one space, separators at both ends trimmed. */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(str_replace(["\u{00A0}", "\u{202F}", "\t"], ' ', $value));

        return trim((string) preg_replace('/\s+/u', ' ', $value), " ,;:·|•-–—\t\n\r");
    }

    /** A segment that differs from invoice to invoice — it carries a date, a month with its year, an amount or a «#123» number. */
    private static function changes(string $segment): bool
    {
        if (preg_match('/\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}|\d{4}-\d{2}-\d{2}|\d[.,]\d{2}(?!\d)|#\s?\d/u', $segment)) {
            return true;
        }
        if (preg_match_all('/(\p{L}+)\.?\s+(?:\d{1,2},?\s+)?20\d{2}(?!\d)/u', $segment, $m)) {
            foreach ($m[1] as $word) {
                if (LearnedLabels::month($word) !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
