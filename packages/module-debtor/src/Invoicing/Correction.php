<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Shared\Money\AmountFormat;

/**
 * What a correction of an ISSUED document (in the Faktura-Journal) becomes — the ONE decision rule
 * of ADR-054 decision 8, pure: the issued document compared with what its corrected draft composes
 * to (`InvoicingService::classify()` builds the transient candidate, never stored).
 *
 *   | changed                                                              | result                         |
 *   |----------------------------------------------------------------------|--------------------------------|
 *   | the gross total, the VAT (tax rows code / rate / base / tax, or with | REPLACEMENT — a new number     |
 *   | / without VAT), the MONTH of the invoice date, the party (O4), the   | (rules 3, 8, 9; owner O4)      |
 *   | payment reference TYPE (QR reference ↔ none — the reference changes) |                                |
 *   | anything else of the snapshot: lines (text, quantity, price, account,| REVISION — same number,        |
 *   | code …), invoice date within the month, service dates, terms, due    | revision + 1 (rule 11)         |
 *   | date, address, subject, payment target / account, the no-VAT note    |                                |
 *   | nothing                                                              | NONE — nothing written         |
 *
 * NOT compared: the number, the revision, the stamp, the state, who / when, the opaque origin, the
 * QR reference and message (derived from the number) and the creditor block of the payment part
 * (the mandator's, taken again at every issue — a changed mandator address is no deviation of the
 * document's source). The reasons are German phrases the screens show («Betrag geändert (300.00 →
 * 320.00)», «Positionen»); {@see summary()} joins them.
 */
final class Correction
{
    public const NONE        = 'none';
    public const REVISION    = 'revision';
    public const REPLACEMENT = 'replacement';

    /** @param list<string> $reasons German, in a fixed order — why it is a replacement (or what a revision changes) */
    private function __construct(public readonly string $kind, public readonly array $reasons)
    {
    }

    /** $issued (in the journal, lines loaded) against $candidate (the corrected draft composed, transient). */
    public static function classify(Invoice $issued, Invoice $candidate): self
    {
        $money = static fn($m): string => AmountFormat::of($m);
        $replace = [];
        if (!$issued->getGrossTotal()->equals($candidate->getGrossTotal())) {
            $replace[] = 'Betrag geändert (' . $money($issued->getGrossTotal()) . ' → ' . $money($candidate->getGrossTotal()) . ')';
        }
        if ($issued->carriesVat() !== $candidate->carriesVat()) {
            $replace[] = $candidate->carriesVat() ? 'neu mit MWST' : 'neu ohne MWST';
        } elseif (self::taxes($issued) !== self::taxes($candidate)) {
            $replace[] = 'MWST geändert';
        }
        if ($issued->getInvoiceDate()->format('Y-m') !== $candidate->getInvoiceDate()->format('Y-m')) {
            $replace[] = 'Rechnungsmonat geändert (' . $issued->getInvoiceDate()->format('m.Y') . ' → ' . $candidate->getInvoiceDate()->format('m.Y') . ')';
        }
        if ($issued->getContact()->getId() !== $candidate->getContact()->getId()) {
            $replace[] = 'Kunde geändert';
        }
        if ($issued->getPayment()->getReferenceType() !== $candidate->getPayment()->getReferenceType()) {
            $replace[] = 'Zahlteil geändert (Referenzart)';
        }
        if ($replace !== []) {
            return new self(self::REPLACEMENT, $replace);
        }

        $revise = [];
        foreach (self::content($issued) as $label => $value) {
            if (self::content($candidate)[$label] !== $value) {
                $revise[] = $label;
            }
        }

        return new self($revise === [] ? self::NONE : self::REVISION, $revise);
    }

    public function isNone(): bool { return $this->kind === self::NONE; }
    public function isRevision(): bool { return $this->kind === self::REVISION; }
    public function isReplacement(): bool { return $this->kind === self::REPLACEMENT; }

    /** «Betrag geändert (300.00 → 320.00), Kunde geändert» / «geändert: Positionen, Betreff» / «keine Änderung». */
    public function summary(): string
    {
        return match ($this->kind) {
            self::REPLACEMENT => implode(', ', $this->reasons),
            self::REVISION    => 'geändert: ' . implode(', ', $this->reasons),
            default           => 'keine Änderung',
        };
    }

    /** «2» and «2.000» are one quantity: a stored line carries three decimals, a composed one what was typed. */
    private static function quantity(?string $quantity): ?string
    {
        if ($quantity === null) {
            return null;
        }
        [$int, $frac] = array_pad(explode('.', trim($quantity), 2), 2, '');

        return $int . '.' . str_pad(substr($frac, 0, 3), 3, '0');
    }

    /** @return list<array{string, int, string, string}> the tax rows as a set — code, rate, base, tax — in code order */
    private static function taxes(Invoice $document): array
    {
        $rows = [];
        foreach ($document->getTaxes() as $tax) {
            $rows[] = [$tax->getTaxCode(), $tax->getTaxRate(), $tax->getBase()->toDecimal(), $tax->getTax()->toDecimal()];
        }
        sort($rows);

        return $rows;
    }

    /**
     * The revision-relevant content, grouped under the German label a screen names it by.
     *
     * @return array<string, mixed>
     */
    private static function content(Invoice $d): array
    {
        $date    = static fn(?\DateTimeImmutable $x): ?string => $x?->format('Y-m-d');
        $address = $d->getAddress();
        $lines   = [];
        $accounts = [];
        foreach ($d->getLines() as $line) {
            /** @var InvoiceLine $line */
            $lines[] = [
                $line->getPosition(), $line->getParentLine()?->getPosition(), $line->type()->value, $line->getCode(), trim($line->getText()),
                self::quantity($line->getQuantity()), $line->getUnit(), $line->getUnitPrice()?->toDecimal(), $line->getDiscountPercent(), $line->getAmount()->toDecimal(),
                $line->getTaxCode(), $line->getTaxRate(),
            ];
            $accounts[] = [$line->getPosition(), $line->getRevenueAccount()];
        }
        $payment = $d->getPayment();

        return [
            'Rechnungsdatum'      => $date($d->getInvoiceDate()),
            'Leistungsdatum'      => [$date($d->getServiceFrom()), $date($d->getServiceTo())],
            'Positionen'          => $lines,
            'Ertragskonto'        => $accounts,
            'Preise netto / brutto' => $d->getPriceMode(),
            'Rechnungsadresse'    => [$address->getSalutation(), $address->getTitle(), $address->getFirstName(), $address->getName(), $address->getAddressRow(),
                $address->getStreet(), $address->getHouseNo(), $address->getZip(), $address->getCity(), $address->getCountry(), $address->getEmail(), $address->getPhone()],
            'Sprache'             => $d->getLanguage(),
            'Betreff'             => [$d->getSubjectLabel(), $d->getSubject()],
            'Zahlungskonditionen' => [$d->getPaymentTermsCode(), $date($d->getDueDate()), $d->getDiscountTiers(), (string) $d->getTermsText()],
            'Zahlungsziel'        => [$payment->getTargetCode(), $payment->getAccount()],
            'Hinweis ohne MWST'   => $d->getVatNote(),
        ];
    }
}
