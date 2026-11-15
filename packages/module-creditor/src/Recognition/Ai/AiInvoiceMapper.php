<?php

namespace Z77\Module\Creditor\Recognition\Ai;

use Z77\Module\Creditor\Recognition\RecognizedPayment;
use Z77\Module\Mandator\Services\Uid;
use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Iban;
use Z77\Shared\Payment\QrReference;
use Z77\Shared\Payment\ScorReference;

/**
 * The AI's answer → {@see AiInvoiceData}, re-checking EVERY value (Rule 7 in spirit: the server
 * trusts nothing it is sent — the schema only shapes the answer, the PDF may carry injected
 * text). A value that fails its check is dropped and named in a German warning; the rest is kept.
 *
 * Checks: IBAN through the kernel's `Iban` (shape + MOD-97), the reference through `QrReference`
 * / `ScorReference` and its fit with the IBAN (as the text layer warns), amounts as `Money`
 * (digits, two decimals, the QR-bill range, a currency), dates as real calendar dates in
 * 2000–2099 with the due date not before the invoice date, the UID through module-mandator's
 * `Uid` (check digit), free texts without control characters and within a length — too long is
 * dropped, never cut. Pure: no I/O.
 */
final class AiInvoiceMapper
{
    /** The answer's fields — the JSON schema's properties ({@see schema()}). */
    public const FIELDS = [
        'creditor_name', 'creditor_address_lines', 'creditor_postal_code', 'creditor_town', 'creditor_country',
        'creditor_uid', 'iban', 'currency', 'gross_total', 'vat_lines', 'invoice_number', 'invoice_date',
        'due_date', 'reference_type', 'reference', 'line_text', 'expense_category',
    ];

    /** The API's limit for nullable / anyOf parameters in a structured-output schema (HTTP 400 above). */
    public const MAX_UNION_PARAMETERS = 16;

    private const MAX_VAT_LINES      = 10;
    private const MAX_ADDRESS_LINES  = 3;
    private const MAX_QUOTE          = 40;

    /** @var list<string> */
    private array $warnings = [];

    /** True when $answer looks like an answer at all: a JSON object naming at least one field. */
    public static function isAnswer(mixed $answer): bool
    {
        return is_array($answer) && $answer !== [] && !array_is_list($answer)
            && array_intersect(array_keys($answer), self::FIELDS) !== [];
    }

