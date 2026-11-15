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
    ) {}

    /** The text layer's payment part; the UID from S1, else the one UID the letterhead prints. */
    public static function fromPayment(RecognizedPayment $payment, ?string $text): self
    {
        $billing = $payment->billing;
        $uid     = $billing?->uid ?? ($text !== null ? self::letterheadUid($text) : null);

        return new self(
            self::STEP_TEXT,
            $payment->iban,
            $payment->isQrIban,
            $payment->currency,
            $payment->amount,
            $payment->referenceType,
            $payment->reference,
            $payment->message,
            $billing?->invoiceNumber,
            $billing?->invoiceDate,
            $billing?->dueDate(),
            $uid,
            null,
            null,
            self::proposalOf($payment, $uid, null),
            $payment->confidence(),
            $payment->notForPayment,
            array_values(array_unique(array_merge($payment->warnings, $billing?->warnings ?? []))),
            $text,
        );
    }

    /**
     * A text layer WITHOUT a payment part may still name the supplier by its UID (a foreign-style
     * invoice, a receipt) — the creditor can be matched by it. Null when the text names none.
     */
    public static function fromTextOnly(string $text): ?self
    {
        $uid = self::letterheadUid($text);
        if ($uid === null) {
            return null;
        }

        return new self(self::STEP_TEXT, null, false, null, null, null, null, null, null, null, null, $uid, null, null, [], null, false, [], $text);
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
            if (preg_match('/^(.+?)\s+(\d+\s?[a-zA-Z]?(?:[\-\/]\d+[a-zA-Z]?)?)$/u', $line, $m)) {
                [$street, $house] = [$m[1], str_replace(' ', '', $m[2])];
            } else {
                $street = $line;
            }
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
