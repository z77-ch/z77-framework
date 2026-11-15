<?php

namespace Z77\Module\Creditor\Recognition;

use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Iban;
use Z77\Shared\Payment\QrReference;
use Z77\Shared\Payment\ScorReference;

/**
 * Reads the PAYMENT PART of a Swiss QR-bill from the TEXT LAYER of a PDF (creditor plan §4
 * step 1; owner answer §11.6: the QR image is not decoded — digital bills are read from their
 * text, scans go to the AI). Pure: text in, {@see RecognizedPayment} or null out.
 *
 * How it finds things — by the HEADINGS the SIX style guide prescribes, in DE / FR / IT / EN
 * ({@see LABELS}): «Konto / Zahlbar an», «Referenz», «Zusätzliche Informationen», «Zahlbar
 * durch», «Währung», «Betrag» and the titles «Empfangsschein» / «Zahlteil». A heading is a text
 * SEGMENT (words separated by single spaces; two or more spaces separate segments) made of
 * labels only — «Betrag gemäss Offerte» in a message is no heading. The value of a heading is
 * the text below it in the SAME COLUMN (± {@see TOLERANCE} characters), down to the next
 * heading or a gap; this reads `pdftotext -layout` (receipt and payment part side by side)
 * and `-raw` (one column) alike. Where two columns ran together with a single space, the
 * column is cut at the word that starts at the heading's position.
 *
 * Only the LAST page that carries «Konto / Zahlbar an» together with a title or the currency
 * heading is read, from the payment part's first heading down — a «Betrag» column of the
 * invoice body above is never taken for the slip.
 *
 * The receipt and the payment part print the same data twice: both are read and compared —
 * two different IBANs, references, currencies or amounts are a warning; the payment part's
 * value wins (the right-hand column in the layout, the later one in stream order). Only
 * validated values are taken: the IBAN through `Iban` (check digits), the reference through
 * `QrReference` / `ScorReference`, the amount within the QR-bill range. Amounts are read with
 * the guidelines' space grouping («1 234.50») and the apostrophe grouping («1'234.50»).
 *
 * Returns null when no payment part is found — a plain invoice, or a slip without text layer.
 */
final class SwissQrBillText
{
    private const RECEIPT          = 'receipt';
    private const PAYMENT          = 'payment';
    private const ACCOUNT          = 'account';
    private const REFERENCE        = 'reference';
    private const INFO             = 'info';
    private const FURTHER          = 'further';
    private const CURRENCY         = 'currency';
    private const AMOUNT           = 'amount';
    private const PAYABLE_BY       = 'payable_by';
    private const PAYABLE_BY_BLANK = 'payable_by_blank';
    private const ACCEPTANCE       = 'acceptance';
    private const SEPARATE         = 'separate';

    /** The headings of the SIX style guide (QR-bill v2.x), DE / FR / IT / EN. */
    private const LABELS = [
        self::RECEIPT          => ['Empfangsschein', 'Récépissé', 'Ricevuta', 'Receipt'],
        self::PAYMENT          => ['Zahlteil', 'Section paiement', 'Sezione pagamento', 'Payment part'],
        self::ACCOUNT          => ['Konto / Zahlbar an', 'Compte / Payable à', 'Conto / Pagabile a', 'Account / Payable to'],
        self::REFERENCE        => ['Referenz', 'Référence', 'Riferimento', 'Reference'],
        self::INFO             => ['Zusätzliche Informationen', 'Informations supplémentaires', 'Informazioni supplementari', 'Additional information'],
        self::FURTHER          => ['Weitere Informationen', 'Informations additionnelles', 'Informazioni aggiuntive', 'Further information'],
        self::CURRENCY         => ['Währung', 'Monnaie', 'Valuta', 'Currency'],
        self::AMOUNT           => ['Betrag', 'Montant', 'Importo', 'Amount'],
        self::PAYABLE_BY       => ['Zahlbar durch', 'Payable par', 'Pagabile da', 'Payable by'],
        self::PAYABLE_BY_BLANK => ['Zahlbar durch (Name/Adresse)', 'Payable par (nom/adresse)', 'Pagabile da (nome/indirizzo)', 'Payable by (name/address)'],
        self::ACCEPTANCE       => ['Annahmestelle', 'Point de dépôt', 'Punto di accettazione', 'Acceptance point'],
        self::SEPARATE         => ['Vor der Einzahlung abzutrennen', 'A détacher avant le versement', 'Da staccare prima del versamento', 'Separate before paying in'],
    ];

