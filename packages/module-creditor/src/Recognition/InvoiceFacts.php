<?php

namespace Z77\Module\Creditor\Recognition;

use Z77\Module\Creditor\Recognition\Ai\AiInvoiceData;
use Z77\Module\Mandator\Services\Uid;
use Z77\Shared\Money\Money;

/**
 * What ONE recognition step read off a supplier's PDF, in the shape the invoice takes it
 * (creditor plan §3 step 5, §4): the text layer's {@see RecognizedPayment} (plus a UID printed in
 * the letterhead) or the AI's {@see AiInvoiceData} — both sources alike, every value already
 * validated by its step. Plain data; `CreditorInvoiceService::applyRecognition()` decides what it
 * fills in (only what is still EMPTY) and records the step per value.
 *
 * {@see $step} is {@see STEP_TEXT} or {@see STEP_AI} — the word the row shows («Text» / «KI»).
 * {@see $proposal} is the creditor as printed (name, street, house_no, zip, city, country, iban,
 * uid) for an unknown creditor — `CreditorInvoice::proposeCreditor()`'s keys.
 */
final class InvoiceFacts
{
    public const STEP_TEXT = 'text';
    public const STEP_AI   = 'ai';

    /**
     * @param array<string, string> $proposal
     * @param list<string> $warnings German, for the row
     */
    public function __construct(
        public readonly string $step,
        public readonly ?string $iban,
        public readonly bool $isQrIban,
        public readonly ?string $currency,
        public readonly ?Money $amount,
        public readonly ?string $referenceType,        // QRR | SCOR | NON | null
        public readonly ?string $reference,
        public readonly ?string $message,
        public readonly ?string $invoiceNumber,
        public readonly ?\DateTimeImmutable $invoiceDate,
        public readonly ?\DateTimeImmutable $dueDate,
        public readonly ?string $uid,                  // canonical CHE-…, check digit valid
        public readonly ?string $lineText,
        public readonly ?string $category,             // AI: the expense in words — never an account
        public readonly array $proposal,
        public readonly ?string $confidence,
        public readonly bool $notForPayment,
        public readonly array $warnings,
        public readonly ?string $text = null,          // the text layer — searched for an IBAN link's hint
        public readonly array $sources = [],           // field → a finer source than $step ('text-label', 'text-header')
    ) {}

    /**
     * The text layer's payment part; the UID from S1, else the one UID the letterhead prints.
     * The header ABOVE the slip adds (owner 2026-11-15), each only where the slip has nothing:
     *
     *   - invoice number, invoice and due date from its LABELS ({@see HeaderLabels}) — Swico S1
     *     wins; source `text-label`; «zahlbar innert N Tagen» = invoice date + N; a due date
     *     before the invoice date is dropped with a warning; a total next to «Rechnungsbetrag» /
     *     «Total» that matches no QR amount is a warning — the QR amount wins;
     *   - the proposal's STREET when the slip prints none ({@see HeaderStreet}: the same name and
     *     postcode + town, exactly) — source `text-header`;
     *   - the slip's unstructured message as the single line's TEXT (applied only when empty).
     */
    public static function fromPayment(RecognizedPayment $payment, ?string $text): self
    {
        $billing  = $payment->billing;
        $uid      = $billing?->uid ?? ($text !== null ? self::letterheadUid($text) : null);
        $header   = $text !== null ? SwissQrBillText::textBefore($text) : '';
        $warnings = array_merge($payment->warnings, $billing?->warnings ?? []);
        $sources  = [];
        [$number, $date, $due] = self::fromLabels($header, $billing?->invoiceNumber, $billing?->invoiceDate, $billing?->dueDate(), $sources, $warnings);

        if ($payment->amount !== null) {
            $totals = HeaderLabels::read($header)->totals;
            if ($totals !== [] && !in_array($payment->amount->toDecimal(), $totals, true)) {
                $warnings[] = 'Rechnungsbetrag im Briefkopf (' . implode(' / ', $totals) . ') weicht vom QR-Betrag (' . $payment->amount->toDecimal() . ') ab — der QR-Betrag gilt';
            }
        }

        $proposal = self::proposalOf($payment, $uid, null);
        if (($proposal['street'] ?? '') === '' && ($proposal['name'] ?? '') !== '' && ($proposal['zip'] ?? '') !== '' && ($proposal['city'] ?? '') !== '') {
            $street = HeaderStreet::find($header, $proposal['name'], $proposal['zip'], $proposal['city']);
            if ($street !== null) {
                [$proposal['street'], $house] = self::splitStreet($street);
                if ($house !== '') {
                    $proposal['house_no'] = $house;
                }
                $sources['proposal_street'] = 'text-header';
            }
        }
        $message = $payment->message !== null ? trim((string) preg_replace('/\s+/u', ' ', $payment->message)) : '';

        return new self(
            self::STEP_TEXT,
            $payment->iban,
            $payment->isQrIban,
            $payment->currency,
            $payment->amount,
            $payment->referenceType,
            $payment->reference,
            $payment->message,
            $number,
            $date,
            $due,
            $uid,
            $message !== '' ? $message : null,
            null,
            $proposal,
            $payment->confidence(),
            $payment->notForPayment,
            array_values(array_unique($warnings)),
            $text,
            $sources,
        );
    }

