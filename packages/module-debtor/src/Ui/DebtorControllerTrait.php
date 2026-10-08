<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Debtor\Entities\Invoice,
    Z77\Module\Debtor\Repositories\InvoiceRepository,
    Z77\Module\Debtor\Services\DebtorCurrency,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money
;

/**
 * Debitoren — the OPEN-ITEM list (2026-10-07, owner: «analysiere das wdv-630
 * Debitoren-Anzeige … jetzt sind Stammdaten mit Einträgen vermischt, man
 * sieht keinen Betrag und Verfall»). Built after wdv-630's
 * `order/DebtorController::listAction`: one row per FINAL payable document
 * (invoice, fee) with what was invoiced, what settled it, what is open, the
 * invoice date, the due date and the customer number, the open total in the
 * header. The debtor MASTER DATA (profile, payment terms, dunning block,
 * account settings) lives on its own screen under Stammdaten › Aufträge ›
 * Debitoren ({@see DebtorProfileControllerTrait}).
 *
 * A STANDARD LIST (listing.md, {@see OpenItemListing}): the columns sort and
 * search in the database, 50 per page, the list a fetch region. Mounted by a
 * thin host controller in the backend (ADR-018 pattern). Host side: `use
 * DebtorControllerTrait` + a one-line layout config delegating to
 * {@see DebtorLayout::config()}.
 *
 * Three views (open — the default —, overdue, all) in the toolbar. A row's
 * state icon opens the document detail as a window (ADR-047), «Zahlung»
 * opens the payment form of the document screen — the list itself writes
 * nothing. No JavaScript of its own. The using class MUST provide (via its
 * host base): `html()`, `em()`, `$layoutManager`.
 */
trait DebtorControllerTrait
{
    private const OPEN_ITEM_NS = 'Z77\\Module\\Debtor';

    private const OPEN_ITEM_VIEWS = ['open' => 'Offen', 'overdue' => 'Überfällig', 'all' => 'Alle'];

    /** URL root of THIS mount — the list's own links are built from it. */
    protected function debtorListBase(): string
    {
        return '/backend/finance/debtor';
    }

    /** URL root of the document screen the rows open (detail, payment). */
    protected function debtorDocumentBase(): string
    {
        return '/backend/finance/invoice';
    }

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $currency   = DebtorCurrency::base();
        $definition = OpenItemListing::definition($currency);
        $state      = $definition->readRequest($request);
        $today      = new \DateTimeImmutable('today');
        $search     = OpenItemListing::search($state, $today);
        /** @var InvoiceRepository $invoices */
        $invoices = $this->em()->getRepository(Invoice::class);
        $totals   = $invoices->openItemTotals($search);
        $paging   = $state->paging($totals['count']);

        $response = $this->html([
            'items'        => $totals['count'] === 0 ? [] : $invoices->openItems($search, $paging->offset(), $paging->pageSize),
            'definition'   => $definition,
            'state'        => $state,
            'paging'       => $paging,
            'today'        => $today,
            'openTotal'    => Money::fromDecimal($totals['open'], $currency),
            'overdueTotal' => Money::fromDecimal($totals['overdue'], $currency),
            'openCount'    => $totals['openCount'],
            'overdueCount' => $totals['overdueCount'],
            'views'        => self::OPEN_ITEM_VIEWS,
            'fmt'          => static fn(?Money $m) => AmountFormat::of($m),
            'actionBase'   => $this->debtorListBase(),
            'documentBase' => $this->debtorDocumentBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»): the view tabs act
        // on the list in the work area (ADR-033 rev. 2026-09-28). A FETCH (sort, page, search —
        // core.js «fetch regions») wants the list alone.
        if (!ListDefinition::isFetch($request)) {
            $this->layoutManager->addPartials('toolbar', 'Backend/DebtorController', self::OPEN_ITEM_NS, 'hc2');
        }

        return $response;
    }
}