    /** The note on a slip that only informs (amount 0.00 or a copy), DE / FR / IT / EN. */
    private const NOT_FOR_PAYMENT = ['nicht zur zahlung verwenden', 'ne pas utiliser pour le paiement', 'non utilizzare per il pagamento', 'do not use for payment'];

    /** How far (characters) a value may start from its heading's column. */
    private const TOLERANCE = 3;

    /**
     * How far a word inside a run-together segment may start from the column to be cut off —
     * tight, because a left value that merely runs past the column («21 00000 … 09017») must
     * not lose its last group to the right column.
     */
    private const CUT_TOLERANCE = 1;

    private const MAX_LABEL_WORDS = 6;

    /** Accented letters the labels carry, folded so a font without them still matches. */
    private const FOLD = ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ì' => 'i', 'î' => 'i', 'ò' => 'o', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c'];

    /** @var array<string, string>|null folded label → kind */
    private static ?array $lookup = null;

    /** @var list<string> */
    private array $lines = [];

    /** @var array<int, list<array{col: int, text: string, kinds: list<array{kind: string, col: int}>}>> segments per line */
    private array $segments = [];

    /** The best reading of several extractions of the same PDF (`-layout`, `-raw`); differing readings are a warning. */
    public static function parseBest(string ...$texts): ?RecognizedPayment
    {
        $readings = array_values(array_filter(array_map(self::parse(...), $texts)));
        if ($readings === []) {
            return null;
        }
        usort($readings, static fn(RecognizedPayment $a, RecognizedPayment $b): int => self::score($b) <=> self::score($a));
        $best     = $readings[0];
        $warnings = [];
        foreach (array_slice($readings, 1) as $other) {
            foreach (['iban' => 'IBAN', 'reference' => 'Referenz', 'currency' => 'Währung'] as $field => $label) {
                if ($best->$field !== null && $other->$field !== null && $best->$field !== $other->$field) {
                    $warnings[] = "Die Textauszüge des PDF widersprechen sich ({$label})";
                }
            }
            if ($best->amount !== null && $other->amount !== null && !$best->amount->equals($other->amount)) {
                $warnings[] = 'Die Textauszüge des PDF widersprechen sich (Betrag)';
            }
        }

        return $warnings === [] ? $best : $best->withWarnings($warnings);
    }

    public static function parse(string $text): ?RecognizedPayment
    {
        $pages = explode("\f", str_replace(["\r\n", "\r"], "\n", $text));
        for ($p = count($pages) - 1; $p >= 0; $p--) {
            $reader = new self(explode("\n", $pages[$p]));
            $found  = $reader->read();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private function __construct(array $lines)
    {
        $this->lines = $lines;
        foreach ($lines as $index => $line) {
            $this->segments[$index] = self::segmentsOf($line);
        }
    }

    private function read(): ?RecognizedPayment
    {
        $headings = [];
        foreach ($this->segments as $line => $segments) {
            foreach ($segments as $segment) {
                foreach ($segment['kinds'] as $heading) {
                    $headings[] = ['kind' => $heading['kind'], 'line' => $line, 'col' => $heading['col']];
                }
            }
        }
        $headings = $this->paymentPart($headings);
        if ($headings === null) {
            return null;
        }
        $of = static fn(string ...$kinds): array => array_values(array_filter($headings, static fn(array $h): bool => in_array($h['kind'], $kinds, true)));
        $warnings = [];

        // ── account and creditor ───────────────────────────────────────
        $accounts = [];
        foreach ($of(self::ACCOUNT) as $heading) {
            $block = $this->blockBelow($heading, 6);
            if ($block === []) {
                continue;
            }
            [$iban, $creditor] = self::ibanAndRest($block);
            if ($iban === null) {
                $warnings[] = 'IBAN «' . $block[0] . '» ist ungültig';
                continue;
            }
            $accounts[] = ['value' => $iban, 'creditor' => $creditor, 'key' => self::key($heading)];
        }
        $account = self::preferred($accounts, 'IBAN', $warnings, static fn(string $v): string => Iban::format($v));
        $creditorLines = [];
        foreach (self::byPreference($accounts) as $candidate) {
            if ($candidate['creditor'] !== []) {
                $creditorLines = $candidate['creditor'];
                break;
            }
        }
        if ($account === null && $warnings === []) {
            $warnings[] = 'Keine gültige IBAN im Zahlteil gefunden';
        }
        $isQrIban = $account !== null && Iban::isQrIban($account);

        // ── reference ──────────────────────────────────────────────────
        $references = [];
        $printed    = $of(self::REFERENCE);
        foreach ($printed as $heading) {
            $block = $this->blockBelow($heading, 2);
            if ($block === []) {
                continue;
            }
            $value = self::reference(implode('', $block));
            if ($value === null) {
                $warnings[] = 'Referenz «' . implode(' ', $block) . '» ist ungültig';
                continue;
            }
            $references[] = ['value' => $value, 'key' => self::key($heading)];
        }
        $reference     = self::preferred($references, 'Referenzen', $warnings);
        $referenceType = match (true) {
            $reference !== null       => ctype_digit($reference) ? RecognizedPayment::REFERENCE_QRR : RecognizedPayment::REFERENCE_SCOR,
            $printed === []           => RecognizedPayment::REFERENCE_NON,
            default                   => null,
        };
        if ($account !== null && $isQrIban && $referenceType !== null && $referenceType !== RecognizedPayment::REFERENCE_QRR) {
            $warnings[] = 'Eine QR-IBAN verlangt eine QR-Referenz';
        }
        if ($account !== null && !$isQrIban && $referenceType === RecognizedPayment::REFERENCE_QRR) {
            $warnings[] = 'Eine QR-Referenz gilt nur mit einer QR-IBAN';
        }

        // ── currency and amount ────────────────────────────────────────
        $currencies = [];
        $amounts    = [];
        foreach ($of(self::CURRENCY) as $heading) {
            $value = $this->valueNear($heading);
            if ($value !== null && preg_match('/^(CHF|EUR)(?:\s+(.+))?$/u', $value, $m)) {
                $currencies[] = ['value' => $m[1], 'key' => self::key($heading)];
                if (isset($m[2])) {
                    $amounts[] = ['value' => self::amount($m[2], $warnings), 'key' => self::key($heading)];
                }
            }
        }
        foreach ($of(self::AMOUNT) as $heading) {
            $value = $this->valueNear($heading);
            if ($value !== null) {
                $amounts[] = ['value' => self::amount($value, $warnings), 'key' => self::key($heading)];
            }
        }
        $currency = self::preferred($currencies, 'Währungen', $warnings);
        $decimal  = self::preferred(array_values(array_filter($amounts, static fn(array $a): bool => $a['value'] !== null)), 'Beträge', $warnings);
        $amount   = null;
        if ($decimal !== null && $currency === null) {
            $warnings[] = 'Betrag ohne Währung — nicht übernommen';
        } elseif ($decimal !== null) {
            $amount = Money::fromDecimal($decimal, $currency);
        }

        // ── message and Swico S1 ───────────────────────────────────────
        $message = null;
        $billing = null;
        $info    = self::byPreference(array_map(fn(array $h): array => ['block' => $this->blockBelow($h, 8), 'key' => self::key($h)], $of(self::INFO)));
        $infoBlock = $info[0]['block'] ?? [];
        if ($infoBlock !== []) {
            $joined = implode("\n", $infoBlock);
            $at     = strpos($joined, SwicoBillingInfo::PREFIX);
            $plain  = $at === false ? $joined : substr($joined, 0, $at);
            $message = trim((string) preg_replace('/\s+/u', ' ', $plain));
            $message = $message === '' ? null : $message;
            if ($at !== false) {
                $billing  = SwicoBillingInfo::parse(substr($joined, $at));
                $warnings = array_merge($warnings, $billing?->warnings ?? []);
            }
        }

        // ── debtor ─────────────────────────────────────────────────────
        $debtor = self::byPreference(array_map(fn(array $h): array => ['block' => $this->blockBelow($h, 5), 'key' => self::key($h)], $of(self::PAYABLE_BY)));
        $debtorLines = $debtor[0]['block'] ?? [];

        // ── «not for payment» ──────────────────────────────────────────
        $notForPayment = false;
        $first         = min(array_column($headings, 'line'));
        foreach (array_slice($this->lines, $first) as $line) {
            $folded = self::fold($line);
            foreach (self::NOT_FOR_PAYMENT as $phrase) {
                if (str_contains($folded, $phrase)) {
                    $notForPayment = true;
                }
            }
        }
        if ($notForPayment) {
            $warnings[] = 'Der Zahlteil ist mit «Nicht zur Zahlung verwenden» markiert';
        }

        [$postalCode, $town] = self::postalCodeAndTown($creditorLines);

        return new RecognizedPayment(
            iban: $account,
            isQrIban: $isQrIban,
            currency: $currency,
            amount: $amount,
            referenceType: $referenceType,
            reference: $reference,
            creditorName: $creditorLines[0] ?? null,
            creditorAddress: array_slice($creditorLines, 1),
            creditorPostalCode: $postalCode,
            creditorTown: $town,
            debtorName: $debtorLines[0] ?? null,
            debtorAddress: array_slice($debtorLines, 1),
            message: $message,
            billing: $billing,
            notForPayment: $notForPayment,
            occurrences: count($accounts),
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * The headings of the payment part, or null when this page has none: «Konto / Zahlbar an»
     * plus a title or the currency heading; read from the title nearest above the first
     * account heading (or that heading itself) down.
     *
     * @param list<array{kind: string, line: int, col: int}> $headings
     * @return list<array{kind: string, line: int, col: int}>|null
     */
    private function paymentPart(array $headings): ?array
    {
        $kinds = array_column($headings, 'kind');
        if (!in_array(self::ACCOUNT, $kinds, true)
            || array_intersect([self::RECEIPT, self::PAYMENT, self::CURRENCY], $kinds) === []) {
            return null;
        }
        $firstAccount = min(array_column(array_filter($headings, static fn(array $h): bool => $h['kind'] === self::ACCOUNT), 'line'));
        $start        = $firstAccount;
        foreach ($headings as $heading) {
            if (in_array($heading['kind'], [self::RECEIPT, self::PAYMENT, self::SEPARATE], true) && $heading['line'] <= $firstAccount) {
                $start = $heading['line'];   // headings are in line order: the last one above wins
            }
        }

        return array_values(array_filter($headings, static fn(array $h): bool => $h['line'] >= $start));
    }

    /**
     * The lines below a heading in its column, up to the next heading, a gap (two empty lines
     * before the first value, one after) or $max lines.
     *
     * @param array{kind: string, line: int, col: int} $heading
     * @return list<string>
     */
    private function blockBelow(array $heading, int $max): array
    {
        $out  = [];
        $gap  = 0;
        $last = min(count($this->lines) - 1, $heading['line'] + 16);
        for ($line = $heading['line'] + 1; $line <= $last; $line++) {
            $segment = $this->segmentAt($line, $heading['col'], true);
            if ($segment === null) {
                $gap++;
                if ($gap > ($out === [] ? 2 : 1)) {
                    break;
                }
                continue;
            }
            if ($segment['kinds'] !== []) {
                break;
            }
            $out[] = $segment['text'];
            $gap   = 0;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * The first segment within three lines below a heading that starts in its column — for
     * currency and amount, whole segments only (never a cut: «CHF 1 234.50» must not yield
     * «234.50» under «Betrag»).
     *
     * @param array{kind: string, line: int, col: int} $heading
     */
    private function valueNear(array $heading): ?string
    {
        $last = min(count($this->lines) - 1, $heading['line'] + 3);
        for ($line = $heading['line'] + 1; $line <= $last; $line++) {
            $segment = $this->segmentAt($line, $heading['col'], false);
            if ($segment !== null) {
                return $segment['kinds'] === [] ? $segment['text'] : null;
            }
        }

        return null;
    }

    /**
     * The segment of $line that starts at $col (± TOLERANCE). With $cut, also the part of a
     * segment that ran across $col (a left column joined to this one by a single space) from
     * the word that starts there. A left column's own text is NOT cut where it runs into a
     * right column: a cut there could split a value (a reference spans word starts), and the
     * left column is the receipt, whose values only confirm the payment part's.
     *
     * @return array{col: int, text: string, kinds: list<array{kind: string, col: int}>}|null
     */
    private function segmentAt(int $line, int $col, bool $cut): ?array
    {
        foreach ($this->segments[$line] ?? [] as $segment) {
            if (abs($segment['col'] - $col) <= self::TOLERANCE) {
                return $segment;
            }
            if ($cut && $segment['col'] < $col && $segment['col'] + mb_strlen($segment['text']) > $col) {
                $tail = self::fromWordAt($segment, $col);
                if ($tail !== null) {
                    return ['col' => $col, 'text' => $tail, 'kinds' => self::headingsOf($tail, $col)];
                }
            }
        }

        return null;
    }

    /** @param array{col: int, text: string} $segment  the rest of the segment from the word starting at $col (± CUT_TOLERANCE) */
    private static function fromWordAt(array $segment, int $col): ?string
    {
        preg_match_all('/\S+/u', $segment['text'], $words, PREG_OFFSET_CAPTURE);
        foreach ($words[0] as [, $offset]) {
            $at = $segment['col'] + mb_strlen(substr($segment['text'], 0, $offset));
            if ($offset > 0 && abs($at - $col) <= self::CUT_TOLERANCE) {
                return substr($segment['text'], $offset);
            }
        }

        return null;
    }

    // ── text helpers ────────────────────────────────────────────────────

    /**
     * The segments of a line (words joined by single spaces), each with its character column
     * and — when it consists of labels only — the headings it carries.
     *
     * @return list<array{col: int, text: string, kinds: list<array{kind: string, col: int}>}>
     */
    private static function segmentsOf(string $line): array
    {
        $line = str_replace(["\t", "\u{00A0}"], ' ', $line);
        preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE);
        $segments = [];
        foreach ($matches[0] as [$text, $offset]) {
            $col        = mb_strlen(substr($line, 0, $offset));
            $segments[] = ['col' => $col, 'text' => $text, 'kinds' => self::headingsOf($text, $col)];
        }

        return $segments;
    }

    /**
     * The headings a segment consists of — «Währung Betrag» is two — or none when any word of
     * it is not part of a label.
     *
     * @return list<array{kind: string, col: int}>
     */
    private static function headingsOf(string $text, int $col): array
    {
        if (mb_strlen($text) > 60) {
            return [];
        }
        preg_match_all('/\S+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $words    = $matches[0];
        $lookup   = self::lookup();
        $headings = [];
        $i        = 0;
        while ($i < count($words)) {
            $matched = false;
            for ($n = min(self::MAX_LABEL_WORDS, count($words) - $i); $n >= 1; $n--) {
                $phrase = implode(' ', array_column(array_slice($words, $i, $n), 0));
                $kind   = $lookup[self::labelKey($phrase)] ?? null;
                if ($kind !== null) {
                    $headings[] = ['kind' => $kind, 'col' => $col + mb_strlen(substr($text, 0, $words[$i][1]))];
                    $i         += $n;
                    $matched    = true;
                    break;
                }
            }
            if (!$matched) {
                return [];
            }
        }

        return $headings;
    }

    /** @return array<string, string> */
    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = [];
            foreach (self::LABELS as $kind => $labels) {
                foreach ($labels as $label) {
                    self::$lookup[self::labelKey($label)] = $kind;
                }
            }
        }

        return self::$lookup;
    }

    private static function labelKey(string $text): string
    {
        $key = self::fold($text);
        $key = (string) preg_replace('~\s*/\s*~u', ' / ', $key);
        $key = (string) preg_replace('/\s+/u', ' ', $key);

        return rtrim(trim($key), ':');
    }

    private static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), self::FOLD);
    }

    /**
     * The IBAN of an account block (its first line, or the start of it when a reader joined
     * the address onto it) and the creditor lines after it. Null IBAN when none is valid.
     *
     * @param list<string> $block
     * @return array{0: ?string, 1: list<string>}
     */
    private static function ibanAndRest(array $block): array
    {
        $first = array_shift($block);
        if (preg_match('/^((?:CH|LI)\s?\d{2}(?:\s?[0-9A-Z]){17})(?=\s|$)\s*(.*)$/u', $first, $m)) {
            $iban = Iban::normalize($m[1]);
            $rest = trim($m[2]);
        } else {
            $iban = Iban::normalize($first);
            $rest = '';
        }
        if (!Iban::isWellFormed($iban) || !Iban::hasValidCheckDigits($iban)) {
            return [null, []];
        }

        return [$iban, array_values(array_filter(array_merge($rest !== '' ? [$rest] : [], array_map('trim', $block)), static fn(string $l): bool => $l !== ''))];
    }

    /** A QR reference (27 digits, check digit) or an RF creditor reference, normalised; null when neither holds. */
    private static function reference(string $text): ?string
    {
        $qr = QrReference::normalize($text);
        if (QrReference::isValid($qr)) {
            return $qr;
        }
        $scor = ScorReference::normalize($text);

        return ScorReference::isValid($scor) ? $scor : null;
    }

    /**
     * «1 234.50», «1'234.50», «1234.50» → "1234.50" within the QR-bill range (0.01 – 999 999 999.99);
     * text with a digit that is no such amount is a warning.
     *
     * @param list<string> $warnings
     */
    private static function amount(string $text, array &$warnings): ?string
    {
        $text = trim(str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text));
        if (preg_match("/^(\d{1,3}(?:[ '\u{2019}]\d{3})+|\d+)[.,](\d{2})$/u", $text, $m)) {
            $units = ltrim((string) preg_replace("/[ '\u{2019}]/u", '', $m[1]), '0');
            $units = $units === '' ? '0' : $units;
            if ($units === '0' && $m[2] === '00') {
                return null;   // 0.00: a slip that only informs — nothing to pay
            }
            if (strlen($units) <= 9) {
                return $units . '.' . $m[2];
            }
        }
        if (preg_match('/\d/', $text)) {
            $warnings[] = "Betrag «{$text}» nicht lesbar";
        }

        return null;
    }

    /**
     * Postcode and town from the last address line: «8000 Zürich», «CH-8000 Zürich».
     *
     * @param list<string> $lines name first
     * @return array{0: ?string, 1: ?string}
     */
    private static function postalCodeAndTown(array $lines): array
    {
        // The last line — also when a reader joined the whole address onto one line («… Beispielgasse 7 8000 Zürich»).
        $last = $lines === [] ? '' : $lines[count($lines) - 1];
        if (preg_match('/(?:^|\s)(?:[A-Z]{1,2}-)?(\d{4,5})\s+(\D+)$/u', $last, $m)) {
            return [$m[1], trim($m[2])];
        }

        return [null, null];
    }

    /** @param array{line: int, col: int} $heading  sort key: the right-hand column first, then the later line */
    private static function key(array $heading): int
    {
        return $heading['col'] * 100_000 + $heading['line'];
    }

    /**
     * @template T of array{key: int}
     * @param list<T> $candidates
     * @return list<T> the payment part's first
     */
    private static function byPreference(array $candidates): array
    {
        usort($candidates, static fn(array $a, array $b): int => $b['key'] <=> $a['key']);

        return $candidates;
    }

    /**
     * The preferred value of the readings (receipt / payment part) — a warning when they differ.
     *
     * @param list<array{value: string, key: int}> $candidates
     * @param list<string> $warnings
     */
    private static function preferred(array $candidates, string $label, array &$warnings, ?\Closure $format = null): ?string
    {
        if ($candidates === []) {
            return null;
        }
        $values = array_values(array_unique(array_column($candidates, 'value')));
        if (count($values) > 1) {
            $warnings[] = "Verschiedene {$label} auf Empfangsschein und Zahlteil: " . implode(' / ', $format === null ? $values : array_map($format, $values));
        }

        return self::byPreference($candidates)[0]['value'];
    }

    /** Ranks readings: validated fields count, warnings cost. */
    private static function score(RecognizedPayment $p): int
    {
        return ($p->iban !== null ? 8 : 0) + ($p->amount !== null ? 4 : 0) + ($p->currency !== null ? 2 : 0)
            + ($p->referenceType !== null ? 2 : 0) + ($p->creditorName !== null ? 1 : 0) + $p->occurrences
            - 3 * count($p->warnings);
    }
}
