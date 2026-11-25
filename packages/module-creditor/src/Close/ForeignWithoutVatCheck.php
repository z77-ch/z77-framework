<?php

namespace Z77\Module\Creditor\Close;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * The foreign-voucher warning of the VAT return (ADR-042 addendum 2026-11-24 point 1, as amended by
 * addendum 2 of 2026-11-25 point 9: built for creditor invoices only — a receipt has neither a
 * currency nor an address). Registered in `creditorConfig → openWorkChecks` under `vat-return` only;
 * parameters `from`, `to` (`DateTimeImmutable`) — the period of the return.
 *
 * A POSTED invoice whose payment date (= its posting date, the direct variant) lies in the period is
 * a possible reverse charge when it is FOREIGN — an original currency is set, or the creditor has an
 * address abroad and none in CH / LI — and at least one of its lines is «ohne MWST» while none
 * carries a reverse-charge code. A WARNING per invoice for the first {@see LIST_LIMIT}, then one that
 * counts the rest; confirmed with the one tick at «MWST buchen». Nothing is blocked: a foreign
 * invoice without VAT is often right (goods whose import VAT is on the customs voucher, a service
 * consumed abroad); the user decides.
 *
 * The sentences are what «MWST buchen» confirms (the warnings fingerprint, stored with the posted
 * return) — keep their wording stable.
 *
 * The reverse-charge codes are module-vat's file data (category `reverse-charge`), read here and
 * handed to the repository's SQL. Writes nothing.
 */
final class ForeignWithoutVatCheck implements OpenWorkCheckInterface
{
    /** How many invoices are named one by one before the rest is counted. */
    public const LIST_LIMIT = 10;

    /** The link target of a finding — the Kreditorenjournal (financial names no module; the sentence says where). */
    private const AREA = 'creditor-journal';

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        $em = DI::getUnifiedEntityManager();
        /** @var CreditorInvoiceRepository $invoices */
        $invoices = $em->getRepository(CreditorInvoice::class);
        $found    = $invoices->foreignWithoutVatBetween($from, $to, self::reverseChargeCodes($em->getRepository(TaxCode::class)->findAll()), self::LIST_LIMIT);

        foreach ($found['rows'] as $row) {
            yield Finding::warning(self::sentence($row), self::AREA);
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::warning('… und ' . $rest . ' weitere Kreditorenrechnungen aus dem Ausland mit einer Zeile «ohne MWST» in dieser Periode — Bezugsteuer prüfen (Kreditorenjournal, verbuchte Rechnungen).', self::AREA);
        }
    }

    /**
     * «Kreditorenrechnung 4711 · Muster Ltd, bezahlt am 15.08.2026: in USD, Adresse im Ausland, eine
     * Zeile «ohne MWST» — prüfen, ob Bezugsteuer geschuldet ist.»
     *
     * @param array{id: int, number: string, paid_on: string, currency: string, creditor: string, abroad: bool} $row
     */
    private static function sentence(array $row): string
    {
        $name    = 'Kreditorenrechnung ' . ($row['number'] !== '' ? $row['number'] : '(ohne Nr.) ' . $row['id']) . ($row['creditor'] !== '' ? ' · ' . $row['creditor'] : '');
        $reasons = array_values(array_filter([
            $row['currency'] !== '' ? 'in ' . $row['currency'] : '',
            $row['abroad'] ? 'Adresse im Ausland' : '',
        ]));

        return $name . ', bezahlt am ' . (new \DateTimeImmutable($row['paid_on']))->format('d.m.Y') . ': ' . implode(', ', $reasons)
            . ', eine Zeile «ohne MWST» — prüfen, ob Bezugsteuer geschuldet ist.';
    }

    /**
     * The codes of category reverse charge — active or not (a posted line keeps a code deactivated since).
     *
     * @param list<TaxCode> $codes
     * @return list<string>
     */
    private static function reverseChargeCodes(array $codes): array
    {
        return array_values(array_map(
            static fn(TaxCode $code): string => $code->getCode(),
            array_filter($codes, static fn(TaxCode $code): bool => $code->category() === TaxCategory::ReverseCharge)
        ));
    }
}
