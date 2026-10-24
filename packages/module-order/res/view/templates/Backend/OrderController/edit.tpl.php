<?php
/**
 * The ORDER WINDOW (plan §9.4; the owner's board on the developer canvas) — a new order or an
 * existing one, ONE form, ONE save. A plain form, no JavaScript of its own (Rule 7): the
 * positions use the shared core.js blocks «rows» (add, remove, drag & drop, indent), «autosize»
 * and «line sums» — without the script «+ Artikel», «+ Textzeile» and × are submits that come
 * back with the changed rows; the article number is typed (core.js «fill» completes the row) or chosen in
 * article's searching picker (`article-pick`, a window over this one — the row's magnifier fills
 * THAT row, «+ Artikel» a new one; owner 2026-10-19); `csrf_token` is the page-mode field (`#[Csrf]`), an existing order also
 * carries the entity token and the VERSION it was rendered from (the optimistic lock of
 * `OrderService::update()`).
 *
 * Fetched, a WINDOW (ADR-047): mask `order-edit`, record `sales-order:<id>` (a new order: mask
 * only), the title in the title bar, `_origin` carried back. The action row (ADR-049) is the
 * shared `partials/modalActions`, FIRST in the form: Speichern · the STATUS as a select saved with
 * «Speichern» (owner 2026-10-20; a locked order: a pill that opens «Status ändern …») · «Drucken ▾» — by the PHASE
 * (ADR-043 addendum 2026-10-15): Offerte / Auftragsbestätigung (PDF, new tab), «Auftragsbestätigung
 * …» (a quote: it confirms the order) or «Lieferschein …», Rechnungsvorschau (PDF) · Buchungsdruck …
 * (only a bookable phase without an invoice — a plain link that leaves the window, the
 * DEBTOR-WIN-002 pattern) · Schliessen · «N Fehler». A cancelled order prints nothing.
 *
 * LAYOUT = the owner's draft (2026-10-16, `order.md` «Order window redesign»; ADR-051): the form
 * is the window ground, its parts are CARDS (`.be-form--blocks`, `_forms.scss`). A head row of
 * three blocks — Kunde (customer, billing and delivery address) · Betreff / Notiz · dates and
 * payment —, the positions as one block with the totals at its foot, «Verlauf und Belege»
 * collapsed at the end (troubleshooting). No radio tabs any more.
 *
 * Read-only (D7: an invoice is booked, or the phase is closed / cancelled): the head and the
 * positions sit in a disabled fieldset, no «Speichern», the reason on top. No explanations in the
 * form (ADR-048) — the help (`edit.help`) has a section per field.
 *
 * @var \Z77\Module\Order\Ui\OrderForm $form
 * @var \Z77\Module\Order\Entities\SalesOrder|null $order  null = a new order
 * @var int|null $version
 * @var \Z77\Module\Order\Entities\OrderStatus|null $status
 * @var array<string, string> $statusLabels  code → label
 * @var list<\Z77\Module\Order\Entities\OrderStatus> $startable  a new order's choice
 * @var list<\Z77\Module\Order\Entities\OrderStatus> $statusChoices  an editable order's status select: the current one + the allowed targets ([] = locked: the link «Status ändern …»)
 * @var string|null $editRefusal  why the order is read-only (D7), null = editable
 * @var bool $needReason  the stock seam asked for a reason (consumed stock would come back)
 * @var string $entityCsrf
 * @var string $customerField  debtor's `CustomerPicker::fieldHtml()` — the customer + «Kunde wählen …» (contacts without a profile choosable, D9)
 * @var string $customerTitle  block (1)'s title — debtor's shared `Backend/CustomerPicker/_customerTitle` (`#order-customer-title`): «Kunde», the customer number, the phone
 * @var string $termsSelect  block (3)'s «Zahlungskonditionen» — debtor's shared `_terms` (`#order-terms`, PaymentTerms; owner 2026-10-18)
 * @var string $debtorEdit  «Debitor anpassen» — debtor's shared `_debtorEdit` (`#order-debtor-edit`), the profile dialog of the customer
 * @var array<string, string> $ships  delivery-address choice → label
 * @var array<string, string> $bills  billing-address choice → label (2026-10-17)
 * @var bool $shipAdjusted  the stored delivery snapshot is none of the customer's addresses (badge «angepasst»)
 * @var bool $billAdjusted  the same for the billing snapshot
 * @var string $dirtyBase  the posted `_dirty_base` echoed back (core.js «dirty forms»), '' on a GET
 * @var list<string> $billShown  the billing card's address lines for the CURRENT choice — not the
 *                               preview's, which fails while the order has no lines (2026-10-18)
 * @var list<string> $shipShown  the delivery card's, empty for «wie Rechnungsadresse»
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
 * @var array<string, string> $taxRates  code → the rate shown («8.1 %») on the SERVICE date («Leistung von», else the order date)
 * @var array<string, list<array{0: string, 1: string}>> $rateSchedule  code → [[valid from, rate], …] for core.js «dated rates»
 * @var bool $liableToVat  false = the order is computed WITHOUT VAT (the mandator is not liable, owner 2026-10-21 —
 *                         `OrderService::chargesVat()`): no MWST column, no rates for core.js, no VAT rows in the totals
 * @var array<string, array{name: string, unit: string, taxCode: string, account: string, offered: bool}> $articles  article number → what it brings
 * @var \Z77\Module\Debtor\Entities\Invoice|null $preview  debtor's preview of the STORED order (D11)
 * @var string $currency  the order's currency (the totals' before there is a preview)
 * @var list<string> $previewProblems  what the booking print would refuse now
 * @var list<\Z77\Module\Order\Entities\SalesOrderStatusChange> $history
 * @var array<string, string> $triggers  trigger → German
 * @var \Z77\Module\Debtor\Entities\Invoice|null $invoice
 * @var \Z77\Module\Debtor\Services\PaymentState|null $payment
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $creditNotes
 * @var string $invoiceBase  debtor's document screens
 * @var string $orderTitle  «Offerte» / «Auftragsbestätigung» (by the phase)
 * @var bool $canBook
 * @var bool $canReinvoice  the invoice is in the Faktura-Journal — «Neu fakturieren …» (ADR-054 decision 12)
 * @var string|null $deleteRefusal  why «Auftrag löschen …» is not offered; null = offered (ADR-054 decision 14)
 * @var \Z77\Module\Order\Entities\OrderPhase|null $phase  the order's phase, null = a new order
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 * @var string $windowWidth
 */
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Order\Ui\OrderForm;
use Z77\Module\Vat\Entities\TaxRate;

