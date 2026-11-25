<?php

namespace Z77\Module\Financial\Ui;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Ledger\EntryDocument;
use Z77\Module\Financial\Ledger\EntryDocumentProviderInterface;
use Z77\Module\Financial\Pdf\VatReturnArchive;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\VatReturn\VatReturnService;

/**
 * Financial's own answer to the journal's «which document stands behind this entry»
 * (`financialConfig → entryDocuments`, owner decision 11 of 2026-11-25): the settlement entry of a
 * posted VAT return — origin `vat-return`, source reference `{from}/{to}/r{revision}`
 * (`VatReturnPostingService::sourceRef()`) — opens the return's ARCHIVED voucher; its reversal
 * inherits the origin, so it opens the same voucher (the lifted revision's, byte for byte).
 *
 * The file is verified against the row's `voucher_sha256` ({@see VatReturnArchive::bytes()}): a
 * voucher that is missing or altered gets no icon. One query per settled period on the page (its
 * revisions), then one read per voucher.
 */
final class VatReturnEntryDocuments implements EntryDocumentProviderInterface
{
    public function __construct(private readonly ?VatReturnArchive $archive = null)
    {
    }

    public function documentsFor(array $entries): array
    {
        $wanted = [];
        foreach ($entries as $entry) {
            if ($entry['sourceType'] === VatReturnService::SOURCE_TYPE
                && preg_match('#^(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})/r(\d+)$#', (string) $entry['sourceRef'], $m) === 1) {
                $wanted[$entry['id']] = [$m[1], $m[2], (int) $m[3]];
            }
        }
        if ($wanted === []) {
            return [];
        }
        /** @var VatReturnRepository $returns */
        $returns = DI::getUnifiedEntityManager()->getRepository(VatReturn::class);
        $byKey   = [];
        foreach (array_unique(array_column($wanted, 0)) as $from) {
            foreach ($returns->ordinaryRevisions(new \DateTimeImmutable($from)) as $return) {
                $byKey[$return->getPeriodFrom()->format('Y-m-d') . '/' . $return->getPeriodTo()->format('Y-m-d') . '/' . $return->getRevision()] = $return;
            }
        }
        $archive = $this->archive ?? new VatReturnArchive();
        $checked = [];
        $out     = [];
        foreach ($wanted as $entryId => [$from, $to, $revision]) {
            $return = $byKey[$from . '/' . $to . '/' . $revision] ?? null;
            if ($return === null) {
                continue;
            }
            $id = (int) $return->getId();
            $checked[$id] ??= $archive->bytes($return->getVoucherPath(), $return->getVoucherSha256()) !== null;
            if ($checked[$id]) {
                $out[$entryId] = new EntryDocument(VatReturnLayout::BASE . '/voucher?id=' . $id, 'MWST-Beleg');
            }
        }

        return $out;
    }
}
