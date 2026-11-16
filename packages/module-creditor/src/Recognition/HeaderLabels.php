<?php

namespace Z77\Module\Creditor\Recognition;

/**
 * Invoice number, invoice date, due date and the invoice total read off the LABELS of the
 * invoice's header (owner 2026-11-15: no AI, no cost — the text layer step). Pure: header text
 * in (the text above the payment part, {@see SwissQrBillText::textBefore()}), values out.
 *
 * A label is found case-insensitively at a word start (never inside a word: «Rechnungsdatum»
 * holds no «Datum», «Kundennummer» is no label at all); its value is what follows on the same
 * line (after «:» or spaces), or — for a label with nothing after it — the text right below it in
 * the same column (a table whose labels head their values). Labels, DE / FR / IT / EN:
 *
 *   - invoice number: Rechnungsnummer, Rechnungs-Nr., Rechnung Nr., N° de facture, Facture n°,
 *     Numero fattura, Invoice number, Invoice no. — a token with a digit, ≤ 40 characters;
 *   - invoice date: Rechnungsdatum, Date de facture, Data fattura, Invoice date — and the bare
 *     «Datum» / «Date» / «Data» ONLY when it occurs exactly once (else ambiguous: nothing);
 *   - due date: Zahlbar bis, Fällig am, Fälligkeit, Payable jusqu'au, Échéance, Scadenza, Due date;
 *     «zahlbar innert N Tagen» (and its FR / IT / EN forms) gives {@see $dueDays};
 *   - total: Rechnungsbetrag, Total, Gesamtbetrag, Montant total, Totale, Invoice total — only to
 *     CHECK the QR amount ({@see $totals}); never filled from here.
 *
 * Dates: `dd.mm.yyyy`, `dd.mm.yy`, `dd/mm/yyyy` and «31. Januar 2026» / «31 janvier 2026» /
 * «31 gennaio 2026» / «31 January 2026». A label found twice with DIFFERENT values decides
 * nothing (null) — never a guess. Which fields are empty, what S1 says and the due-before-invoice
 * check are {@see InvoiceFacts}' business.
 */
final class HeaderLabels
{
    private const NUMBER   = 'number';
    private const DATE     = 'date';
    private const DUE      = 'due';
    private const GENERIC  = 'generic';
    private const TOTAL    = 'total';

    /** Most specific first: a longer label is masked before a shorter one is looked for. */
    private const LABELS = [
        self::NUMBER  => ['Rechnungsnummer', 'Rechnungs-Nr.', 'Rechnungs-Nr', 'Rechnungsnr.', 'Rechnung Nr.', 'Rechnung Nr', 'Numéro de facture', 'N° de facture', 'No de facture', 'Facture n°', 'Facture no', 'Numero della fattura', 'Numero fattura', 'Fattura n.', 'Invoice number', 'Invoice no.', 'Invoice no', 'Invoice #'],
        self::DATE    => ['Rechnungsdatum', 'Date de la facture', 'Date de facture', 'Data della fattura', 'Data fattura', 'Invoice date'],
        self::DUE     => ['Fälligkeitsdatum', 'Zahlbar bis', 'Fällig am', 'Fälligkeit', "Date d'échéance", "Payable jusqu'au", 'Échéance', 'Echéance', 'Data di scadenza', 'Scadenza', 'Due date'],
        self::TOTAL   => ['Rechnungsbetrag', 'Gesamtbetrag', 'Totalbetrag', 'Montant total', 'Invoice total', 'Total amount', 'Totale', 'Total'],
        self::GENERIC => ['Datum', 'Date', 'Data'],
    ];

    /** «zahlbar innert 30 Tagen» and its sisters → the number of days. */
    private const TERMS = [
        '/zahlbar\s+(?:innert|innerhalb(?:\s+von)?|in)\s+(\d{1,3})\s+tag(?:en)?/iu',
        '/payable\s+(?:dans\s+les|sous|à)\s+(\d{1,3})\s+jours/iu',
        '/(?:pagabile\s+)?entro\s+(\d{1,3})\s+giorni/iu',
        '/(?:payable|due)\s+within\s+(\d{1,3})\s+days/iu',
    ];

    public const MONTHS = [
        'januar' => 1, 'jänner' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
        'janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'juin' => 6, 'juillet' => 7, 'août' => 8, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12,
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6, 'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'dicembre' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'may' => 5, 'june' => 6, 'july' => 7, 'october' => 10, 'december' => 12,
    ];

    private const TOLERANCE = 3;

    /** @param list<string> $totals decimals («25.55») next to a total label */
    private function __construct(
        public readonly ?string $invoiceNumber,
        public readonly ?\DateTimeImmutable $invoiceDate,
        public readonly ?\DateTimeImmutable $dueDate,
        public readonly ?int $dueDays,
        public readonly array $totals,
    ) {}