$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$window     = !empty($window);
$isNew      = $order === null;
$editable   = $isNew || $editRefusal === null;
$id         = $isNew ? 0 : (int) $order->getId();
$key        = $isNew ? 'new' : (string) $id;
// «A-6 · Betreff» — the number and what the order is about (owner 2026-10-17); without a subject the customer.
$title      = $isNew ? 'Neuer Auftrag' : 'A-' . $order->documentNumber() . ' · ' . ($order->getSubject() !== '' ? $order->getSubject() : $order->getContact()->displayName());
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . $id);
$invalidIds = $form->invalidIds();
$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
$invalid    = static fn(string $message): string => $message !== '' ? 'true' : 'false';
$winAttr    = $window
    ? ' data-window="order-edit"' . ($isNew ? '' : ' data-window-entity="sales-order:' . $id . '"')
        . ' data-window-title="' . e($title) . '"' . (!empty($windowWidth) ? ' data-window-width="' . e($windowWidth) . '"' : '')
    : '';

// The action row's further controls (ADR-049): the status pill, the print menu (PDFs in a new tab,
// the confirm windows in THIS window — `data-window-link`), the booking print a plain link that
// leaves the window (DEBTOR-WIN-002 pattern). What the PHASE allows (ADR-043 addendum 2026-10-15): a
// quote prints «Offerte» and offers the order confirmation that confirms it; from `confirmed` on the
// confirmation is a plain PDF and the delivery note is offered; a cancelled order prints nothing.
$extra     = '';
$statusChoices = $statusChoices ?? [];
$cancelled = $phase === \Z77\Module\Order\Entities\OrderPhase::Cancelled;
// The status is a FIELD in the action row (owner 2026-10-18, `.be-status-field`): «STATUS», a dot
// in the tone of its phase, the name. Left group: save + status; a spacer; right group: what leads
// out of the order (print, booking print); a quiet «Schliessen» at the end.
$tone = static fn(?\Z77\Module\Order\Entities\OrderPhase $p): string => match ($p) {
    \Z77\Module\Order\Entities\OrderPhase::Confirmed => 'accent',
    \Z77\Module\Order\Entities\OrderPhase::Delivered => 'warn',
    \Z77\Module\Order\Entities\OrderPhase::Closed    => 'good',
    \Z77\Module\Order\Entities\OrderPhase::Cancelled => 'danger',
    default                                          => 'muted',
};
if ($isNew) {
    // A new order chooses its start status right beside «Erstellen» (owner 2026-10-17: the status
    // belongs in the action row, not among the dates) — the select inside the status field.
    $options   = '';
    $startTone = 'muted';
    foreach ($startable as $row) {
        $chosen = $row->getCode() === $form->header('status');
        $startTone = $chosen ? $tone($row->getPhase()) : $startTone;
        $options .= '<option value="' . e($row->getCode()) . '"' . ($chosen ? ' selected' : '') . '>' . e($row->getLabel()) . '</option>';
    }
    $extra .= '<label class="be-status-field be-status-field--' . $startTone . '" for="order-status"><span class="be-status-field__label">Status</span><span class="be-status-field__dot"></span>'
        . '<select id="order-status" name="status" aria-invalid="' . $invalid($form->error('status')) . '"'
        . ($form->error('status') !== '' ? ' title="' . e($form->error('status')) . '"' : '') . '>' . $options . '</select></label>'
        . '<span class="z77-form-actions__spacer"></span>';
} elseif ($statusChoices !== []) {
    // An editable order: the status is a select too (owner 2026-10-20) — the current status and the
    // targets the transition rule allows; it moves with «Speichern» (OrderService::update(), one
    // unit of work) and, like any field, makes the save call (core.js «dirty forms»).
    $options = '';
    foreach ($statusChoices as $row) {
        $chosen   = $row->getCode() === $form->header('status');
        $options .= '<option value="' . e($row->getCode()) . '"' . ($chosen ? ' selected' : '') . '>' . e($row->getLabel()) . '</option>';
    }
    $extra .= '<label class="be-status-field be-status-field--' . $tone($phase) . '" for="order-status" title="Status — wird mit «Speichern» gesetzt"><span class="be-status-field__label">Status</span><span class="be-status-field__dot"></span>'
        . '<select id="order-status" name="status" data-help-key="status" aria-invalid="' . $invalid($form->error('status')) . '"'
        . ($form->error('status') !== '' ? ' title="' . e($form->error('status')) . '"' : '') . '>' . $options . '</select></label>'
        . '<span class="z77-form-actions__spacer"></span>';
} elseif ($invoice !== null) {
    // An order with an invoice: the status is SHOWN only (owner 2026-10-20) — posted, a correction is a credit
    // note; still in the Faktura-Journal (ADR-054), the order is edited and «Neu fakturieren …» re-issues it.
    $extra .= '<span class="be-status-field be-status-field--' . $tone($phase) . '" title="Rechnung fakturiert — der Status bleibt">'
        . '<span class="be-status-field__label">Status</span><span class="be-status-field__dot"></span>'
        . '<span class="be-status-field__name">' . e($status?->getLabel() ?? $order->getStatusCode()) . '</span></span>'
        . '<span class="z77-form-actions__spacer"></span>';
} else {
    // A locked order without an invoice (closed, cancelled): no «Speichern» — «Status ändern …» is
    // the way back to an editable status.
    $statusLabel = $status?->getLabel() ?? $order->getStatusCode();
    $extra .= '<a class="be-status-field be-status-field--' . $tone($phase) . '" href="' . e($actionBase . '/status?id=' . $id) . '" data-window-link title="Status ändern …">'
        . '<span class="be-status-field__label">Status</span><span class="be-status-field__dot"></span>'
        . '<span class="be-status-field__name">' . e($statusLabel) . '</span><span class="be-status-field__caret" aria-hidden="true">▼</span></a>'
        . '<span class="z77-form-actions__spacer"></span>';
}
if (!$isNew) {
    $prints = [];
    if (!$cancelled) {
        $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/pdf?id=' . $id) . '" target="_blank" rel="noopener" title="' . e($orderTitle) . ' als PDF (neuer Tab)">' . e($orderTitle) . '</a>';
        if ($phase?->isQuote() ?? false) {
            $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/confirm-confirmation?id=' . $id) . '" data-window-link title="Auftragsbestätigung drucken — der Auftrag wird bestätigt">Auftragsbestätigung …</a>';
        } else {
            $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/confirm-delivery-note?id=' . $id) . '" data-window-link>Lieferschein …</a>';
        }
        if ($invoice === null) {
            $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/invoice-preview?id=' . $id) . '" target="_blank" rel="noopener" title="Die Rechnung, wie das Fakturieren sie heute erstellen würde — ohne Nummer und ohne Zahlteil">Rechnungsvorschau</a>';
        }
    }
    if ($prints !== []) {
        // A CSS-only menu (`<details>`, the `.be-shell-add--select` anatomy) — no script (Rule 7).
        $extra .= '<details class="be-shell-add be-shell-add--select"><summary class="be-btn be-btn--ghost">Drucken ▾</summary>'
            . '<div class="be-shell-add__panel" role="menu" aria-label="Drucken">' . implode('', $prints) . '</div></details>';
    }
    // Total 0 (owner 2026-10-20): no invoice, no «Fakturieren …» — the hint stands under the action row.
    if ($canBook && empty($zeroTotal)) {
        $extra .= '<a class="be-btn be-btn--confirm-open" href="' . e($actionBase . '/confirm-book?' . http_build_query(['order' => [$id . ':' . $order->getVersion()]])) . '" title="Rechnung erstellen, nummerieren und als PDF drucken — ins Faktura-Journal, noch nicht verbucht">Fakturieren …</a>';
    }
    // «Neu fakturieren …» (ADR-054 decision 12): the invoice in the Faktura-Journal issued again from the order — a
    // PAGE like «Fakturieren …» (it leaves the window); green when the invoice is flagged (the journal is blocked).
    if (!empty($canReinvoice)) {
        $extra .= '<a class="be-btn ' . ($invoice->getReissueNeeded() !== null ? 'be-btn--confirm-open' : 'be-btn--ghost') . '" href="' . e($actionBase . '/confirm-reinvoice?id=' . $id) . '" title="Die Rechnung aus dem Auftrag neu fakturieren — gleiche Nummer (neue Version) oder Ersatz mit neuer Nummer">Neu fakturieren …</a>';
    }
    // «Auftrag löschen …» (ADR-054 decision 14): while no posted invoice exists — the dialog in this window.
    if (($deleteRefusal ?? 'x') === null) {
        $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/confirm-delete?id=' . $id) . '" data-window-link title="Auftrag löschen — er bleibt mit Nummer und Grund unter «Gelöscht», das Lager wird zurückgebucht">Löschen …</a>';
    }
}
// The positions' columns: handle (⋮⋮ grip + ⇥ indent) · Pos. · Art · Art.-Nr. · Text · Menge ·
// Einheit · Preis · Rabatt % · MWST · Betrag · ×. A text row's text spans Art.-Nr. … Betrag
// (`--be-rows-span`). No Konto column (owner 2026-10-18: every priced line is an article, D3).
// Without VAT (owner 2026-10-21) the MWST column goes: one track less, a text row spans one less.
$liableToVat = $liableToVat ?? true;
$cols = '3.6rem 2.4rem 5.2rem 8.8rem minmax(12rem, 3fr) 4.5rem 4.5rem 6.5rem 4.5rem' . ($liableToVat ? ' 4.5rem' : '') . ' 7rem 1.8rem';
$span = $liableToVat ? 8 : 7;
// «Artikel wählen …» (owner 2026-10-19): article's picker at this window's root. The row's magnifier
// and «+ Artikel» open it as a window over this one; the context its price needs rides along at
// the click (`data-window-with` — the fields as they stand), the href carries the rendered values
// for a page without the script (a new tab: the order here keeps what was typed).
$pickUrl   = $actionBase . '/article-pick';
$pickWith  = 'order_date service_from price_mode';
$pickState = ['order_date' => $form->header('order_date'), 'service_from' => $form->header('service_from'), 'price_mode' => $form->header('price_mode')];
// The row's amount as typed (quantity × price less the discount) — shown only, the save and the
// totals below decide (D11); '' when a value is not a number yet.
$amount = static function (array $row): string {
    $num = static function (string $v): ?float {
        $v = str_replace(["'", '’', ' ', ','], ['', '', '', '.'], trim($v));
        return is_numeric($v) ? (float) $v : null;
    };
    $qty = $num($row['quantity']);
    $price = $num($row['price']);
    if ($row['type'] === LineType::Text->value || $qty === null || $price === null) {
        return '';
    }
    return number_format($qty * $price * (1 - ($num($row['discount']) ?? 0.0) / 100), 2, '.', "'");
};
// «anpassen» on an address card: a submit like «+ Weitere Zeilen» — the server answers with the
// card's own fields, filled with the address it showed (owner 2026-10-18, no script).
$adjustButton = static fn(string $field): string => $editable && $form->header($field) !== OrderForm::ADDRESS_CUSTOM
    ? '<button type="submit" class="be-form__card-action" name="op" value="adjust-' . $field . '" formnovalidate>anpassen</button>'
    : '';
