<?php

namespace Z77\Module\Creditor\Recognition;

use Z77\Shared\Money\Money;

/**
 * The LABEL a creditor prints before a value — learned per creditor (owner 2026-11-15/16: «wenn die
 * KI den Kreditor erkennt, kann man doch bestimmt Felder nehmen …, die das PHP-Zeilenlesen ebenfalls
 * erstellt»). Pure: text and value in, label out ({@see learn()}); text and label in, value out
 * ({@see read()}). `CreditorLearningService` stores the labels per creditor and decides when to
 * trust them; {@see HeaderLabels} stays the reader of the GENERAL labels every invoice may carry.
 *
 * **Learning** a value (the AI's answer or the owner's saved correction): every place the value is
 * printed in the text — an invoice number as such, a date in any of {@see scanDates()}' forms, an
 * amount with its currency sign or code — gives a candidate label: the words right before it on the
 * same line (after the last token with a digit, `Invoice number INV-1 Date of issue` → «Date of
 * issue»), or the column head right above it (a header table). A candidate counts only when reading
 * the text at that label gives exactly the value again; one whose words name the field («Amount
 * due», «Rechnungsdatum») wins, else the last amount / the first other value.
 *
 * **Reading** a learned label: at every place the label is printed, the value beside it (or right
 * below it) — ONE distinct value, else nothing (never a guess). An amount must be in the learned
 * currency: a different sign or code beside it = nothing; no sign beside it = the learned currency
 * only while the text names it somewhere. Range 0.01 … 999 999 999.99.
 */
final class LearnedLabels
{
    public const NUMBER = 'invoice_number';
    public const DATE   = 'invoice_date';
    public const DUE    = 'due_date';
    public const AMOUNT = 'amount';
    public const FIELDS = [self::NUMBER, self::DATE, self::DUE, self::AMOUNT];
    /** What a creditor's learned labels must deliver before the AI is left out (the due date is optional). */
    public const REQUIRED = [self::NUMBER, self::DATE, self::AMOUNT];
    /**
     * The COLUMN HEADER above the line text (owner 2026-11-17: «Description» in «Description | Qty |
     * Unit price | Tax | Amount») — stored as a label row of its own field, never in {@see FIELDS}
     * nor {@see REQUIRED}: it does not count for the trust phase and never keeps the AI running.
     * {@see learnLineHeader()} / {@see readLineText()}.
     */
    public const LINE_TEXT = 'line_text';

    /** How many blank lines may stand between a column header and its first row. */
    private const ROW_DISTANCE = 3;
    /** How many continuation lines a line text takes at most. */
    private const CONTINUATION_MAX = 3;
    /** The longest line text read (the invoice line's text column). */
    private const LINE_TEXT_LENGTH = 255;
    /** Between the first row and its continuation lines. */
    public const LINE_TEXT_JOIN = ' · ';

    /** The longest label stored. */
    public const LABEL_LENGTH = 60;

    /** A numeric date «01/10/2026»: day first (the default) or month first (an American invoice). */
    public const ORDER_DMY = 'dmy';
    public const ORDER_MDY = 'mdy';

    private const TOLERANCE = 3;

