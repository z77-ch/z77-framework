<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\PaymentSnapshot;
use Z77\Module\Debtor\Services\Iban;
use Z77\Shared\Money\Money;

/**
 * The Swiss QR-bill of a document — built from the document's SNAPSHOT only
 * (`debtor.md` rule: VAT, address, terms and totals are read from the
 * document, never resolved again): the payment part (`Invoice::getPayment()`,
 * {@see PaymentSnapshot}), the address block (`AddressSnapshot`) as the
 * debtor, and the gross total. Deterministic: the same document yields the
 * same payload, whatever changed in the master data since.
 *
 * The data structure follows SIX, «Swiss Implementation Guidelines for the
 * QR-bill» v2.x (version `0200`): the elements in their fixed order, one per
 * line (LF), no separator after the last one:
 *
 *   Header      QRType `SPC` · Version `0200` · Coding `1`
 *   CdtrInf     IBAN (21, CH / LI)
 *   Cdtr        AdrTp `S` · Name 70 · StrtNmOrAdrLine1 70 · BldgNbOrAdrLine2 16 · PstCd 16 · TwnNm 35 · Ctry 2
 *   UltmtCdtr   seven empty elements (reserved)
 *   CcyAmt      Amt (0.01–999999999.99, two decimals, no separators) · Ccy `CHF` | `EUR`
 *   UltmtDbtr   AdrTp `S` + the six fields, or seven empty elements
 *   RmtInf      Tp `QRR` | `NON` · Ref (27 digits with QRR, empty with NON) · AddInf/Ustrd 140 · Trailer `EPD`
 *
 * 31 elements; the optional «StrdBkgInf» and «AltPmtInf» after the trailer
 * are not printed. The whole payload is at most 997 characters, UTF-8, from
 * the extended Latin character set the guidelines allow
 * ({@see isAllowedText()}); the QR code itself is error-correction level M
 * with the Swiss cross in its centre (rendering — the PDF, see `debtor.md`).
 *
 * «Degrade quietly» (wdv's `prepare()`, kept by the owner 2026-09-23): a
 * bill that cannot be built is not an exception but a list of
 * {@see problems()} in German — the document is issued anyway and prints
 * without a payment part until the cause is fixed and the document is
 * re-issued. A debtor block that does not fit the specification is left out
 * (the payer writes it by hand); the creditor, the account, the amount and
 * the reference must fit.
 */
final class QrBill
{
    public const TYPE    = 'SPC';
    public const VERSION = '0200';
    public const CODING  = '1';
    public const TRAILER = 'EPD';

    public const MAX_PAYLOAD  = 997;
    public const ELEMENTS     = 31;
    public const CURRENCIES   = ['CHF', 'EUR'];
    public const MIN_AMOUNT   = 1;              // 0.01 in minor units
    public const MAX_AMOUNT   = 99999999999;    // 999'999'999.99 in minor units

    /** Field → maximum length in the specification («S» address). */
    public const ADDRESS_LENGTHS = ['name' => 70, 'street' => 70, 'house_no' => 16, 'zip' => 16, 'city' => 35, 'country' => 2];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, string>|null the debtor block, null = left out */
    private ?array $debtor;

    /** @var array<string, string> */
    private array $creditor;

    private function __construct(
        public readonly string $account,
        public readonly string $referenceType,
        public readonly string $reference,
        public readonly string $message,
        public readonly ?Money $amount,
        public readonly string $currency,
        array $creditor,
        ?array $debtor,
    ) {
        $this->creditor = $creditor;
        $this->debtor   = $debtor;
    }