    public static function read(string $header): self
    {
        $lines = explode("\n", str_replace(["\r\n", "\r", "\f", "\t", "\u{00A0}"], ["\n", "\n", "\n", ' ', ' '], $header));
        $found = [self::NUMBER => [], self::DATE => [], self::DUE => [], self::TOTAL => [], self::GENERIC => []];
        $masked = $lines;
        foreach (self::LABELS as $kind => $labels) {
            foreach ($labels as $label) {
                $pattern = '/(?<![\p{L}\p{N}\-])' . self::labelPattern($label) . '(?![\p{L}])/iu';
                foreach ($masked as $i => $line) {
                    if (!preg_match_all($pattern, $line, $hits, PREG_OFFSET_CAPTURE)) {
                        continue;
                    }
                    foreach ($hits[0] as [$text, $offset]) {
                        // the value beside the label, else the one below it (a header table)
                        $found[$kind][] = [
                            self::valueAfter(substr($line, $offset + strlen($text))),
                            self::valueBelow($lines[$i + 1] ?? '', mb_strlen(substr($line, 0, $offset))),
                        ];
                        // mask the label, so «Date» inside «Invoice date» is not found again
                        $masked[$i] = substr_replace($masked[$i], str_repeat('#', strlen($text)), $offset, strlen($text));
                        $line       = $masked[$i];
                    }
                }
            }
        }

        $first  = static fn(callable $parse): \Closure => static fn(array $pair): ?string => $parse($pair[0]) ?? $parse($pair[1]);
        $number = self::single(array_map($first(self::number(...)), $found[self::NUMBER]));
        $date   = self::single(array_map($first(self::date(...)), $found[self::DATE]));
        if ($date === null && count($found[self::GENERIC]) === 1) {
            $date = $first(self::date(...))($found[self::GENERIC][0]);
        }
        $due    = self::single(array_map($first(self::date(...)), $found[self::DUE]));
        $days   = null;
        foreach (self::TERMS as $term) {
            if (preg_match($term, implode("\n", $lines), $m)) {
                $days = (int) $m[1];
                break;
            }
        }
        $totals = array_values(array_unique(array_filter(array_map($first(self::amount(...)), $found[self::TOTAL]))));

        return new self($number, $date !== null ? new \DateTimeImmutable($date) : null, $due !== null ? new \DateTimeImmutable($due) : null, $days, $totals);
    }

    public function hasAnyValue(): bool
    {
        return $this->invoiceNumber !== null || $this->invoiceDate !== null || $this->dueDate !== null || $this->dueDays !== null;
    }

    /** «25.55», «1'234.50», «1 234.50», «CHF 25.55» → «25.55»; null = no amount. */
    public static function amount(string $value): ?string
    {
        if (!preg_match("/^(?:CHF|EUR|Fr\.?)?\s*(\d{1,3}(?:['’ ]\d{3})*|\d+)[.,](\d{2})(?!\d)/u", trim($value), $m)) {
            return null;
        }

        return str_replace(["'", '’', ' '], '', $m[1]) . '.' . $m[2];
    }

    /** A date at the start of $value («31.01.2026», «31.01.26», «31/01/2026», «31. Januar 2026») as Y-m-d, or null. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^(\d{1,2})[./](\d{1,2})[./](\d{4}|\d{2})(?!\d)#', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = strlen($m[3]) === 2 ? 2000 + $y : $y;
        } elseif (preg_match('/^(\d{1,2})(?:\.|er)?\s+(\p{L}+)\.?\s+(\d{4})(?!\d)/u', $value, $m) && isset(self::MONTHS[mb_strtolower($m[2])])) {
            [$d, $mo, $y] = [(int) $m[1], self::MONTHS[mb_strtolower($m[2])], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) && $y >= 2000 && $y <= 2099 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** An invoice number: the first token with a digit, letters / digits / - / . / _, ≤ 40 characters. */
    public static function number(string $value): ?string
    {
        if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\/._]{0,39})(?![A-Za-z0-9\-\/._])/u', trim($value), $m) || !preg_match('/\d/', $m[1])) {
            return null;
        }

        return rtrim($m[1], '.');
    }

    /** What follows a label on its line: after «:» / «#» / spaces, up to a gap of two spaces (the next column). */
    public static function valueAfter(string $after): string
    {
        $after = (string) preg_replace('/^\s*[:#]?\s*/u', '', $after);

        return trim((string) preg_split('/\s{2,}/u', $after)[0]);
    }

    /** The segment right below a label (same column ± TOLERANCE) — a header table. */
    public static function valueBelow(string $line, int $col): string
    {
        preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$text, $offset]) {
            if (abs(mb_strlen(substr($line, 0, $offset)) - $col) <= self::TOLERANCE) {
                return $text;
            }
        }

        return '';
    }

    /** The one value all hits agree on; none, or two different ones = null. */
    private static function single(array $values): ?string
    {
        $values = array_values(array_unique(array_filter($values, static fn($v) => $v !== null)));

        return count($values) === 1 ? $values[0] : null;
    }

    /** A label as a pattern: spaces flexible, «°» also «o», «'» also «’». */
    public static function labelPattern(string $label): string
    {
        return str_replace([' ', '°', "'"], ['\s+', '[°o]', "['’]"], preg_quote($label, '/'));
    }
}
