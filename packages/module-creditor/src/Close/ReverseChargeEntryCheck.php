<?php

namespace Z77\Module\Creditor\Close;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Posting\ReverseChargeCodes;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * The VAT return's BLOCKING open point for reverse charge (owner O-8, ADR-059 addendum 2026-11-25,
 * point 3 and amendment b; `creditorConfig → openWorkChecks → vat-return` ONLY): «MWST buchen» of a
 * period is blocked while an invoice still in the Kreditorenjournal carries a reverse-charge code,
 * its INVOICE date lies in THAT period and its tax entry is missing — the reverse charge belongs to
 * the period of the invoice date (O-2). The way out is always open: «Journal verbuchen» posts the
 * tax entry, also for an unpaid invoice.
 *
 * It looks ONLY at the period being posted: an invoice whose invoice date lies in a period already
 * posted never blocks — «Journal verbuchen» refuses it with the owner's sentence (the correction
 * path) — so there is no deadlock. A posted invoice is never one (its pair was posted with it).
 *
 * One finding per invoice for the first {@see LIST_LIMIT}, then one that counts the rest. The
 * parameters are financial's: `from` and `to` (`DateTimeImmutable`). Writes nothing.
 */
final class ReverseChargeEntryCheck implements OpenWorkCheckInterface
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
        if ($scope !== 'vat-return') {
            return;
        }
        $em = DI::getUnifiedEntityManager();
        /** @var CreditorInvoiceRepository $invoices */
        $invoices = $em->getRepository(CreditorInvoice::class);
        $found    = $invoices->reverseChargeMissingBetween($from, $to, (new ReverseChargeCodes($em))->codes(), self::LIST_LIMIT);

        foreach ($found['rows'] as $row) {
            yield Finding::blocking(self::sentence($row), self::AREA);
        }
        $rest = $found['count'] - count($found['rows']);
        if ($rest > 0) {
            yield Finding::blocking('… und ' . $rest . ' weitere Kreditorenrechnungen mit Bezugsteuer dieser Periode noch nicht verbucht — im Kreditorenjournal «Journal verbuchen».', self::AREA);
        }
    }

    /**
     * «Rechnung INV-55120 · Pixelsoft Inc., Rechnungsdatum 30.10.2026: Bezugsteuer noch nicht verbucht —
     * im Kreditorenjournal «Journal verbuchen».»
     *
     * @param array{id: int, number: string, invoice_date: string, creditor: string} $row
     */
    public static function sentence(array $row): string
    {
        $name = 'Rechnung ' . ($row['number'] !== '' ? $row['number'] : '(ohne Nr.) ' . $row['id']) . ($row['creditor'] !== '' ? ' · ' . $row['creditor'] : '');

        return $name . ', Rechnungsdatum ' . (new \DateTimeImmutable($row['invoice_date']))->format('d.m.Y')
            . ': Bezugsteuer noch nicht verbucht — im Kreditorenjournal «Journal verbuchen».';
    }
}