    /**
     * The JSON schema for `output_config.format` — every field required (structured outputs need
     * `required` + `additionalProperties: false`; numeric and length constraints are not supported
     * there, so they live in {@see map()}). Amounts are strings: no float ever reaches `Money`.
     *
     * NO union types: «not printed» is the empty string, never null. The API refuses a schema
     * with more than {@see MAX_UNION_PARAMETERS} nullable / anyOf parameters (HTTP 400 «too many
     * parameters with union types», found live 2026-11-15 with 17) — and {@see map()} reads '' as
     * null anyway ({@see text()}). A later field stays a plain string too.
     */
    public static function schema(): array
    {
        $text = static fn(string $description): array => [
            'type'        => 'string',
            'description' => $description . ' Empty string when not printed.',
        ];
        $date = $text;

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => self::FIELDS,
            'properties'           => [
                'creditor_name'          => $text('Name of the supplier who issued the invoice and is to be paid (not the recipient).'),
                'creditor_address_lines' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Street and P.O. box lines of the supplier, without name, postal code and town. Empty list when not printed.',
                ],
                'creditor_postal_code'   => $text('Postal code of the supplier.'),
                'creditor_town'          => $text('Town of the supplier.'),
                'creditor_country'       => $text('Country of the supplier as ISO 3166 alpha-2 code, e.g. CH, DE.'),
                'creditor_uid'           => $text('Swiss company UID of the supplier as printed, e.g. CHE-123.456.789. Empty for a foreign VAT number.'),
                'iban'                   => $text('IBAN of the supplier account to pay into, as printed.'),
                'currency'               => $text('ISO 4217 code of the invoice currency, e.g. CHF, EUR.'),
                'gross_total'            => $text('Total amount payable including VAT: digits, a dot, two decimals, no thousands separator, e.g. "1234.50".'),
                'vat_lines'              => [
                    'type'        => 'array',
                    'description' => 'One entry per VAT rate printed on the invoice. Empty list when none is printed.',
                    'items'       => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['rate', 'net', 'vat'],
                        'properties'           => [
                            'rate' => ['type' => 'string', 'description' => 'VAT rate in percent, e.g. "8.1".'],
                            'net'  => $text('Net amount taxed at this rate, format "1234.50".'),
                            'vat'  => $text('VAT amount at this rate, format "1234.50".'),
                        ],
                    ],
                ],
                'invoice_number'         => $text('Invoice number as printed.'),
                'invoice_date'           => $date('Invoice date, YYYY-MM-DD.'),
                'due_date'               => $date('Due date, YYYY-MM-DD: as printed, or the invoice date plus a printed payment term in days.'),
                'reference_type'         => [
                    'type'        => 'string',
                    'enum'        => [RecognizedPayment::REFERENCE_QRR, RecognizedPayment::REFERENCE_SCOR, RecognizedPayment::REFERENCE_NON, ''],
                    'description' => 'Swiss QR-bill reference type: QRR (27 digits), SCOR (starts with RF), NON (payment part without reference). Empty string without a Swiss payment part.',
                ],
                'reference'              => $text('The payment reference as printed (QRR or SCOR), else empty.'),
                'line_text'              => $text('Short German booking text, at most 80 characters, Swiss spelling (ss, never ß), e.g. "Druckerpatronen Oktober 2026".'),
                'expense_category'       => $text('The kind of expense in plain German words, e.g. "Büromaterial", "Telefon und Internet", "Miete", "Software-Abo". Never an account number.'),
            ],
        ];
    }

    /** @param array<string, mixed> $answer an answer that passed {@see isAnswer()} */
    public function map(array $answer, string $model): AiInvoiceData
    {
        $this->warnings = [];

        $name    = $this->text($answer['creditor_name'] ?? null, 140, 'Kreditor');
        $address = $this->addressLines($answer['creditor_address_lines'] ?? []);
        $postal  = $this->pattern($answer['creditor_postal_code'] ?? null, '/^[A-Z0-9][A-Z0-9 \-]{1,9}$/', 'PLZ', true);
        $town    = $this->text($answer['creditor_town'] ?? null, 35, 'Ort');
        $country = $this->pattern($answer['creditor_country'] ?? null, '/^[A-Z]{2}$/', 'Land', true);
        $uid     = $this->uid($answer['creditor_uid'] ?? null);

        $iban     = $this->iban($answer['iban'] ?? null);
        $isQrIban = $iban !== null && Iban::isQrIban($iban);
        $currency = $this->pattern($answer['currency'] ?? null, '/^[A-Z]{3}$/', 'Währung', true);
        $gross    = $this->money($answer['gross_total'] ?? null, $currency, 'Gesamtbetrag');
        [$referenceType, $reference] = $this->reference($answer['reference_type'] ?? null, $answer['reference'] ?? null, $iban, $isQrIban);

        $invoiceDate = $this->date($answer['invoice_date'] ?? null, 'Rechnungsdatum');
        $dueDate     = $this->date($answer['due_date'] ?? null, 'Fälligkeit');
        if ($invoiceDate !== null && $dueDate !== null && $dueDate < $invoiceDate) {
            $this->warnings[] = 'Fälligkeit vor dem Rechnungsdatum — nicht übernommen';
            $dueDate = null;
        }

        $payment = new RecognizedPayment(
            iban: $iban,
            isQrIban: $isQrIban,
            currency: $currency,
            amount: $gross,
            referenceType: $referenceType,
            reference: $reference,
            creditorName: $name,
            creditorAddress: $address,
            creditorPostalCode: $postal,
            creditorTown: $town,
            debtorName: null,
            debtorAddress: [],
            message: null,
            billing: null,
            notForPayment: false,
            occurrences: $iban === null ? 0 : 1,
            warnings: [],
        );

        $data = [
            'invoiceNumber'   => $this->text($answer['invoice_number'] ?? null, 40, 'Rechnungsnummer'),
            'vatLines'        => $this->vatLines($answer['vat_lines'] ?? [], $currency),
            'lineText'        => $this->text($answer['line_text'] ?? null, 120, 'Buchungstext'),
            'expenseCategory' => $this->text($answer['expense_category'] ?? null, 60, 'Aufwandart'),
        ];
        $warnings = array_values(array_unique($this->warnings));

        return new AiInvoiceData(
            payment: $warnings === [] ? $payment : $payment->withWarnings($warnings),
            creditorCountry: $country,
            uid: $uid,
            invoiceNumber: $data['invoiceNumber'],
            invoiceDate: $invoiceDate,
            dueDate: $dueDate,
            vatLines: $data['vatLines'],
            lineText: $data['lineText'],
            expenseCategory: $data['expenseCategory'],
            model: $model,
            warnings: $warnings,
        );
    }

    /** Plain one-line text: control characters → space, whitespace collapsed; too long → dropped. */
    private function text(mixed $value, int $max, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $this->warnings[] = "KI-Angabe {$label}: kein Text — nicht übernommen";

            return null;
        }
        $clean = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $value));
        if ($clean === '' || !mb_check_encoding($clean, 'UTF-8')) {
            return null;
        }
        if (mb_strlen($clean) > $max) {
            $this->warnings[] = "KI-Angabe {$label}: länger als {$max} Zeichen — nicht übernommen";

            return null;
        }

        return $clean;
    }

    private function pattern(mixed $value, string $regex, string $label, bool $upper = false): ?string
    {
        $text = $this->text($value, 40, $label);
        if ($text === null) {
            return null;
        }
        $text = $upper ? mb_strtoupper($text) : $text;
        if (preg_match($regex, $text) !== 1) {
            $this->warnings[] = "KI-Angabe {$label} «" . self::quote($text) . '» ungültig — nicht übernommen';

            return null;
        }

        return $text;
    }

    /** @return list<string> */
    private function addressLines(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->warnings[] = 'KI-Angabe Adresse: keine Liste — nicht übernommen';

            return [];
        }
        $lines = [];
        foreach ($value as $line) {
            $text = $this->text($line, 70, 'Adresszeile');
            if ($text !== null) {
                $lines[] = $text;
            }
        }
        if (count($lines) > self::MAX_ADDRESS_LINES) {
            $this->warnings[] = 'KI-Angabe Adresse: mehr als ' . self::MAX_ADDRESS_LINES . ' Zeilen — nicht übernommen';

            return [];
        }

        return $lines;
    }

    private function uid(mixed $value): ?string
    {
        $text = $this->text($value, 40, 'UID');
        if ($text === null) {
            return null;
        }
        $uid = Uid::normalize($text);
        if (!Uid::hasValidCheckDigit($uid)) {
            $this->warnings[] = 'KI-Angabe UID «' . self::quote($text) . '» ungültig — nicht übernommen';

            return null;
        }

        return $uid;
    }

    private function iban(mixed $value): ?string
    {
        $text = $this->text($value, 60, 'IBAN');
        if ($text === null) {
            return null;
        }
        $iban = Iban::normalize($text);
        if (!Iban::isWellFormed($iban) || !Iban::hasValidCheckDigits($iban)) {
            $this->warnings[] = 'KI-Angabe IBAN «' . self::quote($text) . '» ungültig — nicht übernommen';

            return null;
        }

        return $iban;
    }

    /**
     * «1234.50» (an apostrophe or space as thousands separator is tolerated) within the QR-bill
     * range 0.01 – 999 999 999.99 (0.00 only where $allowZero — a 0 % VAT line), with a
     * currency. Anything else is dropped with a warning.
     */
    private function money(mixed $value, ?string $currency, string $label, bool $allowZero = false): ?Money
    {
        $text = $this->text($value, 20, $label);
        if ($text === null) {
            return null;
        }
        $decimal = (string) preg_replace("/[ '\u{2019}]/u", '', $text);
        if (preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $decimal) !== 1 || (!$allowZero && preg_match('/[1-9]/', $decimal) !== 1)) {
            $this->warnings[] = "KI-Angabe {$label} «" . self::quote($text) . '» ungültig — nicht übernommen';

            return null;
        }
        if ($currency === null) {
            $this->warnings[] = "KI-Angabe {$label} ohne Währung — nicht übernommen";

            return null;
        }

        return Money::fromDecimal($decimal, $currency);
    }

    private function date(mixed $value, string $label): ?\DateTimeImmutable
    {
        $text = $this->text($value, 20, $label);
        if ($text === null) {
            return null;
        }
        if (preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $this->warnings[] = "KI-Angabe {$label} «" . self::quote($text) . '» ungültig — nicht übernommen';

            return null;
        }

        return new \DateTimeImmutable($text);
    }

    /**
     * The reference and its type, each re-derived from the reference itself: a QRR must pass
     * `QrReference`, a SCOR `ScorReference`; NON carries none. A mismatch with the IBAN is kept
     * with a warning, as the text layer does.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function reference(mixed $type, mixed $value, ?string $iban, bool $isQrIban): array
    {
        $text = $this->text($value, 40, 'Referenz');
        $type = $type === '' ? null : $type;   // '' = not printed (no union types in the schema)
        if ($type !== null && !in_array($type, [RecognizedPayment::REFERENCE_QRR, RecognizedPayment::REFERENCE_SCOR, RecognizedPayment::REFERENCE_NON], true)) {
            $this->warnings[] = 'KI-Angabe Referenzart ungültig — nicht übernommen';
            $type = null;
        }
        if ($text === null) {
            return [$type === RecognizedPayment::REFERENCE_NON ? $type : null, null];
        }
        $reference = null;
        $derived   = null;
        $qr        = QrReference::normalize($text);
        $scor      = ScorReference::normalize($text);
        if (QrReference::isValid($qr)) {
            [$reference, $derived] = [$qr, RecognizedPayment::REFERENCE_QRR];
        } elseif (ScorReference::isValid($scor)) {
            [$reference, $derived] = [$scor, RecognizedPayment::REFERENCE_SCOR];
        }
        if ($reference === null) {
            $this->warnings[] = 'KI-Angabe Referenz «' . self::quote($text) . '» ungültig — nicht übernommen';

            return [null, null];
        }
        if ($type !== null && $type !== $derived) {
            $this->warnings[] = "KI-Angabe Referenzart {$type} passt nicht zur Referenz — {$derived} übernommen";
        }
        if ($iban !== null && $isQrIban && $derived !== RecognizedPayment::REFERENCE_QRR) {
            $this->warnings[] = 'Eine QR-IBAN verlangt eine QR-Referenz';
        }
        if ($iban !== null && !$isQrIban && $derived === RecognizedPayment::REFERENCE_QRR) {
            $this->warnings[] = 'Eine QR-Referenz gilt nur mit einer QR-IBAN';
        }

        return [$derived, $reference];
    }

    /** @return list<array{rate: string, net: ?Money, vat: ?Money}> */
    private function vatLines(mixed $value, ?string $currency): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->warnings[] = 'KI-Angabe MWST-Zeilen: keine Liste — nicht übernommen';

            return [];
        }
        if (count($value) > self::MAX_VAT_LINES) {
            $this->warnings[] = 'KI-Angabe MWST-Zeilen: mehr als ' . self::MAX_VAT_LINES . ' — nicht übernommen';

            return [];
        }
        $lines = [];
        foreach ($value as $line) {
            $rate = is_array($line) ? $this->text($line['rate'] ?? null, 10, 'MWST-Satz') : null;
            $rate = $rate === null ? null : rtrim(str_replace(',', '.', $rate), ' %');
            if ($rate === null || preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $rate) !== 1) {
                $this->warnings[] = 'KI-Angabe MWST-Zeile ohne gültigen Satz — nicht übernommen';
                continue;
            }
            $lines[] = [
                'rate' => $rate,
                'net'  => $this->money($line['net'] ?? null, $currency, "MWST {$rate} % Basis", true),
                'vat'  => $this->money($line['vat'] ?? null, $currency, "MWST {$rate} % Steuer", true),
            ];
        }

        return $lines;
    }

    private static function quote(string $text): string
    {
        return mb_strlen($text) > self::MAX_QUOTE ? mb_substr($text, 0, self::MAX_QUOTE) . '…' : $text;
    }
}