    /**
     * A text layer WITHOUT a payment part may still name the supplier by its UID (a foreign-style
     * invoice, a receipt) — the creditor can be matched by it — and carry the header labels
     * (invoice number, dates; source `text-label`). Null when it offers none of them.
     */
    public static function fromTextOnly(string $text): ?self
    {
        $uid      = self::letterheadUid($text);
        $sources  = [];
        $warnings = [];
        [$number, $date, $due] = self::fromLabels(SwissQrBillText::textBefore($text), null, null, null, $sources, $warnings);
        if ($uid === null && $number === null && $date === null && $due === null) {
            return null;
        }

        return new self(self::STEP_TEXT, null, false, null, null, null, null, null, $number, $date, $due, $uid, null, null, [], null, false, array_values(array_unique($warnings)), $text, $sources);
    }

    /**
     * The header labels where S1 left a gap; S1 wins. The due date from «zahlbar innert N Tagen»
     * counts from the invoice date; a due date before the invoice date is dropped with a warning.
     *
     * @param array<string, string> $sources  field → `text-label` for every value the labels gave
     * @param list<string>          $warnings
     * @return array{0: ?string, 1: ?\DateTimeImmutable, 2: ?\DateTimeImmutable}
     */
    private static function fromLabels(string $header, ?string $number, ?\DateTimeImmutable $date, ?\DateTimeImmutable $due, array &$sources, array &$warnings): array
    {
        if (trim($header) === '') {
            return [$number, $date, $due];
        }
        $labels = HeaderLabels::read($header);
        if ($number === null && $labels->invoiceNumber !== null) {
            [$number, $sources['invoice_number']] = [$labels->invoiceNumber, 'text-label'];
        }
        if ($date === null && $labels->invoiceDate !== null) {
            [$date, $sources['invoice_date']] = [$labels->invoiceDate, 'text-label'];
        }
        if ($due === null) {
            $labelDue = $labels->dueDate ?? ($labels->dueDays !== null && $date !== null ? $date->modify('+' . $labels->dueDays . ' days') : null);
            if ($labelDue !== null && $date !== null && $labelDue < $date) {
                $warnings[] = 'Fälligkeit vor dem Rechnungsdatum — nicht übernommen';
            } elseif ($labelDue !== null) {
                [$due, $sources['due_date']] = [$labelDue, 'text-label'];
            }
        }

        return [$number, $date, $due];
    }

    /** The AI's answer — its payment part and the invoice fields; the category stays words. */
    public static function fromAi(AiInvoiceData $data): self
    {
        $p = $data->payment;

        return new self(
            self::STEP_AI,
            $p->iban,
            $p->isQrIban,
            $p->currency,
            $p->amount,
            $p->referenceType,
            $p->reference,
            null,
            $data->invoiceNumber,
            $data->invoiceDate,
            $data->dueDate,
            $data->uid,
            $data->lineText,
            $data->expenseCategory,
            self::proposalOf($p, $data->uid, $data->creditorCountry),
            $p->confidence(),
            false,
            $data->warnings,
        );
    }

    /**
     * The ONE valid Swiss UID the text prints («CHE-123.456.789 MWST», «CHE123456789»). Two
     * different ones (the supplier's and the recipient's) decide nothing — null, never a guess.
     */
    public static function letterheadUid(string $text): ?string
    {
        if (!preg_match_all('/\bCHE[\s\-]?(\d{3})[\s.]?(\d{3})[\s.]?(\d{3})\b/u', $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        $found = [];
        foreach ($m as $hit) {
            $uid = 'CHE-' . $hit[1] . '.' . $hit[2] . '.' . $hit[3];
            if (Uid::hasValidCheckDigit($uid)) {
                $found[$uid] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /** At least one value an invoice field can take. */
    public function hasAnyValue(): bool
    {
        return $this->iban !== null || $this->amount !== null || $this->reference !== null || $this->uid !== null
            || $this->invoiceNumber !== null || $this->invoiceDate !== null || $this->dueDate !== null
            || $this->message !== null || $this->lineText !== null || ($this->proposal['name'] ?? '') !== '';
    }

    /** «Alte Tiefenaustrasse 6» → [«Alte Tiefenaustrasse», «6»]; a line without a house number stays whole. */
    private static function splitStreet(string $line): array
    {
        if (preg_match('/^(.+?)\s+(\d+\s?[a-zA-Z]?(?:[\-\/]\d+[a-zA-Z]?)?)$/u', $line, $m)) {
            return [$m[1], str_replace(' ', '', $m[2])];
        }

        return [$line, ''];
    }

    /**
     * The creditor as printed — name, «Strasse Nr.» split, postcode, town, country (the AI's; else
     * the IBAN's country for CH / LI, the QR-bill countries), IBAN, UID.
     *
     * @return array<string, string>
     */
    private static function proposalOf(RecognizedPayment $p, ?string $uid, ?string $country): array
    {
        $street = '';
        $house  = '';
        foreach ($p->creditorAddress as $line) {
            if ($p->creditorPostalCode !== null && str_contains($line, $p->creditorPostalCode)) {
                continue;   // the postcode + town line
            }
            [$street, $house] = self::splitStreet($line);
            break;
        }
        $country ??= ($p->iban !== null && in_array(substr($p->iban, 0, 2), ['CH', 'LI'], true)) ? substr($p->iban, 0, 2) : null;

        return array_filter([
            'name'     => (string) $p->creditorName,
            'street'   => $street,
            'house_no' => $house,
            'zip'      => (string) $p->creditorPostalCode,
            'city'     => (string) $p->creditorTown,
            'country'  => (string) $country,
            'iban'     => (string) $p->iban,
            'uid'      => (string) $uid,
        ], static fn(string $v) => $v !== '');
    }
}