    /**
     * Whether $text uses only characters a QR-bill may carry: the extended
     * Latin set of the guidelines (v2.3) — Basic Latin 0x20–0x7E, Latin-1
     * Supplement 0xA0–0xFF, Latin Extended-A 0x100–0x17F, Ș ș Ț ț and €.
     * No control characters (a line break would split an element).
     */
    public static function isAllowedText(string $text): bool
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return false;
        }
        foreach (mb_str_split($text) as $char) {
            $cp = mb_ord($char, 'UTF-8');
            $ok = ($cp >= 0x20 && $cp <= 0x7E) || ($cp >= 0xA0 && $cp <= 0x17F)
                || in_array($cp, [0x218, 0x219, 0x21A, 0x21B, 0x20AC], true);
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** The bill of $document, from its snapshot. */
    public static function of(Invoice $document): self
    {
        $payment = $document->getPayment();
        $bill    = new self(
            $payment->getAccount(),
            $payment->getReferenceType(),
            $payment->getReference(),
            $payment->getMessage(),
            $document->getGrossTotal(),
            $document->getCurrency(),
            [
                'name'     => $payment->getCreditorName(),
                'street'   => $payment->getCreditorStreet(),
                'house_no' => $payment->getCreditorHouseNo(),
                'zip'      => $payment->getCreditorZip(),
                'city'     => $payment->getCreditorCity(),
                'country'  => $payment->getCreditorCountry(),
            ],
            self::debtorOf($document->getAddress()),
        );
        if ($document->isCreditNote()) {
            $bill->problems[] = 'Eine Gutschrift hat keinen Zahlteil.';
        } elseif (!$payment->hasPaymentPart()) {
            // A final document is never re-issued (review 2026-09-30): the way out is a credit note and a new invoice.
            $bill->problems[] = $document->isFinal()
                ? 'Ohne Zahlteil ausgestellt und definitiv — er lässt sich nicht nachtragen; nötigenfalls Gutschrift und neue Rechnung mit Zahlungsziel.'
                : 'Ohne Zahlungsziel ausgestellt — kein Zahlteil. Zahlungsziel wählen und neu fakturieren.';
        } else {
            $bill->check();
        }

        return $bill;
    }

    public function isPrintable(): bool
    {
        return $this->problems === [];
    }

    /** @return list<string> German, one sentence each; empty when the bill is printable */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string, string> name, street, house_no, zip, city, country */
    public function creditor(): array
    {
        return $this->creditor;
    }

    /** @return array<string, string>|null the debtor block, null when left out */
    public function debtor(): ?array
    {
        return $this->debtor;
    }

    /** «CH44 3199 9123 0008 8901 2» — as the payment part prints the account. */
    public function formattedAccount(): string
    {
        return Iban::format($this->account);
    }

    /** The QR reference in blocks of five, '' with NON. */
    public function formattedReference(): string
    {
        return $this->reference === '' ? '' : QrReference::format($this->reference);
    }

    /**
     * The payload the QR code encodes — the elements in the order of the
     * guidelines, separated by LF.
     *
     * @throws \LogicException the bill is not printable ({@see problems()})
     */
    public function payload(): string
    {
        if (!$this->isPrintable()) {
            throw new \LogicException('QR-bill not printable: ' . implode(' ', $this->problems));
        }

        return self::assemble($this);
    }

    /** @return list<string> */
    private static function elements(self $bill): array
    {
        $address = static fn(?array $a): array => $a === null
            ? ['', '', '', '', '', '', '']
            : ['S', $a['name'], $a['street'], $a['house_no'], $a['zip'], $a['city'], $a['country']];

        return [
            self::TYPE, self::VERSION, self::CODING,
            $bill->account,
            ...$address($bill->creditor),
            '', '', '', '', '', '', '',                                 // UltmtCdtr — reserved, empty
            $bill->amount === null ? '' : $bill->amount->toDecimal(),
            $bill->currency,
            ...$address($bill->debtor),
            $bill->referenceType,
            $bill->reference,
            $bill->message,
            self::TRAILER,
        ];
    }

    private static function assemble(self $bill): string
    {
        return implode("\n", self::elements($bill));
    }

    /** Every rule of the specification the snapshot must meet; the findings as German sentences. */
    private function check(): void
    {
        $iban = $this->account;
        if (!Iban::isWellFormed($iban) || !Iban::hasValidCheckDigits($iban) || !Iban::isSwissArea($iban)) {
            $this->problems[] = 'Das Konto ' . Iban::format($iban) . ' ist keine gültige schweizerische oder liechtensteinische IBAN.';
        } elseif ($this->referenceType === PaymentSnapshot::REFERENCE_QRR && !Iban::isQrIban($iban)) {
            $this->problems[] = 'Eine QR-Referenz verlangt eine QR-IBAN — ' . Iban::format($iban) . ' ist keine.';
        } elseif ($this->referenceType === PaymentSnapshot::REFERENCE_NON && Iban::isQrIban($iban)) {
            $this->problems[] = 'Eine QR-IBAN wird nie ohne Referenz gedruckt (die Bank weist die Zahlung ab).';
        }
        if ($this->referenceType === PaymentSnapshot::REFERENCE_QRR && !QrReference::isValid($this->reference)) {
            $this->problems[] = 'Die QR-Referenz ist ungültig (27 Ziffern, Prüfziffer Modulo 10 rekursiv).';
        }
        if ($this->referenceType === PaymentSnapshot::REFERENCE_NON && $this->reference !== '') {
            $this->problems[] = 'Ohne QR-IBAN (Referenztyp NON) trägt der Beleg keine Referenz.';
        }
        if (!in_array($this->referenceType, [PaymentSnapshot::REFERENCE_QRR, PaymentSnapshot::REFERENCE_NON], true)) {
            $this->problems[] = 'Referenztyp «' . $this->referenceType . '» wird nicht gedruckt (nur QRR oder NON).';
        }
        if (!in_array($this->currency, self::CURRENCIES, true)) {
            $this->problems[] = 'Ein QR-Einzahlungsschein lautet auf CHF oder EUR, nicht auf ' . $this->currency . '.';
        }
        if ($this->amount === null || $this->amount->minor < self::MIN_AMOUNT || $this->amount->minor > self::MAX_AMOUNT) {
            $this->problems[] = 'Der Betrag muss zwischen 0.01 und 999\'999\'999.99 liegen.';
        }
        $labels = ['name' => 'Name', 'street' => 'Strasse', 'house_no' => 'Hausnummer', 'zip' => 'PLZ', 'city' => 'Ort', 'country' => 'Land'];
        foreach (['name', 'zip', 'city', 'country'] as $required) {
            if ($this->creditor[$required] === '') {
                $this->problems[] = 'Zahlungsempfänger: ' . $labels[$required] . ' fehlt (Zahlungsziel oder Mandant ergänzen).';
            }
        }
        foreach (self::ADDRESS_LENGTHS as $field => $max) {
            if (mb_strlen($this->creditor[$field]) > $max) {
                $this->problems[] = 'Zahlungsempfänger: ' . $labels[$field] . ' hat mehr als ' . $max . ' Zeichen (beim Zahlungsziel kürzer erfassen).';
            } elseif (!self::isAllowedText($this->creditor[$field])) {
                $this->problems[] = 'Zahlungsempfänger: ' . $labels[$field] . ' enthält Zeichen, die ein QR-Einzahlungsschein nicht drucken darf.';
            }
        }
        if ($this->creditor['country'] !== '' && !preg_match('/^[A-Z]{2}$/', $this->creditor['country'])) {
            $this->problems[] = 'Zahlungsempfänger: das Land ist kein ISO-Code aus zwei Buchstaben.';
        }
        if (mb_strlen($this->message) > PaymentSnapshot::MESSAGE_LENGTH || !self::isAllowedText($this->message)) {
            $this->problems[] = 'Die Mitteilung passt nicht auf den Zahlteil (140 Zeichen, erlaubte Zeichen).';
        }
        // A guard: with every element within its own limit the payload stays near 670 characters (harness P3C55).
        if ($this->problems === [] && mb_strlen(self::assemble($this)) > self::MAX_PAYLOAD) {
            $this->problems[] = 'Die Daten des Zahlteils sind länger als 997 Zeichen.';
        }
    }

    /**
     * The debtor block from the address snapshot — the name as printed
     * (first name and name), street, house number, zip, city, country; null
     * when a field does not fit the specification or is missing (the block
     * is optional: the payer then writes it on the receipt by hand).
     *
     * @return array<string, string>|null
     */
    private static function debtorOf(AddressSnapshot $address): ?array
    {
        $block = [
            'name'     => trim($address->getFirstName() . ' ' . $address->getName()),
            'street'   => $address->getStreet(),
            'house_no' => $address->getHouseNo(),
            'zip'      => $address->getZip(),
            'city'     => $address->getCity(),
            'country'  => $address->getCountry(),
        ];
        if ($block['name'] === '' || $block['zip'] === '' || $block['city'] === '' || !preg_match('/^[A-Z]{2}$/', $block['country'])) {
            return null;
        }
        foreach (self::ADDRESS_LENGTHS as $field => $max) {
            if (mb_strlen($block[$field]) > $max || !self::isAllowedText($block[$field])) {
                return null;
            }
        }

        return $block;
    }
}
