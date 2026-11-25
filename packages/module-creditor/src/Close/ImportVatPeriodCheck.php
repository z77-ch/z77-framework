<?php

namespace Z77\Module\Creditor\Close;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

/**
 * The import-VAT warning of the VAT return (owner O-5 (b), ADR-041 addendum 2026-11-25 point 8):
 * at «MWST buchen» of the PAYMENT period, a POSTED creditor invoice paid in it with a «nur Steuer»
 * line (import VAT) whose invoice date lies in ANOTHER VAT period — the same sentence the run
 * «Journal verbuchen» showed as a grey line ({@see ImportVatPeriods}), again, so it is not
 * overlooked when the creditor posting lies back. A WARNING per invoice for the first
 * {@see LIST_LIMIT}, then one that counts the rest; confirmed with the one tick. No extra entry, no
 * block.
 *
 * Registered in `creditorConfig → openWorkChecks` under `vat-return` only; parameters `from`, `to`
 * (`DateTimeImmutable`) — the period of the return. The sentences are part of the confirmed-warnings
 * fingerprint of a posted return — keep them stable. Writes nothing.
 */
final class ImportVatPeriodCheck implements OpenWorkCheckInterface
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
        $periods  = new ImportVatPeriods($em);
        $found    = [];
        foreach ($invoices->postedTaxOnlyPaidBetween($from, $to) as $invoice) {
            $sentence = $periods->sentence($invoice);
            if ($sentence !== null) {
                $found[] = $sentence;
            }
        }
        foreach (array_slice($found, 0, self::LIST_LIMIT) as $sentence) {
            yield Finding::warning($sentence, self::AREA);
        }
        $rest = count($found) - self::LIST_LIMIT;
        if ($rest > 0) {
            yield Finding::warning('… und ' . $rest . ' weitere Kreditorenrechnungen mit Einfuhrsteuer, deren Rechnungsdatum in einer anderen MWST-Periode liegt — prüfen (Kreditorenjournal, verbuchte Rechnungen).', self::AREA);
        }
    }
}
