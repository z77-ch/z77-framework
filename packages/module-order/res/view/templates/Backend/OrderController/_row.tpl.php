<?php
/**
 * One order row of the standard list — the single place that renders it (FETCH-ROW-001): the
 * node carries `data-entity="sales-order:<id>"`.
 *
 *   - in the bookable tabs («Termin», «Geliefert») a checkbox of the form `#order-book` (`{id}:{version}`);
 *   - the state icon opens the order WINDOW (ADR-047, `data-window-open`; the origin is the list
 *     region it stands in) — a pencil while the order is editable (no invoice, or one still in the
 *     Faktura-Journal — ADR-054), a lock once its invoice is posted, in a locked phase (closed,
 *     cancelled — D7) or deleted;
 *   - every searchable cell is a `<label for>` of its column's search field;
 *   - Betrag: the gross total the last save stored from debtor's preview (D11), «–» when the
 *     preview refused the order as it stood;
 *   - Rechnung: the booked invoice's number, its own column (searched, sorted — owner 2026-10-22)
 *   - Zahlung («Abgeschlossen» only): the payment state of the order's invoice, asked from debtor
 *     (`PaymentStates` — posted invoices only); an invoice still in the Faktura-Journal shows
 *     «nicht verbucht» (ADR-054: not payable before the posting).
 *
 * @var \Z77\Module\Order\Entities\SalesOrder $order
 * @var \Z77\Module\Order\Entities\OrderStatus|null $status  null = the code is missing from the master data
 * @var \Z77\Module\Debtor\Services\PaymentState|null $payment
 * @var string $view
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
use Z77\Module\Debtor\Services\PaymentState;
use Z77\Module\Order\Ui\OrderListing;
use Z77\Shared\Money\AmountFormat;

$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$id         = (int) $order->getId();
$url        = $actionBase . '/edit?id=' . $id;
// ADR-054 (step 5): editable too while the invoice is in the Faktura-Journal; a deleted order never.
$editable   = !$order->isDeleted() && (($order->getInvoice()?->isInJournal() ?? false) || ($order->getInvoice() === null && !($status?->getPhase()->isLocked() ?? false)));
$stateText  = $editable ? 'Auftrag öffnen — bearbeitbar' : 'Auftrag öffnen — ' . ($order->isDeleted() ? 'gelöscht' : ($order->getInvoice() !== null ? 'Rechnung verbucht, nicht mehr bearbeitbar' : 'abgeschlossen'));
$badges     = [PaymentState::OPEN => 'badge--info', PaymentState::PARTIAL => 'badge--warning', PaymentState::PAID => 'badge--success', PaymentState::CREDITED => 'badge--muted'];
?>
                <div class="be-list__item" data-entity="sales-order:<?= $id ?>">
                    <div class="be-list__row">
                        <?php if (OrderListing::isSelectable($view)): ?>
                        <span class="be-list__cell"><input type="checkbox" name="order[]" form="order-book" value="<?= e($id . ':' . $order->getVersion()) ?>" aria-label="Auftrag <?= e($order->documentNumber()) ?> auswählen"></span>
                        <?php endif; ?>
                        <a class="be-list__cell be-list__state be-list__state--<?= $editable ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" aria-label="<?= e($stateText) ?>" title="<?= e($stateText) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= $editable ? 'edit' : 'lock' ?>"/></svg></a>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_nr')) ?>"><?= e($order->documentNumber()) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_date')) ?>" data-priority="3"><?= e($order->getOrderDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_service')) ?>" data-priority="2"<?= $order->getServiceTo() !== null ? ' title="bis ' . e($order->getServiceTo()->format('d.m.Y')) . '"' : '' ?>><?= $order->getServiceFrom() !== null ? e($order->getServiceFrom()->format('d.m.Y')) . ($order->getServiceTo() !== null ? ' …' : '') : '' ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_name')) ?>"><?= e($order->getContact()->displayName()) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_subject')) ?>" data-priority="2"><?= e($order->getSubject()) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_status')) ?>" data-priority="2"><?= e($status?->getLabel() ?? $order->getStatusCode()) ?></label>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_invoice')) ?>" data-priority="2"><?= $order->getInvoice()?->getNumber() !== null ? e((string) $order->getInvoice()->getNumber()) : '' ?></label>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= $order->getTotalGross() !== null ? e(AmountFormat::of($order->getTotalGross())) : '<span class="be-list__cell--muted" title="Vor dem Fakturieren zu beheben — im Auftrag sichtbar">–</span>' ?></label>
                        <?php if ($view === OrderListing::VIEW_CLOSED): ?>
                        <span class="be-list__cell"><?php if ($payment !== null): ?><span class="badge <?= e($badges[$payment->status] ?? 'badge--muted') ?>"><?= e($payment->label()) ?></span><?php elseif ($order->getInvoice()?->isInJournal()): ?><span class="badge badge--warning" title="Rechnung fakturiert, im Faktura-Journal — noch nicht verbucht, noch nicht zahlbar">nicht verbucht</span><?php endif; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
