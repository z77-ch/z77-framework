<?php

namespace Z77\Module\Receipt\Ui;

use Z77\Core\DI;
use Z77\Module\Financial\Ledger\EntryDocument;
use Z77\Module\Financial\Ledger\EntryDocumentProviderInterface;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Repositories\ReceiptRepository;

/**
 * Receipt's answer to the finance journal's «which document stands behind this entry»
 * (`receiptConfig → entryDocuments`, owner 2026-11-20): an entry of origin `receipt` / the receipt
 * id ({@see \Z77\Module\Receipt\Services\ReceiptPostingBuilder}) opens the receipt's PHOTO — a
 * reversal inherits the origin, so it opens the same photo. The address is the DMS's read-gated
 * preview from {@see ReceiptPhoto} (never built here); a photo the session may not read, or one
 * that is gone (trash, purged), is left out — no icon.
 *
 * One SQL query for the page (receipt ids → document ids), then the DMS gate per photo.
 */
final class ReceiptEntryDocuments implements EntryDocumentProviderInterface
{
    public function documentsFor(array $entries): array
    {
        $receiptOf = [];
        foreach ($entries as $entry) {
            if ($entry['sourceType'] === Receipt::SOURCE_TYPE && ctype_digit($entry['sourceRef'])) {
                $receiptOf[$entry['id']] = (int) $entry['sourceRef'];
            }
        }
        if ($receiptOf === []) {
            return [];
        }
        /** @var ReceiptRepository $receipts */
        $receipts  = DI::getUnifiedEntityManager()->getRepository(Receipt::class);
        $documents = $receipts->documentIdsOf(array_values($receiptOf));
        $photos    = ReceiptPhoto::of(array_values($documents));
        $out       = [];
        foreach ($receiptOf as $entryId => $receiptId) {
            $photo = $photos[$documents[$receiptId] ?? 0] ?? null;
            if ($photo !== null && $photo['state'] === 'doc' && $photo['previewUrl'] !== null) {
                $out[$entryId] = new EntryDocument($photo['previewUrl'], $photo['isImage'] ? 'Belegfoto' : 'Beleg');
            }
        }

        return $out;
    }
}
