<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Repositories\ReceiptRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The legal retention of a POSTED receipt's photo (owner decision 2026-11-15): Swiss business
 * records are kept 10 years (OR 958f, MWSTG Art. 70), counted from the END of the business year in
 * which they arose. Once a receipt is posted, its photo in the DMS gets `retentionUntil` = the end of
 * the fiscal year (module-financial) that covers the RECEIPT DATE — the date of its journal entry —
 * plus {@see YEARS} years; without a fiscal year (a posted receipt always had one, the ledger refuses
 * otherwise — only a deleted year leaves none) the calendar year: 31.12. + 10. The DMS then refuses
 * the purge and an overwrite until that day (documents.md); a captured receipt's photo has no deadline.
 *
 * Written through the module's own handle {@see \Z77\Module\Dms\Services\ModuleDrive::retainUntil()}
 * (server-side, only extends, idempotent). The photos are FILE records, the receipts DATABASE rows —
 * no common unit of work: {@see apply()} runs AFTER «Journal verbuchen» committed (a rolled-back run
 * sets nothing) and covers EVERY posted receipt whose photo lacks the deadline, so a write that failed
 * after a commit, and the receipts posted before this rule existed, are healed by the next run or by
 * opening «Verbuchte Journale» (the backfill — there is no migration: the documents are not in the
 * database).
 */
final class ReceiptRetention
{
    /** Years a posted receipt's photo is kept after the end of its fiscal year (OR 958f). */
    public const YEARS = 10;

    private ?DocumentService $documents;

    /** @param DocumentService|null $documents null = `DocumentService::create()` at the first write */
    public function __construct(private readonly UnifiedEntityManager $em, ?DocumentService $documents = null)
    {
        $this->documents = $documents;
    }

    /**
     * The deadline for a receipt dated $receiptDate: the end of $year (the fiscal year covering the
     * date) or, without one, 31.12. of the date's calendar year — plus {@see YEARS} years. A year that
     * ends on 29.02. ends on 28.02. ten years later.
     */
    public static function until(\DateTimeImmutable $receiptDate, ?FiscalYear $year): string
    {
        $end    = $year?->getEndDate() ?? new \DateTimeImmutable($receiptDate->format('Y') . '-12-31');
        $target = (int) $end->format('Y') + self::YEARS;
        $month  = (int) $end->format('n');
        $day    = min((int) $end->format('j'), (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $target, $month)))->format('t'));

        return sprintf('%04d-%02d-%02d', $target, $month, $day);
    }

    /**
     * Give every posted receipt's photo its deadline — idempotent (the DMS never shortens one and
     * writes nothing that is already kept as long). Never inside a Doctrine unit of work: the file
     * write is not part of it.
     *
     * @return array{retained: int, skipped: list<int>} photos written now; photo ids the DMS did not
     *         write (gone, or moved out of the «Belege» partition)
     */
    public function apply(): array
    {
        /** @var ReceiptRepository $receipts */
        $receipts = $this->em->getRepository(Receipt::class);
        $dates    = $receipts->postedPhotoDates();
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
            $until[$documentId] = self::until($day, $match);
        }

        return ($this->documents ??= DocumentService::create())
            ->forModule(ReceiptCaptureService::MODULE_KEY, ReceiptCaptureService::PARTITION_NAME)
            ->retainUntil($until);
    }
}
