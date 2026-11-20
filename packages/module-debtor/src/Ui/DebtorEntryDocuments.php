<?php

namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Financial\Ledger\EntryDocument;
use Z77\Module\Financial\Ledger\EntryDocumentProviderInterface;

/**
 * Debtor's answer to the finance journal's «which document stands behind this entry»
 * (`debtorConfig → entryDocuments`, owner 2026-11-20): an entry of origin `invoice` /
 * `credit-note` / `fee` and the document NUMBER ({@see \Z77\Module\Debtor\Invoicing\PostingBuilder})
 * opens the document's PDF through the invoice screen's own action `pdf` — the ARCHIVED file of
 * the issued version (ADR-054 decision 25), never re-rendered, never a public path. Shown only
 * when the session may reach that action (`AuthService::canReach()`, the host's access config —
 * the dispatcher enforces it on the click as well). A payment's entry (origin `payment`) has no
 * document here.
 *
 * One SQL query for the page (numbers → ids).
 */
final class DebtorEntryDocuments implements EntryDocumentProviderInterface
{
    /** The invoice screen's mount (`InvoiceControllerTrait::invoiceListBase()`). */
    private const INVOICE_BASE = '/backend/finance/invoice';

    public function documentsFor(array $entries): array
    {
        $numberOf = [];
        $kindOf   = [];
        foreach ($entries as $entry) {
            $kind = InvoiceKind::tryFrom($entry['sourceType']);
            if ($kind !== null && ctype_digit($entry['sourceRef'])) {
                $numberOf[$entry['id']] = (int) $entry['sourceRef'];
                $kindOf[$entry['id']]   = $kind;
            }
        }
        if ($numberOf === []) {
            return [];
        }
        $auth     = DI::getAuthService();
        $segments = explode('/', trim(self::INVOICE_BASE, '/'));
        if (!$auth->canReach($auth->getCurrentUser(), $segments[0], $segments[1], $segments[2], 'pdf')) {
            return [];
        }
        /** @var InvoiceRepository $invoices */
        $invoices = DI::getUnifiedEntityManager()->getRepository(Invoice::class);
        $ids      = $invoices->idsByNumbers(array_values($numberOf));
        $out      = [];
        foreach ($numberOf as $entryId => $number) {
            if (isset($ids[$number])) {
                $out[$entryId] = new EntryDocument(self::INVOICE_BASE . '/pdf?id=' . $ids[$number], $kindOf[$entryId]->label() . ' (PDF)');
            }
        }

        return $out;
    }
}
