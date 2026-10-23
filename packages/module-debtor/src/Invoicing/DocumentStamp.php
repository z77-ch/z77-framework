<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Shared\Money\Money;

/**
 * The STAMP of an issued document version (ADR-054 decision 24, owner decision O12 of
 * 2026-10-23): a short ID that names exactly one version — printed small next to the number,
 * recorded per version in `invoice_revision.document_stamp` (unique), cited by a successor's
 * «ersetzt Rechnung 2610003 (ID …)». Pure; reads the document's SNAPSHOT only.
 *
 * Definition (fixed in step 3 of the build plan, 2026-10-23 — changing it changes no stored
 * stamp, but a recomputation would no longer match; a new definition gets a new `v`):
 *
 *   stamp = the first {@see LENGTH} hexadecimal digits (lower case) of
 *           SHA-256( {@see canonical()} of the version )
 *
 * The canonical serialisation is JSON (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, no
 * whitespace, keys in the fixed order below, money as decimal strings `"1234.50"`, dates
 * `Y-m-d`, absent values `null`) of the CONTENT the customer holds — never the PDF bytes (a
 * PDF cannot contain its own hash):
 *
 *   v (format, "1") · kind · number · revision · invoice_date · service_from · service_to ·
 *   due_date · currency · price_mode · recipient (contact id, customer number, salutation,
 *   title, first name, name, address row, street, house no., zip, city, country) · subject
 *   label · subject · credit note of (number) · lines (per line: position, parent position,
 *   type, code, text, quantity, unit, unit price, discount, amount, tax code, tax rate,
 *   revenue account) · totals (net, tax, rounding, gross) · taxes (per row: code, rate, base,
 *   tax) · payment (account, reference type, reference)
 *
 * A collision (another version already carries the same ten digits — the database's unique
 * index is the final word) is resolved by {@see of()} with a salt: `"salt": n` appended to the
 * serialisation, n = 1, 2, … — so the stamp stays content-derived and reproducible from the
 * stored row plus the salt (none in practice: 40 bits).
 */
final class DocumentStamp
{
    /** Ten hex digits = 40 bits — the column `document_stamp` holds ten characters. */
    public const LENGTH = Invoice::STAMP_LENGTH;

    /** The serialisation format — part of the hashed text. */
    public const FORMAT = '1';

    /** How many salts are tried before a collision is treated as a bug. */
    private const MAX_SALT = 20;

    /**
     * The stamp of $document as it stands (numbered, its revision set), unique against $taken.
     *
     * @param callable(string): bool $taken whether a stamp is already used by another version
     */
    public static function of(Invoice $document, int $customerNumber, callable $taken): string
    {
        $canonical = self::canonical($document, $customerNumber);
        for ($salt = 0; $salt <= self::MAX_SALT; $salt++) {
            $stamp = self::hash($salt === 0 ? $canonical : substr($canonical, 0, -1) . ',"salt":' . $salt . '}');
            if (!$taken($stamp)) {
                return $stamp;
            }
        }
        throw new \LogicException('No free document stamp after ' . self::MAX_SALT . ' salts — the stamp definition is broken');
    }

    /** The first {@see LENGTH} hex digits of SHA-256($text). */
    public static function hash(string $text): string
    {
        return substr(hash('sha256', $text), 0, self::LENGTH);
    }

    /** The canonical serialisation of the version (see the class comment) — what the stamp hashes. */
    public static function canonical(Invoice $document, int $customerNumber): string
    {
        if (!$document->isNumbered()) {
            throw new \LogicException('A document is stamped once it carries its number');
        }
        $money   = static fn(?Money $m): ?string => $m?->toDecimal();
        $date    = static fn(?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d');
        $address = $document->getAddress();
        $lines   = [];
        foreach ($document->getLines() as $line) {
            /** @var InvoiceLine $line */
            $lines[] = [
                'position'   => $line->getPosition(),
                'parent'     => $line->getParentLine()?->getPosition(),
                'type'       => $line->type()->value,
                'code'       => $line->getCode(),
                'text'       => $line->getText(),
                'quantity'   => $line->getQuantity(),
                'unit'       => $line->getUnit(),
                'unit_price' => $money($line->getUnitPrice()),
                'discount'   => $line->getDiscountPercent(),
                'amount'     => $money($line->getAmount()),
                'tax_code'   => $line->getTaxCode(),
                'tax_rate'   => $line->getTaxRate(),
                'account'    => $line->getRevenueAccount(),
            ];
        }
        $taxes = [];
        foreach ($document->getTaxes() as $tax) {
            $taxes[] = ['code' => $tax->getTaxCode(), 'rate' => $tax->getTaxRate(), 'base' => $money($tax->getBase()), 'tax' => $money($tax->getTax())];
        }
        $payment = $document->getPayment();

        return json_encode([
            'v'            => self::FORMAT,
            'kind'         => $document->kind()->value,
            'number'       => $document->getNumber(),
            'revision'     => $document->getRevision(),
            'invoice_date' => $date($document->getInvoiceDate()),
            'service_from' => $date($document->getServiceFrom()),
            'service_to'   => $date($document->getServiceTo()),
            'due_date'     => $date($document->getDueDate()),
            'currency'     => $document->getCurrency(),
            'price_mode'   => $document->getPriceMode(),
            'recipient'    => [
                'contact'    => $document->getContact()->getId(),
                'customer'   => $customerNumber,
                'salutation' => $address->getSalutation(),
                'title'      => $address->getTitle(),
                'first_name' => $address->getFirstName(),
                'name'       => $address->getName(),
                'row'        => $address->getAddressRow(),
                'street'     => $address->getStreet(),
                'house_no'   => $address->getHouseNo(),
                'zip'        => $address->getZip(),
                'city'       => $address->getCity(),
                'country'    => $address->getCountry(),
            ],
            'subject_label'  => $document->getSubjectLabel(),
            'subject'        => $document->getSubject(),
            'credit_note_of' => $document->getCreditNoteOf()?->getNumber(),
            'lines'          => $lines,
            'totals'         => [
                'net'      => $money($document->getNetTotal()),
                'tax'      => $money($document->getTaxTotal()),
                'rounding' => $money($document->getRounding()),
                'gross'    => $money($document->getGrossTotal()),
            ],
            'taxes'   => $taxes,
            'payment' => ['account' => $payment->getAccount(), 'reference_type' => $payment->getReferenceType(), 'reference' => $payment->getReference()],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