    /** Abbreviated and English month names beside {@see HeaderLabels::MONTHS}. */
    private const MONTH_MORE = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'mär' => 3, 'mrz' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9,
        'oct' => 10, 'okt' => 10, 'nov' => 11, 'dec' => 12, 'dez' => 12, 'déc' => 12, 'janv' => 1, 'févr' => 2, 'fevr' => 2, 'avr' => 4,
        'juil' => 7, 'gen' => 1, 'mag' => 5, 'giu' => 6, 'lug' => 7, 'ago' => 8, 'set' => 9, 'ott' => 10, 'dic' => 12,
        'april' => 4, 'august' => 8, 'september' => 9, 'november' => 11,
    ];

    /** Currency signs → the code. */
    private const SIGNS = ['US$' => 'USD', '$' => 'USD', '€' => 'EUR', '£' => 'GBP', 'SFr.' => 'CHF', 'Fr.' => 'CHF'];

    /** Words that name the field in a label — a candidate carrying one wins. */
    private const KEYWORDS = [
        self::AMOUNT => ['total', 'due', 'betrag', 'summe', 'montant', 'importo', 'amount', 'zahlen', 'payable', 'totale', 'zahlbar', 'balance', 'saldo'],
        self::NUMBER => ['nr', 'no', 'number', 'nummer', 'numéro', 'numero', 'n°', '#', 'invoice', 'rechnung', 'facture', 'fattura'],
        self::DATE   => ['date', 'datum', 'data', 'issue', 'issued', 'ausgestellt', 'rechnungsdatum'],
        self::DUE    => ['due', 'fällig', 'fälligkeit', 'zahlbar', 'échéance', 'scadenza', 'payable'],
    ];

    /**
     * The label printed before $value in $text — the best candidate that reads $value back.
     *
     * @return array{label: string, order: ?string}|null null = not printed, or no label reads it back unambiguously
     */
    public static function learn(string $text, string $field, string|\DateTimeImmutable|Money $value): ?array
    {
        $lines      = self::lines($text);
        $currency   = $value instanceof Money ? $value->currency : null;
        $candidates = [];
        foreach ($lines as $i => $line) {
            foreach (self::occurrences($line, $field, $value) as [$offset, $order]) {
                $before = substr($line, 0, $offset);
                $label  = self::labelBefore($before) ?? self::labelAbove($lines[$i - 1] ?? '', mb_strlen($before));
                if ($label === null) {
                    continue;
                }
                $read = self::read($text, $field, $label, $currency, $order);
                if ($read === null || !self::same($read, $value)) {
                    continue;
                }
                $named = false;
                foreach (self::KEYWORDS[$field] as $word) {
                    if (preg_match('/(?<![\p{L}])' . preg_quote($word, '/') . '(?![\p{L}])/iu', $label)) {
                        $named = true;
                        break;
                    }
                }
                $candidates[] = ['label' => $label, 'order' => $order, 'named' => $named, 'at' => count($candidates)];
            }
        }
        if ($candidates === []) {
            return null;
        }
        // a label naming the field first; then the LAST amount (the total at the bottom), the FIRST other value
        usort($candidates, static fn(array $a, array $b): int => [$b['named'], $field === self::AMOUNT ? $b['at'] : -$b['at']] <=> [$a['named'], $field === self::AMOUNT ? $a['at'] : -$a['at']]);

        return ['label' => $candidates[0]['label'], 'order' => $candidates[0]['order']];
    }

    /**
     * The value printed at $label — beside it, else right below it; exactly ONE distinct value,
     * else null. $currency: the learned currency of an amount; $order: the learned order of a
     * numeric date.
     */
    public static function read(string $text, string $field, string $label, ?string $currency = null, ?string $order = null): string|\DateTimeImmutable|Money|null
    {
        $label = trim($label);
        if ($label === '' || !in_array($field, self::FIELDS, true)) {
            return null;
        }
        $lines   = self::lines($text);
        $pattern = '/(?<![\p{L}\p{N}\-])' . HeaderLabels::labelPattern($label) . '(?![\p{L}])/iu';
        $found   = [];
        foreach ($lines as $i => $line) {
            if (!preg_match_all($pattern, $line, $hits, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($hits[0] as [$hit, $offset]) {
                $after = HeaderLabels::valueAfter(substr($line, $offset + strlen($hit)));
                $below = HeaderLabels::valueBelow($lines[$i + 1] ?? '', mb_strlen(substr($line, 0, $offset)));
                foreach ([$after, $below] as $candidate) {
                    $parsed = self::parse($field, $candidate, $currency, $order, $text);
                    if ($parsed === false) {
                        return null;   // an amount in another currency at the label — implausible
                    }
                    if ($parsed !== null) {
                        $found[$parsed] = true;
                        break;
                    }
                }
            }
        }
        if (count($found) !== 1) {
            return null;
        }
        $value = (string) array_key_first($found);

        return match ($field) {
            self::NUMBER => $value,
            self::AMOUNT => Money::fromDecimal(...explode('|', $value)),
            default      => new \DateTimeImmutable($value),
        };
    }

    /**
     * The line text printed under the column header $header (owner 2026-11-17): the first row
     * below it (blank lines skipped) that carries an AMOUNT to the right of the header's column —
     * its cell in that column, cut before an amount inside it — and the continuation lines right
     * below (no blank line between, a cell in the same column, no amount; a period such as «Nov
     * 14–Dec 14, 2026») appended with {@see LINE_TEXT_JOIN}. A continuation line never becomes a
     * row of its own. The header counts only as a whole cell; its first place with such a row
     * wins. Null = no such row.
     */
    public static function readLineText(string $text, string $header): ?string
    {
        $header = self::normalized($header);
        if ($header === '') {
            return null;
        }
        $lines = self::lines($text);
        foreach ($lines as $i => $line) {
            foreach (self::cells($line) as $cell) {
                if (self::normalized($cell['text']) !== $header) {
                    continue;
                }
                $found = self::rowBelow($lines, $i, $cell['col']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * The column header above the line text the owner KEPT (owner 2026-11-17): its first part
     * (before {@see LINE_TEXT_JOIN}) printed as a cell of a row with an amount to its right, the
     * header the cell above it in the same column (blank lines skipped) — kept only when
     * {@see readLineText()} at that header gives the same first part back. Null = the text is not
     * printed so (a text the owner wrote himself), or no header reads it back.
     */
    public static function learnLineHeader(string $text, string $kept): ?string
    {
        $want = self::normalized(explode(trim(self::LINE_TEXT_JOIN), $kept)[0]);
        if (preg_match_all('/\p{L}/u', $want) < 2) {
            return null;
        }
        $lines = self::lines($text);
        foreach ($lines as $j => $line) {
            foreach (self::cells($line) as $cell) {
                if (self::normalized(self::cutBeforeAmount($line, $cell)) !== $want || !self::amountRightOf($line, $cell['offset'])) {
                    continue;
                }
                $i = $j - 1;
                for ($skip = 0; $i >= 0 && trim($lines[$i]) === '' && $skip < self::ROW_DISTANCE; $skip++) {
                    $i--;
                }
                $above  = $i >= 0 ? self::cellAt($lines[$i], $cell['col']) : null;
                $header = $above !== null ? self::label($above['text']) : null;
                if ($header === null) {
                    continue;
                }
                $read = self::readLineText($text, $header);
                if ($read !== null && self::normalized(explode(trim(self::LINE_TEXT_JOIN), $read)[0]) === $want) {
                    return $header;
                }
            }
        }

        return null;
    }

    /** A month's number by its name or abbreviation in DE / FR / IT / EN; null = no month. */
    public static function month(string $word): ?int
    {
        $word = rtrim(mb_strtolower($word), '.');

        return HeaderLabels::MONTHS[$word] ?? self::MONTH_MORE[$word] ?? null;
    }

    /**
     * The dates printed in $line: byte offset, length, Y-m-d and — for a numeric date that reads as
     * a different day month-first — the order. A numeric date gives one entry per valid order.
     *
     * @return list<array{0: int, 1: int, 2: string, 3: ?string}>
     */
    public static function scanDates(string $line): array
    {
        $out = [];
        if (preg_match_all('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/u', $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                self::addDate($out, $hit[0], (int) $hit[1][0], (int) $hit[2][0], (int) $hit[3][0], null);
            }
        }
        if (preg_match_all('#(?<![\d./])(\d{1,2})([./\-])(\d{1,2})\2(\d{4}|\d{2})(?![\d])#u', $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $y = strlen($hit[4][0]) === 2 ? 2000 + (int) $hit[4][0] : (int) $hit[4][0];
                self::addDate($out, $hit[0], $y, (int) $hit[3][0], (int) $hit[1][0], self::ORDER_DMY);
                self::addDate($out, $hit[0], $y, (int) $hit[1][0], (int) $hit[3][0], self::ORDER_MDY);
            }
        }
        if (preg_match_all('/(?<!\d)(\d{1,2})(?:\.|er|st|nd|rd|th)?\s+(\p{L}{3,})\.?,?\s+(\d{4})(?!\d)/u', $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $month = self::month($hit[2][0]);
                if ($month !== null) {
                    self::addDate($out, $hit[0], (int) $hit[3][0], $month, (int) $hit[1][0], null);
                }
            }
        }
        if (preg_match_all('/(?<![\p{L}])(\p{L}{3,})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})(?!\d)/u', $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $month = self::month($hit[1][0]);
                if ($month !== null) {
                    self::addDate($out, $hit[0], (int) $hit[3][0], $month, (int) $hit[2][0], null);
                }
            }
        }

        return $out;
    }

    /**
     * The amounts printed in $line: byte offset, length, the decimal («1234.50») and the currency
     * a sign or code beside it names (null = none). Thousands separators ' ’ . , — never the
     * decimal one; a plain space never (a quantity before a price).
     *
     * @return list<array{0: int, 1: int, 2: string, 3: ?string}>
     */
    public static function scanAmounts(string $line): array
    {
        $sign = '(CHF|EUR|USD|GBP|SFr\.|Fr\.|US\$|€|\$|£)';
        if (!preg_match_all('/(?<![\w.,\'’])(?:' . $sign . '\s?)?(-)?(\d{1,3}(?:[\'’.,]\d{3})+|\d+)([.,])(\d{2})(?![\d])(?:\s?(CHF|EUR|USD|GBP|€|\$|£)(?![A-Za-z]))?/u', $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        foreach ($m as $hit) {
            $whole = $hit[3][0];
            if (($hit[2][0] ?? '') === '-' || str_contains($whole, $hit[4][0])) {
                continue;   // a credit, or the decimal separator used as a thousands one
            }
            $digits  = ltrim((string) preg_replace('/\D/', '', $whole), '0');
            $decimal = ($digits === '' ? '0' : $digits) . '.' . $hit[5][0];
            $code    = ($hit[1][0] ?? '') !== '' ? $hit[1][0] : (($hit[6][0] ?? '') !== '' ? $hit[6][0] : null);
            $out[]   = [(int) $hit[0][1], strlen($hit[0][0]), $decimal, $code !== null ? (self::SIGNS[$code] ?? $code) : null];
        }

        return $out;
    }

    /** Whether $text names $currency at all (its code, or its sign). */
    public static function mentionsCurrency(string $text, string $currency): bool
    {
        if (preg_match('/(?<![A-Za-z])' . preg_quote($currency, '/') . '(?![A-Za-z])/', $text)) {
            return true;
        }
        foreach (self::SIGNS as $sign => $code) {
            if ($code === $currency && str_contains($text, $sign)) {
                return true;
            }
        }

        return false;
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @return list<string> the text's lines (pages run on) */
    private static function lines(string $text): array
    {
        return explode("\n", str_replace(["\r\n", "\r", "\f", "\t", "\u{00A0}"], ["\n", "\n", "\n", ' ', ' '], $text));
    }

    /**
     * Where $value is printed in $line: byte offset and — for a numeric date — the order it was read in.
     *
     * @return list<array{0: int, 1: ?string}>
     */
    private static function occurrences(string $line, string $field, string|\DateTimeImmutable|Money $value): array
    {
        $out = [];
        if ($field === self::NUMBER && is_string($value) && trim($value) !== '') {
            if (preg_match_all('/(?<![A-Za-z0-9\-\/._])' . preg_quote(trim($value), '/') . '(?![A-Za-z0-9\-\/_]|\.[A-Za-z0-9])/iu', $line, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [, $offset]) {
                    $out[] = [(int) $offset, null];
                }
            }
        } elseif (($field === self::DATE || $field === self::DUE) && $value instanceof \DateTimeImmutable) {
            foreach (self::scanDates($line) as [$offset, , $ymd, $order]) {
                if ($ymd === $value->format('Y-m-d')) {
                    $out[] = [$offset, $order];
                }
            }
        } elseif ($field === self::AMOUNT && $value instanceof Money) {
            foreach (self::scanAmounts($line) as [$offset, , $decimal, $code]) {
                if ($decimal === $value->toDecimal() && ($code === null || $code === $value->currency)) {
                    $out[] = [$offset, null];
                }
            }
        }

        return $out;
    }

    /**
     * The cells of a layout line — runs of words separated by single spaces (two or more spaces
     * separate columns): text, byte offset, column (characters).
     *
     * @return list<array{text: string, offset: int, col: int}>
     */
    private static function cells(string $line): array
    {
        preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($matches[0] as [$text, $offset]) {
            $out[] = ['text' => $text, 'offset' => (int) $offset, 'col' => mb_strlen(substr($line, 0, (int) $offset))];
        }

        return $out;
    }

    /** @return array{text: string, offset: int, col: int}|null the cell starting in column $col (± TOLERANCE) */
    private static function cellAt(string $line, int $col): ?array
    {
        foreach (self::cells($line) as $cell) {
            if (abs($cell['col'] - $col) <= self::TOLERANCE) {
                return $cell;
            }
        }

        return null;
    }

    /**
     * The line text of the row below the header in line $i, column $col ({@see readLineText()}).
     *
     * @param list<string> $lines
     */
    private static function rowBelow(array $lines, int $i, int $col): ?string
    {
        $j = $i + 1;
        for ($skip = 0; isset($lines[$j]) && trim($lines[$j]) === '' && $skip < self::ROW_DISTANCE; $skip++) {
            $j++;
        }
        $cell = isset($lines[$j]) ? self::cellAt($lines[$j], $col) : null;
        if ($cell === null || !self::amountRightOf($lines[$j], $cell['offset'])) {
            return null;
        }
        $first = self::cutBeforeAmount($lines[$j], $cell);
        if (preg_match_all('/\p{L}/u', $first) < 2) {
            return null;   // a number or a code in that column, not a text
        }
        $parts = [$first];
        for ($k = $j + 1; isset($lines[$k]) && count($parts) <= self::CONTINUATION_MAX; $k++) {
            $next = self::cellAt($lines[$k], $col);
            if (trim($lines[$k]) === '' || $next === null || self::amountRightOf($lines[$k], -1)) {
                break;   // a blank line, another column, or the next row / a total
            }
            $parts[] = $next['text'];
        }

        return mb_substr(implode(self::LINE_TEXT_JOIN, $parts), 0, self::LINE_TEXT_LENGTH);
    }

    /** Whether $line prints an amount starting after byte $offset — a date («14.11.2026») is no amount. */
    private static function amountRightOf(string $line, int $offset): bool
    {
        foreach (self::realAmounts($line) as $at) {
            if ($at > $offset) {
                return true;
            }
        }

        return false;
    }

    /** @param array{text: string, offset: int, col: int} $cell its text up to an amount inside it («Claude Ultra 1 200.00» → «Claude Ultra 1») */
    private static function cutBeforeAmount(string $line, array $cell): string
    {
        $end = $cell['offset'] + strlen($cell['text']);
        foreach (self::realAmounts($line) as $at) {
            if ($at > $cell['offset'] && $at < $end) {
                return trim(substr($line, $cell['offset'], $at - $cell['offset']));
            }
        }

        return $cell['text'];
    }

    /** @return list<int> byte offsets of the amounts in $line that are not part of a date */
    private static function realAmounts(string $line): array
    {
        $dates = self::scanDates($line);
        $out   = [];
        foreach (self::scanAmounts($line) as [$offset]) {
            foreach ($dates as [$from, $length]) {
                if ($offset >= $from && $offset < $from + $length) {
                    continue 2;
                }
            }
            $out[] = $offset;
        }

        return $out;
    }

    /** Lower case, whitespace collapsed — how a header and a kept text are compared. */
    private static function normalized(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /** The words right before a value on its line: the last column segment, after its last token with a digit. */
    private static function labelBefore(string $before): ?string
    {
        $segments = preg_split('/\s{2,}/u', trim($before)) ?: [];
        $segment  = (string) end($segments);
        $tokens   = preg_split('/\s+/u', $segment) ?: [];
        $words    = [];
        for ($t = count($tokens) - 1; $t >= 0; $t--) {
            if (preg_match('/\d/', $tokens[$t])) {
                break;
            }
            array_unshift($words, $tokens[$t]);
        }

        return self::label(implode(' ', $words));
    }

    /** The column head right above a value (same column ± TOLERANCE) — a header table. */
    private static function labelAbove(string $line, int $col): ?string
    {
        preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$text, $offset]) {
            if (abs(mb_strlen(substr($line, 0, $offset)) - $col) <= self::TOLERANCE) {
                return self::label($text);
            }
        }

        return null;
    }

    /** A usable label: «:» and spaces trimmed, 2+ letters, no digit, at most LABEL_LENGTH characters. */
    private static function label(string $words): ?string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $words), " :=\t");
        if ($label === '' || mb_strlen($label) > self::LABEL_LENGTH || preg_match('/\d/', $label) || preg_match_all('/\p{L}/u', $label) < 2) {
            return null;
        }

        return $label;
    }

    /**
     * One value at a label, as the key {@see read()} collects (number, Y-m-d, «decimal|currency»);
     * null = nothing there; false = an amount in ANOTHER currency (implausible).
     */
    private static function parse(string $field, string $value, ?string $currency, ?string $order, string $text): string|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($field === self::NUMBER) {
            return HeaderLabels::number($value);
        }
        if ($field === self::AMOUNT) {
            $amount = self::scanAmounts($value)[0] ?? null;
            if ($amount === null || $amount[0] !== 0 || $currency === null) {
                return null;
            }
            if ($amount[3] !== null && $amount[3] !== $currency) {
                return false;
            }
            if ($amount[3] === null && !self::mentionsCurrency($text, $currency)) {
                return null;
            }
            if ((float) $amount[2] < 0.01 || (float) $amount[2] > 999999999.99) {
                return null;
            }

            return $amount[2] . '|' . $currency;
        }
        foreach (self::scanDates($value) as [$offset, , $ymd, $dateOrder]) {
            if ($offset === 0 && ($dateOrder === null || $dateOrder === ($order ?? self::ORDER_DMY))) {
                return $ymd;
            }
        }

        return null;
    }

    private static function same(string|\DateTimeImmutable|Money $read, string|\DateTimeImmutable|Money $value): bool
    {
        return match (true) {
            $read instanceof Money && $value instanceof Money                           => $read->toDecimal() === $value->toDecimal() && $read->currency === $value->currency,
            $read instanceof \DateTimeImmutable && $value instanceof \DateTimeImmutable => $read->format('Y-m-d') === $value->format('Y-m-d'),
            is_string($read) && is_string($value)                                       => strcasecmp(trim($read), trim($value)) === 0,
            default                                                                     => false,
        };
    }

    /** @param list<array{0: int, 1: int, 2: string, 3: ?string}> $out */
    private static function addDate(array &$out, array $hit, int $y, int $m, int $d, ?string $order): void
    {
        if ($y >= 2000 && $y <= 2099 && checkdate($m, $d, $y)) {
            $out[] = [(int) $hit[1], strlen($hit[0]), sprintf('%04d-%02d-%02d', $y, $m, $d), $order];
        }
    }
}
