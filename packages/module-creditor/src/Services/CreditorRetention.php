<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Receipt\Services\ReceiptRetention;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The legal retention of a POSTED creditor invoice's PDF (owner answer 8, 2026-11-15; OR 958f,
 * MWSTG Art. 70): once posted, the PDF in the DMS gets `retentionUntil` = the end of the fiscal year
 * covering the PAYMENT date — the date of its journal entry — plus 10 years; the DMS then refuses
 * the purge and an overwrite until that day. The deadline rule is module-receipt's
 * {@see ReceiptRetention::until()} (one rule, the same law; creditor requires receipt).
 *
 * The PDFs are FILE records, the invoices DATABASE rows — no common unit of work: {@see apply()} runs
 * AFTER «Journal verbuchen» committed and covers EVERY posted invoice, so a write that failed after
 * a commit is healed by the next run or by opening «Verbuchte Journale» (the backfill). Written
 * through the module's own handle `ModuleDrive::retainUntil()` (only extends, idempotent).
 */
final class CreditorRetention
{
    private ?DocumentService $documents;

    /** @param DocumentService|null $documents null = `DocumentService::create()` at the first write */
    public function __construct(private readonly UnifiedEntityManager $em, ?DocumentService $documents = null)
    {
        $this->documents = $documents;
    }

    /**
     * Give every posted invoice's PDF its deadline — idempotent. Never inside a Doctrine unit of work.
     *
     * @return array{retained: int, skipped: list<int>} PDFs written now; document ids the DMS did not write
     */
    public function apply(): array
    {
        /** @var CreditorInvoiceRepository $invoices */
        $invoices = $this->em->getRepository(CreditorInvoice::class);
        $dates    = $invoices->postedDocumentDates();
        if ($dates === []) {
            return ['retained' => 0, 'skipped' => []];
        }
        $years = $this->em->getRepository(FiscalYear::class)->findAll();
        $until = [];
        foreach ($dates as $documentId => $date) {
            $day   = new \DateTimeImmutable($date);
            $match = null;
            foreach ($years as $year) {
                if ($year->covers($day)) {
                    $match = $year;
                    break;
                }
            }
            $until[$documentId] = ReceiptRetention::until($day, $match);
        }

        return ($this->documents ??= DocumentService::create())
            ->forModule(CreditorIntakeService::MODULE_KEY, CreditorIntakeService::PARTITION_NAME)
            ->retainUntil($until);
    }
}