// The card's fields: contact's `_addressFields` without the link part, under `{field}_addr_`.
$addressFields = fn(string $field): string => $this->partial('Backend/ContactController/_addressFields', [
    'address'  => $form->customAddress($field),
    'prefix'   => $field . '_addr_',
    'withLink' => false,
    'required' => false,
], 'Z77\\Module\\Contact');
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-form--blocks" id="order-form-<?= e($key) ?>" autocomplete="off" novalidate data-dirty="<?= $isNew ? 'dirty' : 'clean' ?>">
        <input type="hidden" name="_dirty_base" value="<?= e($dirtyBase ?? '') ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) ($version ?? $order->getVersion()) ?>">
        <?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'      => $editable ? ($isNew ? 'Erstellen' : 'Speichern') : '',
            'submitAttrs' => ['name' => 'op', 'value' => 'save', 'data-dirty-submit' => ''],
            'extra'       => $extra,
            'cancel'      => $window ? 'Schliessen' : 'Zur Liste',
            'cancelKind'  => 'quiet',
            'cancelHref'  => $window ? '' : $actionBase . '/list',
            'errors'      => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <h2 class="be-list__section-title"><?= e($title) ?></h2>
        <?php endif; ?>

        <?php if ($canBook && !empty($zeroTotal)): ?>
        <div class="be-modal__alert"><?= e(\Z77\Module\Order\Services\OrderService::ZERO_TOTAL_HINT) ?> Kein Fakturieren.</div>
        <?php endif; ?>
        <?php if ($editRefusal !== null): ?>
        <div class="be-modal__alert"><?= e($editRefusal) ?></div>
        <?php endif; ?>
        <?php if ($invoice !== null && $invoice->isInJournal() && !$order->isDeleted()): ?>
        <?php // ADR-054 decisions 9, 12: the invoice is issued, not posted — the order is editable, a deviation blocks the journal. ?>
        <div class="be-modal__alert<?= $invoice->getReissueNeeded() !== null ? ' be-modal__alert--error' : '' ?>" data-order-invoice-journal="<?= $invoice->getReissueNeeded() !== null ? 'flagged' : 'in-step' ?>"><?= e($invoice->documentName()) ?> ist fakturiert, aber noch nicht verbucht (Faktura-Journal).<?= $invoice->getReissueNeeded() !== null ? ' <strong>Rechnung weicht ab:</strong> ' . e($invoice->getReissueNeeded()) . ' — «Neu fakturieren …», sonst bleibt das Faktura-Journal gesperrt.' : ' Eine Änderung am Auftrag sperrt das Faktura-Journal, bis neu fakturiert ist.' ?></div>
        <?php endif; ?>
        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($needReason): ?>
        <div class="be-form__block">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-<?= e($key) ?>-stock_reason">Grund der Rückbuchung</label>
                <textarea id="order-<?= e($key) ?>-stock_reason" name="stock_reason" rows="2" maxlength="255" required aria-invalid="true"></textarea>
            </div>
        </div>
        <?php endif; ?>

        <fieldset class="be-form__fieldset"<?= $editable ? '' : ' disabled' ?>>
        <div class="be-form__blocks order-head">
            <?php // (1) Kunde — the customer, the billing address (what the booking print takes), the delivery address. ?>
            <div class="be-form__block">
                <div class="be-form__block-head">
                    <?php // «Kunde 20417» + phone (debtor's shared `_customerTitle`, `#order-customer-title`) — «Kunde wechseln» replaces it. ?>
                    <?= raw($customerTitle) ?>
                </div>
                <div data-z77-field-wrapper>
                    <?php if ($editable): ?>
                    <?php // debtor's customer field: the name, «Kunde wechseln» opens the searching picker over this window
                          // (CustomerPicker); no «+ Kontakt» here — the picker window offers it (owner 2026-10-17). ?>
                    <?= raw($customerField) ?>
                    <?php else: ?>
                    <?= $this->partial('Backend/CustomerPicker/_fixed', ['name' => $order->getContact()->displayName()], 'Z77\Module\Debtor') ?>
                    <?php endif; ?>
                    <?= raw($fieldError($form->error('contact'))) ?>
                </div>
                <?php // The two address cards: the select in the head, the address of the CURRENT choice below — every
                      // choice rendered, the select switches them (`_addressCases`, owner 2026-10-18). ?>
                <?php foreach (['bill' => ['Rechnung', $bills, $billCases, $billAdjusted],
                                'ship' => ['Lieferung', $ships, $shipCases, $shipAdjusted]] as $field => [$cardLabel, $choices, $cases, $adjusted]): ?>
                <div class="be-form__card" data-z77-field-wrapper data-switch-scope>
                    <div class="be-form__card-head">
                        <label class="be-form__card-label" for="order-<?= $field ?>"><?= e($cardLabel) ?></label>
                        <?= $this->partial('Backend/OrderController/_ship', ['ships' => $choices, 'selected' => $form->header($field), 'invalid' => $form->error($field) !== '', 'field' => $field], 'Z77\Module\Order') ?>
                    </div>
                    <?= $this->partial('Backend/OrderController/_addressCases', [
                        'field'    => $field,
                        'cases'    => $cases,
                        'selected' => $form->header($field),
                        'adjusted' => $adjusted,
                        'fields'   => $form->header($field) === OrderForm::ADDRESS_CUSTOM ? $addressFields($field) : '',
                    ], 'Z77\Module\Order') ?>
                    <?= raw($fieldError($form->error($field))) ?>
                    <?= raw($adjustButton($field)) ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php // (2) What it is about. ?>
            <div class="be-form__block">
                <?php // «Betreffname» right before «Betreff» (owner 2026-10-21) — debtor's shared field with its datalist. ?>
                <?= $this->partial('partials/subjectLabelField', [
                    'id'          => 'order-subject_label',
                    'value'       => $form->header('subject_label'),
                    'suggestions' => $subjectLabels ?? [],
                    'prefill'     => !empty($subjectLabelPrefill),
                    'error'       => $form->error('subject_label'),
                ], 'Z77\\Module\\Debtor') ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="order-subject">Betreff</label>
                    <input type="text" class="be-form__input--lead" id="order-subject" name="subject" value="<?= e($form->header('subject')) ?>" maxlength="<?= \Z77\Module\Order\Entities\SalesOrder::SUBJECT_LENGTH ?>" aria-invalid="<?= $invalid($form->error('subject')) ?>">
                    <?= raw($fieldError($form->error('subject'))) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="order-notes">Notizen (intern)</label>
                    <textarea id="order-notes" name="notes" rows="2" maxlength="<?= \Z77\Module\Order\Entities\SalesOrder::NOTES_LENGTH ?>" aria-invalid="<?= $invalid($form->error('notes')) ?>"><?= e($form->header('notes')) ?></textarea>
                    <?= raw($fieldError($form->error('notes'))) ?>
                </div>
                <?php if (!$isNew): ?>
                <div class="be-form__field">
                    <span class="be-form__card-label">Herkunft</span>
                    <div class="be-form__fact be-form__fact--mono"><span class="badge badge--primary"><?= e($order->getSourceType() === \Z77\Module\Order\Entities\SalesOrder::SOURCE_BACKEND ? 'Backend' : $order->getSourceType()) ?></span> erfasst <?= e($order->getCreatedAt()->format('d.m.Y')) ?>, <?= e($order->getCreatedBy()) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php // (3) When, and how it is paid. ?>
            <div class="be-form__block">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="order-order_date">Auftragsdatum</label>
                    <input type="date" id="order-order_date" name="order_date" value="<?= e($form->header('order_date')) ?>" required aria-invalid="<?= $invalid($form->error('order_date')) ?>">
                    <?= raw($fieldError($form->error('order_date'))) ?>
                </div>
                <div class="be-form__pair">
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="order-service_from">Leistung von</label>
                        <input type="date" id="order-service_from" name="service_from" value="<?= e($form->header('service_from')) ?>" aria-invalid="<?= $invalid($form->error('service_from')) ?>">
                        <?= raw($fieldError($form->error('service_from'))) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="order-service_to">bis</label>
                        <input type="date" id="order-service_to" name="service_to" value="<?= e($form->header('service_to')) ?>" aria-invalid="<?= $invalid($form->error('service_to')) ?>">
                        <?= raw($fieldError($form->error('service_to'))) ?>
                    </div>
                </div>
                <?php // The payment terms (owner 2026-10-18) — «Debitor anpassen» right of the label changes the
                      // customer's standing terms. The bank account («Zahlungsziel») is not offered here: the
                      // server keeps it (a new order: the first active target; an existing one: its own). ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <div class="be-form__card-head">
                        <label for="order-terms">Zahlungskonditionen</label>
                        <?= raw($debtorEdit) ?>
                    </div>
                    <?= raw($termsSelect) ?>
                    <?= raw($fieldError($form->error('terms'))) ?>
                </div>
            </div>
        </div>

        <?php // The positions (owner 2026-10-18, the draft): one block, the price mode in its head, the
              // totals at its foot. The rows are a SORT ORDER — ⋮⋮ drags a row (its indented rows with it),
              // ⇥ indents it beneath the row above, × removes it; «+ Artikel» / «+ Textzeile» add one
              // (core.js «rows» in place; without the script the adders and × are submits, the order is
              // the rendered one). The totals follow the typing (core.js «line sums», display only) until
              // the save answers with debtor's preview (D11). ?>
        <?php
        $currency = $preview?->getCurrency() ?? $currency;
        // One row of the positions — the server's rows and the two <template> rows share it ($i = '__i__').
        $positionRow = function (int|string $i, array $row, string $no) use ($form, $articles, $taxCodes, $taxRates, $liableToVat, $editable, $invalid, $amount, $actionBase, $key, $pickUrl, $pickWith, $pickState): string {
            $p    = 'rows[' . $i . ']';
            $rid  = 'order-row-' . $i . '-';
            $err  = static fn(string $f): string => is_int($i) ? $form->rowError($i, $f) : '';
            $text = $row['type'] === LineType::Text->value;
            $art  = $row['article'] !== '' ? ($articles[$row['article']] ?? null) : null;
            // The VAT rate of the line: the article's tax code (D3), a kept free line's own (display only).
            $code = $art !== null ? $art['taxCode'] : $row['tax_code'];
            $rows = max(1, substr_count($row['text'], "\n") + 1);
            ob_start(); ?>
            <div class="be-list__item" data-row>
                <div class="be-list__row be-rows__row<?= $text ? ' be-rows__row--text' : '' ?>" data-fill-scope>
                    <span class="be-list__cell be-rows__handle">
                        <?php if ($editable): ?>
                        <button type="button" class="be-rows__grip" data-row-grip hidden title="Verschieben: ziehen oder Alt+↑ / Alt+↓" aria-label="Zeile verschieben (ziehen oder Alt+Pfeil auf / ab)">⋮⋮</button>
                        <?php endif; ?>
                        <label class="be-rows__indent" title="Einrücken: unter die Position darüber">
                            <input type="checkbox" class="be-rows__indent-input" id="<?= $rid ?>child" name="<?= $p ?>[child]" value="1"<?= $row['child'] !== '' ? ' checked' : '' ?> data-row-indent data-help-key="child" aria-label="Unter die Position darüber einrücken" aria-invalid="<?= $invalid($err('child')) ?>">
                            <span aria-hidden="true">⇥</span>
                        </label>
                    </span>
                    <span class="be-list__cell be-list__cell--num be-list__cell--muted" data-row-no><?= e($no) ?></span>
                    <span class="be-list__cell">
                        <?php if ($row['id'] !== ''): ?><input type="hidden" name="<?= $p ?>[id]" value="<?= e($row['id']) ?>"><?php endif; ?>
                        <?php if (($row['code'] ?? '') !== ''): // the line code — no field yet (owner 2026-10-22), carried through ?><input type="hidden" name="<?= $p ?>[code]" value="<?= e($row['code']) ?>"><?php endif; ?>
                        <input type="hidden" name="<?= $p ?>[type]" value="<?= $text ? 'text' : 'service' ?>">
                        <?php if ($art === null && $row['tax_code'] !== ''): ?><input type="hidden" name="<?= $p ?>[tax_code]" value="<?= e($row['tax_code']) ?>"><?php endif; ?>
                        <span class="badge badge--muted"><?= e(OrderForm::TYPES[$text ? 'text' : 'service']) ?></span>
                    </span>
                    <?php if ($text): ?>
                    <span class="be-list__cell be-rows__text be-rows__wide"><textarea class="be-input be-input--sm" id="<?= $rid ?>text" name="<?= $p ?>[text]" rows="<?= $rows ?>" data-autosize data-help-key="text" aria-label="Text" aria-invalid="<?= $invalid($err('text')) ?>"><?= e($row['text']) ?></textarea></span>
                    <?php else: ?>
                    <?php // The number is typed (when known — the fastest path) or chosen in the picker (the magnifier, owner 2026-10-19); no datalist since 2026-10-20 (owner: the picker searches). ?>
                    <span class="be-list__cell be-rows__article"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>article" name="<?= $p ?>[article]" value="<?= e($row['article']) ?>" spellcheck="false" data-fill-url="<?= e($actionBase . '/article-defaults') ?>" data-fill-with="order_date service_from price_mode" data-help-key="article" aria-label="Artikel" aria-invalid="<?= $invalid($err('article')) ?>"<?= $art !== null ? ' title="' . e($art['name']) . '"' : '' ?><?= $row['id'] !== '' && $row['article'] === '' ? ' placeholder="ohne Artikel"' : '' ?>><?php if ($editable): $pick = \Z77\Module\Article\Ui\ArticlePicker::url($pickUrl, ['target' => $rid . 'article'] + $pickState); ?><a class="be-rows__pick" href="<?= e($pick) ?>" target="_blank" rel="noopener" data-window-open="<?= e($pick) ?>" data-window-with="<?= e($pickWith) ?>" title="Artikel wählen …" aria-label="Artikel wählen …"><svg class="be-icon" width="13" height="13" aria-hidden="true"><use href="#icon-search"/></svg></a><?php endif; ?></span>
                    <span class="be-list__cell be-rows__text"><textarea class="be-input be-input--sm" id="<?= $rid ?>text" name="<?= $p ?>[text]" rows="<?= $rows ?>" data-autosize="title" data-fill-key="text" data-help-key="text" aria-label="Text" aria-invalid="<?= $invalid($err('text')) ?>"><?= e($row['text']) ?></textarea></span>
                    <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>quantity" name="<?= $p ?>[quantity]" value="<?= e($row['quantity']) ?>" inputmode="decimal" data-sum-qty data-help-key="quantity" aria-label="Menge" aria-invalid="<?= $invalid($err('quantity')) ?>"></span>
                    <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>unit" name="<?= $p ?>[unit]" value="<?= e($row['unit']) ?>" maxlength="16" data-fill-key="unit" data-help-key="unit" aria-label="Einheit" aria-invalid="<?= $invalid($err('unit')) ?>"></span>
                    <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>price" name="<?= $p ?>[price]" value="<?= e($row['price']) ?>" inputmode="decimal" data-fill-key="price" data-sum-price data-price-mode-price data-help-key="price" aria-label="Preis" aria-invalid="<?= $invalid($err('price')) ?>"></span>
                    <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>discount" name="<?= $p ?>[discount]" value="<?= e($row['discount']) ?>" inputmode="decimal" data-sum-discount data-help-key="discount" aria-label="Rabatt in Prozent" aria-invalid="<?= $invalid($err('discount')) ?>"></span>
                    <?php // The RATE of the article's tax code («8.1 %», D3 — not changeable, never sent): a select only so
                          // that core.js «fill» can set the code and the option shows its rate; `data-rate` feeds «line sums».
                          // Without VAT no cell: «line sums» and «price mode» then count without a rate. ?>
                    <?php if ($liableToVat): ?>
                    <span class="be-list__cell" title="vom Artikel"><select class="be-input be-input--sm be-rows__rate" data-fill-key="tax_code" data-sum-rate data-price-mode-rate data-rate-schedule-ref="order-vat" tabindex="-1" aria-label="MWST-Satz (vom Artikel)" disabled>
                        <option value=""<?= $code === '' ? ' selected' : '' ?>>–</option>
                        <?php foreach ($taxCodes as $taxCode): $c = $taxCode->getCode(); ?>
                        <option value="<?= e($c) ?>"<?= $c === $code ? ' selected' : '' ?><?= isset($taxRates[$c]) ? ' data-rate="' . e(rtrim($taxRates[$c], ' %')) . '"' : '' ?>><?= e($taxRates[$c] ?? $c) ?></option>
                        <?php endforeach; ?>
                    </select></span>
                    <?php endif; ?>
                    <span class="be-list__cell be-list__cell--num be-list__cell--amount" data-sum-amount><?= e($amount($row)) ?></span>
                    <?php endif; ?>
                    <span class="be-list__cell be-rows__end">
                        <?php if ($editable): ?>
                        <button type="submit" class="be-rows__remove" name="op" value="remove:<?= e((string) $i) ?>" formnovalidate data-row-remove title="Zeile entfernen" aria-label="Zeile entfernen">×</button>
                        <?php endif; ?>
                    </span>
                </div>
                <?php $messages = is_int($i) ? array_filter(array_map($err, ['type', 'child', 'article', 'text', 'quantity', 'unit', 'price', 'discount', 'tax_code'])) : []; ?>
                <?php if ($messages !== []): ?>
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--wrap be-list__cell--span">
                        <?php foreach (array_unique($messages) as $message): ?>
                        <small class="be-form__field-error"><?= e($message) ?></small>
                        <?php endforeach; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php return (string) ob_get_clean();
        };
        $blank = ['id' => '', 'type' => 'service', 'child' => '', 'article' => '', 'text' => '', 'quantity' => '', 'unit' => '', 'price' => '', 'discount' => '', 'tax_code' => ''];
        ?>
        <?php // «Preise» converts the rows at once (core.js «price mode», owner 2026-10-18); `prices_mode` says
              // which mode the prices are in — the server converts at the save when it is still the other. ?>
        <div class="be-form__block" data-sum data-price-mode>
            <?php // The script data stands before the table; the bar (title, adders, «Preise») and
                  // the column head form ONE sticky top inside it (`.be-rows__top`, owner 2026-10-20): with many
                  // positions they stay in view while the rows scroll. ?>
            <?php if ($liableToVat): ?>
            <script type="application/json" data-rate-schedule="order-vat" data-rate-date="service_from order_date"><?= json_encode($rateSchedule ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <?php endif; ?>
            <div class="be-list__frame be-list__frame--stick">
                <div class="be-list__table be-rows" style="--be-list-cols: <?= e($cols) ?>; --be-rows-span: <?= $span ?>" data-rows="rows">
                    <div class="be-rows__top">
                    <div class="be-form__block-head be-rows__bar">
                        <h3 class="be-list__section-title">Positionen</h3>
                        <?php if ($editable): ?>
                        <div class="be-rows__adders">
                            <?php // «+ Artikel» opens the picker (owner 2026-10-19): the chosen article lands in a NEW row,
                                  // «Schliessen» adds none. Without the script it stays the submit that adds an empty row. ?>
                            <button type="submit" class="be-btn be-btn--add be-btn--sm" name="op" value="add-article" formnovalidate data-window-open="<?= e(\Z77\Module\Article\Ui\ArticlePicker::url($pickUrl, $pickState)) ?>" data-window-with="<?= e($pickWith) ?>" title="Artikel wählen …"><span aria-hidden="true">+</span> Artikel</button>
                            <button type="submit" class="be-btn be-btn--add be-btn--sm" name="op" value="add-text" formnovalidate data-row-add="text"><span aria-hidden="true">+</span> Textzeile</button>
                        </div>
                        <?php endif; ?>
                        <?php // Without VAT the price mode changes no amount (owner 2026-10-21): no select, the mode kept as a hidden field. ?>
                        <div class="be-form__field" data-z77-field-wrapper<?= $liableToVat ? '' : ' hidden' ?>>
                    <label for="order-price_mode">Preise</label>
                    <select id="order-price_mode" name="price_mode" data-sum-mode data-price-mode-select aria-invalid="<?= $invalid($form->error('price_mode')) ?>">
                        <option value="net"<?= $form->header('price_mode') === 'net' ? ' selected' : '' ?>>netto (exkl. MWST)</option>
                        <option value="gross"<?= $form->header('price_mode') === 'gross' ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                    </select>
                    <?= raw($fieldError($form->error('price_mode'))) ?>
                            <input type="hidden" name="prices_mode" value="<?= e($form->header('prices_mode')) ?>" data-price-mode-shown>
                        </div>
                    </div>
                    <div class="be-list__head">
                        <span class="be-list__col"></span>
                        <span class="be-list__col be-list__col--num">Pos.</span>
                        <span class="be-list__col">Art</span>
                        <span class="be-list__col">Art.-Nr.</span>
                        <span class="be-list__col">Text</span>
                        <span class="be-list__col be-list__col--num">Menge</span>
                        <span class="be-list__col">Einheit</span>
                        <span class="be-list__col be-list__col--num">Preis</span>
                        <span class="be-list__col be-list__col--num">Rabatt %</span>
                        <?php if ($liableToVat): ?><span class="be-list__col">MWST</span><?php endif; ?>
                        <span class="be-list__col be-list__col--num">Betrag</span>
                        <span class="be-list__col"></span>
                    </div>
                    </div>
                    <?php $pos = 0; $sub = 0; ?>
                    <?php foreach ($form->rows() as $i => $row):
                        // The position number as printed: an indented row counts under its parent (1.1).
                        if ($row['child'] !== '' && $pos > 0) { $sub++; $no = $pos . '.' . $sub; } else { $pos++; $sub = 0; $no = (string) $pos; } ?>
                    <?= raw($positionRow($i, $row, $no)) ?>
                    <?php endforeach; ?>
                    <?php if ($editable): ?>
                    <template data-row-template="article"><?= raw($positionRow('__i__', $blank, '')) ?></template>
                    <template data-row-template="text"><?= raw($positionRow('__i__', ['type' => 'text'] + $blank, '')) ?></template>
                    <?php endif; ?>
                </div>
            </div>
            <div class="be-form__foot">
                <div class="be-form__audit">
                    <?php if (!$isNew): ?>
                    erstellt <?= e($order->getCreatedBy()) ?> · <?= e($order->getCreatedAt()->format('d.m.Y H:i')) ?>
                    <?php if ($order->getChangedAt() !== null): ?> &nbsp;|&nbsp; geändert <?= e((string) $order->getChangedBy()) ?> · <?= e($order->getChangedAt()->format('d.m.Y H:i')) ?><?php endif; ?>
                    &nbsp;|&nbsp; Version <?= (int) $order->getVersion() ?>
                    <?php endif; ?>
                </div>
                <?php // The total: debtor's preview of the STORED order (D11) — what the booking print would invoice
                      // today. While rows are typed, core.js «line sums» shows what they add up to (without the
                      // rounding, labelled so); the save answers with the preview again. ?>
                <div class="be-form__totals" aria-live="polite">
                    <div><span>Total netto</span><span data-sum-net><?= $preview !== null ? e($fmt($preview->getNetTotal())) : ($isNew ? '0.00' : '–') ?></span></div>
                    <?php if ($liableToVat): ?>
                    <div class="be-form__totals-group" data-sum-taxes>
                        <template data-sum-tax><div class="be-form__totals-sub"><span>MWST <span data-sum-tax-rate></span> % auf <span data-sum-tax-base></span></span><span data-sum-tax-amount></span></div></template>
                        <?php foreach ($preview?->getTaxes() ?? [] as $tax): ?>
                        <div class="be-form__totals-sub"><span>MWST <?= e(TaxRate::formatPercent($tax->getTaxRate())) ?> % auf <?= e($fmt($tax->getBase())) ?></span><span><?= e($fmt($tax->getTax())) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($preview !== null && !$preview->getRounding()->isZero()): ?>
                    <div class="be-form__totals-sub" data-sum-stored><span>Rundung</span><span><?= e($fmt($preview->getRounding())) ?></span></div>
                    <?php endif; ?>
                    <div class="be-form__totals-grand"><span>Total <?= e($currency) ?></span><span data-sum-gross><?= $preview !== null ? e($fmt($preview->getGrossTotal())) : ($isNew ? '0.00' : '–') ?></span></div>
                    <?php if ($preview !== null): ?>
                    <div class="be-form__totals-note" data-sum-stored>gespeichert — so würde das Fakturieren heute fakturieren</div>
                    <?php endif; ?>
                    <div class="be-form__totals-note" data-sum-live hidden>laufend aus den Zeilen, ohne Rundung — massgebend ist das Total nach dem Speichern</div>
                </div>
            </div>
            <?php if ($previewProblems !== []): ?>
            <div class="be-modal__alert be-modal__alert--error">
                <strong>Vor dem Fakturieren beheben:</strong>
                <?php foreach ($previewProblems as $problem): ?>
                <div><?= e($problem) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        </fieldset>

        <?php if (!$isNew): ?>
        <?php // Verlauf und Belege — collapsed at the end (owner 2026-10-16: rarely used, mainly for troubleshooting). ?>
        <details class="be-form__block">
            <summary>Verlauf und Belege</summary>
            <span class="be-form__card-label">Verlauf</span>
            <?php if ($history === []): ?>
            <p class="be-list__empty">Noch kein Eintrag.</p>
            <?php else: ?>
            <div class="be-list__table" style="--be-list-cols: 8.5rem 7rem minmax(12rem, 2fr) 8rem minmax(8rem, 1fr)">
                <div class="be-list__head">
                    <span class="be-list__col">Datum</span>
                    <span class="be-list__col">Wer</span>
                    <span class="be-list__col">Status</span>
                    <span class="be-list__col">Auslöser</span>
                    <span class="be-list__col">Grund</span>
                </div>
                <?php foreach ($history as $change): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"><?= e($change->getChangedAt()->format('d.m.Y H:i')) ?></span>
                        <span class="be-list__cell"><?= e($change->getChangedBy()) ?></span>
                        <span class="be-list__cell"><?= $change->getFromCode() !== null ? e($statusLabels[$change->getFromCode()] ?? $change->getFromCode()) . ' → ' : '' ?><?= e($statusLabels[$change->getToCode()] ?? $change->getToCode()) ?></span>
                        <span class="be-list__cell"><?= e($triggers[$change->getTrigger()] ?? $change->getTrigger()) ?></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e((string) $change->getReason()) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <span class="be-form__card-label">Belege</span>
            <?php if ($invoice === null): ?>
            <p class="be-list__empty">Noch keine Rechnung — sie entsteht mit dem Fakturieren.</p>
            <?php else: ?>
            <?php $detail = $invoiceBase . '/edit?id=' . (int) $invoice->getId(); $badges = ['open' => 'badge--info', 'partial' => 'badge--warning', 'paid' => 'badge--success', 'credited' => 'badge--muted']; ?>
            <div class="be-list__table" style="--be-list-cols: minmax(10rem, 2fr) 6.5rem 7rem 7rem 8rem 4rem">
                <div class="be-list__head">
                    <span class="be-list__col">Beleg</span>
                    <span class="be-list__col">Datum</span>
                    <span class="be-list__col be-list__col--num">Betrag</span>
                    <span class="be-list__col be-list__col--num">Offen</span>
                    <span class="be-list__col">Zahlung</span>
                    <span class="be-list__col">PDF</span>
                </div>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <a class="be-list__cell" href="<?= e($detail) ?>" data-window-open="<?= e($detail) ?>"><?= e($invoice->documentName()) ?></a>
                        <span class="be-list__cell"><?= e($invoice->getInvoiceDate()->format('d.m.Y')) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($invoice->getGrossTotal())) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $payment !== null ? e($fmt($payment->open)) : '' ?></span>
                        <span class="be-list__cell"><?php if ($payment !== null): ?><span class="badge <?= e($badges[$payment->status] ?? 'badge--muted') ?>"><?= e($payment->label()) ?></span><?php else: ?><span class="badge <?= $invoice->getReissueNeeded() !== null ? 'badge--warning' : 'badge--muted' ?>" title="<?= e((string) $invoice->getReissueNeeded()) ?>"><?= e($invoice->state()->label() . ($invoice->isInJournal() ? ' · nicht verbucht' : '') . ($invoice->getRevision() > 1 ? ' · Version ' . $invoice->getRevision() : '')) ?></span><?php endif; ?></span>
                        <a class="be-list__cell" href="<?= e($invoiceBase . '/pdf?id=' . (int) $invoice->getId()) ?>" target="_blank" rel="noopener">PDF</a>
                    </div>
                </div>
                <?php foreach ($creditNotes as $note): $noteUrl = $invoiceBase . '/edit?id=' . (int) $note->getId(); ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <a class="be-list__cell" href="<?= e($noteUrl) ?>" data-window-open="<?= e($noteUrl) ?>"><?= e($note->documentName()) ?></a>
                        <span class="be-list__cell"><?= e($note->getInvoiceDate()->format('d.m.Y')) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($note->getGrossTotal())) ?></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--muted"><?= e($note->state()->label()) ?></span>
                        <?php if (!$note->isDraft()): ?><a class="be-list__cell" href="<?= e($invoiceBase . '/pdf?id=' . (int) $note->getId()) ?>" target="_blank" rel="noopener">PDF</a><?php else: ?><span class="be-list__cell"></span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </details>
        <?php endif; ?>
    </form>
</div>
