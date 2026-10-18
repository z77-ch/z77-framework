<?php
/**
 * The ORDER WINDOW (plan §9.4; the owner's board on the developer canvas) — a new order or an
 * existing one, ONE form, ONE save. A plain form, no JavaScript of its own (Rule 7): a fixed
 * number of line rows, «Weitere Zeilen» submits and comes back with more, the article picker is
 * a `<datalist>`; `csrf_token` is the page-mode field (`#[Csrf]`), an existing order also
 * carries the entity token and the VERSION it was rendered from (the optimistic lock of
 * `OrderService::update()`).
 *
 * Fetched, a WINDOW (ADR-047): mask `order-edit`, record `sales-order:<id>` (a new order: mask
 * only), the title in the title bar, `_origin` carried back. The action row (ADR-049) is the
 * shared `partials/modalActions`, FIRST in the form: Speichern · the STATUS as a pill that opens
 * «Status ändern …» (the draft's status select, owner 2026-10-16) · «Drucken ▾» — by the PHASE
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
 * @var string|null $editRefusal  why the order is read-only (D7), null = editable
 * @var bool $needReason  the stock seam asked for a reason (consumed stock would come back)
 * @var string $entityCsrf
 * @var string $customerField  debtor's `CustomerPicker::fieldHtml()` — the customer + «Kunde wählen …» (contacts without a profile choosable, D9)
 * @var string $customerTitle  block (1)'s title `_customerTitle` — «Kunde», the customer number, the phone
 * @var string $termsSelect  block (3)'s «Zahlungskonditionen» (`_terms`, debtor's PaymentTerms; owner 2026-10-18)
 * @var string $debtorEdit  «Debitor anpassen» (`_debtorEdit`, `#order-debtor-edit`) — debtor's profile dialog of the customer
 * @var array<string, string> $ships  delivery-address choice → label
 * @var array<string, string> $bills  billing-address choice → label (2026-10-17)
 * @var bool $shipAdjusted  the stored delivery snapshot is none of the customer's addresses (badge «angepasst»)
 * @var bool $billAdjusted  the same for the billing snapshot
 * @var list<string> $billShown  the billing card's address lines for the CURRENT choice — not the
 *                               preview's, which fails while the order has no lines (2026-10-18)
 * @var list<string> $shipShown  the delivery card's, empty for «wie Rechnungsadresse»
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
 * @var array<string, string> $taxRates  code → the rate shown («8.1 %») on the order date
 * @var list<array{number: string, label: string}> $accounts
 * @var array<string, array{name: string, unit: string, taxCode: string, account: string, offered: bool}> $articles  article number → what it brings
 * @var \Z77\Module\Debtor\Entities\Invoice|null $preview  debtor's preview of the STORED order (D11)
 * @var list<string> $previewProblems  what the booking print would refuse now
 * @var list<\Z77\Module\Order\Entities\SalesOrderStatusChange> $history
 * @var array<string, string> $triggers  trigger → German
 * @var \Z77\Module\Debtor\Entities\Invoice|null $invoice
 * @var \Z77\Module\Debtor\Services\PaymentState|null $payment
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $creditNotes
 * @var string $invoiceBase  debtor's document screens
 * @var string $orderTitle  «Offerte» / «Auftragsbestätigung» (by the phase)
 * @var bool $canBook
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
} else {
    $statusLabel = $status?->getLabel() ?? $order->getStatusCode();
    $extra .= '<a class="be-status-field be-status-field--' . $tone($phase) . '" href="' . e($actionBase . '/status?id=' . $id) . '" data-window-link title="Status ändern …">'
        . '<span class="be-status-field__label">Status</span><span class="be-status-field__dot"></span>'
        . '<span class="be-status-field__name">' . e($statusLabel) . '</span><span class="be-status-field__caret" aria-hidden="true">▼</span></a>'
        . '<span class="z77-form-actions__spacer"></span>';
    $prints = [];
    if (!$cancelled) {
        $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/pdf?id=' . $id) . '" target="_blank" rel="noopener" title="' . e($orderTitle) . ' als PDF (neuer Tab)">' . e($orderTitle) . '</a>';
        if ($phase?->isQuote() ?? false) {
            $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/confirm-confirmation?id=' . $id) . '" data-window-link title="Auftragsbestätigung drucken — der Auftrag wird bestätigt">Auftragsbestätigung …</a>';
        } else {
            $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/confirm-delivery-note?id=' . $id) . '" data-window-link>Lieferschein …</a>';
        }
        if ($invoice === null) {
            $prints[] = '<a class="be-shell-add__item" href="' . e($actionBase . '/invoice-preview?id=' . $id) . '" target="_blank" rel="noopener" title="Die Rechnung, wie der Buchungsdruck sie heute erstellen würde — ohne Nummer und ohne Zahlteil">Rechnungsvorschau</a>';
        }
    }
    if ($prints !== []) {
        // A CSS-only menu (`<details>`, the `.be-shell-add--select` anatomy) — no script (Rule 7).
        $extra .= '<details class="be-shell-add be-shell-add--select"><summary class="be-btn be-btn--ghost">Drucken ▾</summary>'
            . '<div class="be-shell-add__panel" role="menu" aria-label="Drucken">' . implode('', $prints) . '</div></details>';
    }
    if ($canBook) {
        $extra .= '<a class="be-btn be-btn--confirm-open" href="' . e($actionBase . '/confirm-book?' . http_build_query(['order' => [$id . ':' . $order->getVersion()]])) . '" title="Rechnung erstellen, verbuchen und als PDF drucken">Buchungsdruck …</a>';
    }
}
// The Konto column only while the order still carries a FREE priced line (D2, before the owner's
// «every priced line is an article», 2026-10-16): an article line takes its account from the
// article (D3), so the column is noise for the person entering the order.
$hasFree = false;
foreach ($form->rows() as $row) {
    $hasFree = $hasFree || ($row['article'] === '' && $row['type'] !== LineType::Text->value && $row['account'] !== '');
}
$cols    = '3rem 7rem 3.5rem 7.5rem minmax(11rem, 3fr) 4.5rem 4.5rem 6.5rem 5rem 5.5rem 7rem' . ($hasFree ? ' 6.5rem' : '');
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
    <form method="post" action="<?= e($action) ?>" class="be-form--blocks" id="order-form-<?= e($key) ?>" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) ($version ?? $order->getVersion()) ?>">
        <?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'      => $editable ? ($isNew ? 'Erstellen' : 'Speichern') : '',
            'submitAttrs' => ['name' => 'op', 'value' => 'save'],
            'extra'       => $extra,
            'cancel'      => $window ? 'Schliessen' : 'Zur Liste',
            'cancelKind'  => 'quiet',
            'cancelHref'  => $window ? '' : $actionBase . '/list',
            'errors'      => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <h2 class="be-list__section-title"><?= e($title) ?></h2>
        <?php endif; ?>

        <?php if ($editRefusal !== null): ?>
        <div class="be-modal__alert"><?= e($editRefusal) ?></div>
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
                    <?php // «Kunde 20417» + phone (`_customerTitle`, `#order-customer-title`) — «Kunde wechseln» replaces it. ?>
                    <?= raw($customerTitle) ?>
                </div>
                <div data-z77-field-wrapper>
                    <?php if ($editable): ?>
                    <?php // debtor's customer field: the name, «Kunde wechseln» opens the searching picker over this window
                          // (CustomerPicker); no «+ Kontakt» here — the picker window offers it (owner 2026-10-17). ?>
                    <?= raw($customerField) ?>
                    <?php else: ?>
                    <span class="be-customer"><span class="be-customer__who"><strong class="be-customer__name" id="invoice-contact-name"><?= e($order->getContact()->displayName()) ?></strong></span></span>
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

        <?php // The positions: one block, the price mode in its head, the totals at its foot. ?>
        <div class="be-form__block">
            <div class="be-form__block-head">
                <h3 class="be-list__section-title">Positionen</h3>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="order-price_mode">Preise</label>
                    <select id="order-price_mode" name="price_mode" aria-invalid="<?= $invalid($form->error('price_mode')) ?>">
                        <option value="net"<?= $form->header('price_mode') === 'net' ? ' selected' : '' ?>>netto (exkl. MWST)</option>
                        <option value="gross"<?= $form->header('price_mode') === 'gross' ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                    </select>
                    <?= raw($fieldError($form->error('price_mode'))) ?>
                </div>
            </div>
            <datalist id="order-articles-<?= e($key) ?>">
                <?php foreach ($articles as $number => $article): ?>
                <?php if ($article['offered']): ?>
                <option value="<?= e((string) $number) ?>"><?= e($article['name'] . ($article['unit'] !== '' ? ' · ' . $article['unit'] : '')) ?></option>
                <?php endif; ?>
                <?php endforeach; ?>
            </datalist>
            <?php if ($hasFree): ?>
            <?= $this->partial('partials/accountDatalist', ['id' => 'order-accounts-' . $key, 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>
            <?php endif; ?>
            <div class="be-list__frame">
                <div class="be-list__table" style="--be-list-cols: <?= e($cols) ?>">
                    <div class="be-list__head">
                        <span class="be-list__col be-list__col--num">Pos.</span>
                        <span class="be-list__col">Art</span>
                        <span class="be-list__col" title="unter der Position darüber">unter</span>
                        <span class="be-list__col">Art.-Nr.</span>
                        <span class="be-list__col">Text</span>
                        <span class="be-list__col be-list__col--num">Menge</span>
                        <span class="be-list__col">Einheit</span>
                        <span class="be-list__col be-list__col--num">Preis</span>
                        <span class="be-list__col be-list__col--num">Rabatt %</span>
                        <span class="be-list__col">MWST</span>
                        <span class="be-list__col be-list__col--num">Betrag</span>
                        <?php if ($hasFree): ?><span class="be-list__col">Konto</span><?php endif; ?>
                    </div>
                    <?php $pos = 0; $sub = 0; ?>
                    <?php foreach ($form->rows() as $i => $row): $p = 'rows[' . $i . ']'; $rid = 'order-row-' . $i . '-'; $art = $row['article'] !== '' ? ($articles[$row['article']] ?? null) : null;
                        // The position number as printed: a child line counts under its parent (1.1).
                        if ($row['child'] !== '' && $pos > 0) { $sub++; $no = $pos . '.' . $sub; } else { $pos++; $sub = 0; $no = (string) $pos; } ?>
                    <div class="be-list__item">
                        <div class="be-list__row" data-fill-scope>
                            <span class="be-list__cell be-list__cell--num be-list__cell--muted"><?= e($no) ?></span>
                            <span class="be-list__cell">
                                <?php if ($row['id'] !== ''): ?><input type="hidden" name="<?= $p ?>[id]" value="<?= e($row['id']) ?>"><?php endif; ?>
                                <select class="be-input be-input--sm" id="<?= $rid ?>type" name="<?= $p ?>[type]" data-help-key="type" aria-label="Art Zeile <?= $i + 1 ?>">
                                    <?php foreach (OrderForm::TYPES as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $row['type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </span>
                            <span class="be-list__cell"><label class="be-choice"><input type="checkbox" class="be-choice__input" id="<?= $rid ?>child" name="<?= $p ?>[child]" value="1"<?= $row['child'] !== '' ? ' checked' : '' ?> data-help-key="child" aria-label="Zeile <?= $i + 1 ?> unter der Position darüber" aria-invalid="<?= $invalid($form->rowError($i, 'child')) ?>"></label></span>
                            <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>article" name="<?= $p ?>[article]" value="<?= e($row['article']) ?>" list="order-articles-<?= e($key) ?>" spellcheck="false" data-fill-url="<?= e($actionBase . '/article-defaults') ?>" data-fill-with="order_date service_from price_mode" data-help-key="article" aria-label="Artikel Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'article')) ?>"<?= $art !== null ? ' title="' . e($art['name']) . '"' : '' ?>></span>
                            <span class="be-list__cell"><textarea class="be-input be-input--sm" id="<?= $rid ?>text" name="<?= $p ?>[text]" rows="1" data-fill-key="text" data-help-key="text" aria-label="Text Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'text')) ?>"><?= e($row['text']) ?></textarea></span>
                            <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>quantity" name="<?= $p ?>[quantity]" value="<?= e($row['quantity']) ?>" inputmode="decimal" data-help-key="quantity" aria-label="Menge Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'quantity')) ?>"></span>
                            <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>unit" name="<?= $p ?>[unit]" value="<?= e($row['unit']) ?>" maxlength="16" data-fill-key="unit" data-help-key="unit" aria-label="Einheit Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'unit')) ?>"></span>
                            <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>price" name="<?= $p ?>[price]" value="<?= e($row['price']) ?>" inputmode="decimal" data-fill-key="price" data-help-key="price" aria-label="Preis Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'price')) ?>"></span>
                            <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>discount" name="<?= $p ?>[discount]" value="<?= e($row['discount']) ?>" inputmode="decimal" data-help-key="discount" aria-label="Rabatt Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'discount')) ?>"></span>
                            <?php // An article line shows the article's VAT LOCKED, as the RATE (D3 — the save takes it from the article and
                                  // ignores what is posted); the field stays, so a changed or cleared article (core.js «fill») can set or free it. ?>
                            <span class="be-list__cell"<?= $art !== null ? ' title="vom Artikel"' : '' ?>><?= $this->partial('partials/taxCodeSelect', [
                                'codes' => $taxCodes, 'name' => $p . '[tax_code]', 'selected' => $art !== null ? $art['taxCode'] : $row['tax_code'], 'id' => $rid . 'tax_code',
                                'label' => 'MWST Zeile ' . ($i + 1), 'invalid' => $form->rowError($i, 'tax_code') !== '', 'shown' => $taxRates,
                                'attrs' => ['data-fill-key' => 'tax_code'] + ($art !== null ? ['disabled' => true] : []),
                            ], 'Z77\\Module\\Vat') ?></span>
                            <span class="be-list__cell be-list__cell--num be-list__cell--amount"><?= e($amount($row)) ?></span>
                            <?php if ($hasFree): ?>
                            <span class="be-list__cell"<?= $art !== null ? ' title="vom Artikel"' : '' ?>><input class="be-input be-input--sm" type="text" id="<?= $rid ?>account" name="<?= $p ?>[account]" value="<?= e($art !== null ? $art['account'] : $row['account']) ?>"<?= $accounts !== [] ? ' list="order-accounts-' . e($key) . '"' : '' ?> inputmode="numeric" data-fill-key="account" data-help-key="account" aria-label="Konto Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'account')) ?>"<?= $art !== null ? ' readonly' : '' ?>></span>
                            <?php endif; ?>
                        </div>
                        <?php $messages = array_filter(array_map(fn($f) => $form->rowError($i, $f), ['type', 'child', 'article', 'text', 'quantity', 'unit', 'price', 'discount', 'tax_code', 'account'])); ?>
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
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($editable): ?>
            <div>
                <button type="submit" class="be-btn be-btn--ghost be-btn--sm" name="op" value="more">+ Weitere Zeilen</button>
            </div>
            <?php endif; ?>

            <div class="be-form__foot">
                <div class="be-form__audit">
                    <?php if (!$isNew): ?>
                    erstellt <?= e($order->getCreatedBy()) ?> · <?= e($order->getCreatedAt()->format('d.m.Y H:i')) ?>
                    <?php if ($order->getChangedAt() !== null): ?> &nbsp;|&nbsp; geändert <?= e((string) $order->getChangedBy()) ?> · <?= e($order->getChangedAt()->format('d.m.Y H:i')) ?><?php endif; ?>
                    &nbsp;|&nbsp; Version <?= (int) $order->getVersion() ?>
                    <?php endif; ?>
                </div>
                <?php if ($preview !== null): ?>
                <?php // The total of the STORED order — debtor's preview (D11): what the booking print would invoice today. ?>
                <div class="be-form__totals" title="gespeichert — so würde der Buchungsdruck heute fakturieren">
                    <div><span>Total netto</span><span><?= e($fmt($preview->getNetTotal())) ?></span></div>
                    <?php foreach ($preview->getTaxes() as $tax): ?>
                    <div class="be-form__totals-sub"><span>MWST <?= e(TaxRate::formatPercent($tax->getTaxRate())) ?> % auf <?= e($fmt($tax->getBase())) ?></span><span><?= e($fmt($tax->getTax())) ?></span></div>
                    <?php endforeach; ?>
                    <?php if (!$preview->getRounding()->isZero()): ?>
                    <div class="be-form__totals-sub"><span>Rundung</span><span><?= e($fmt($preview->getRounding())) ?></span></div>
                    <?php endif; ?>
                    <div class="be-form__totals-grand"><span>Total <?= e($preview->getCurrency()) ?></span><span><?= e($fmt($preview->getGrossTotal())) ?></span></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($previewProblems !== []): ?>
            <div class="be-modal__alert be-modal__alert--error">
                <strong>Vor dem Buchungsdruck beheben:</strong>
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
            <p class="be-list__empty">Noch keine Rechnung — sie entsteht mit dem Buchungsdruck.</p>
            <?php else: ?>
            <?php $detail = $invoiceBase . '/detail?id=' . (int) $invoice->getId(); $badges = ['open' => 'badge--info', 'partial' => 'badge--warning', 'paid' => 'badge--success', 'credited' => 'badge--muted']; ?>
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
                        <span class="be-list__cell"><?php if ($payment !== null): ?><span class="badge <?= e($badges[$payment->status] ?? 'badge--muted') ?>"><?= e($payment->label()) ?></span><?php endif; ?></span>
                        <a class="be-list__cell" href="<?= e($invoiceBase . '/pdf?id=' . (int) $invoice->getId()) ?>" target="_blank" rel="noopener">PDF</a>
                    </div>
                </div>
                <?php foreach ($creditNotes as $note): $noteUrl = $invoiceBase . '/detail?id=' . (int) $note->getId(); ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <a class="be-list__cell" href="<?= e($noteUrl) ?>" data-window-open="<?= e($noteUrl) ?>"><?= e($note->documentName()) ?></a>
                        <span class="be-list__cell"><?= e($note->getInvoiceDate()->format('d.m.Y')) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($note->getGrossTotal())) ?></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--muted"><?= $note->isFinal() ? 'verbucht' : 'in Fakturierung' ?></span>
                        <?php if ($note->isFinal()): ?><a class="be-list__cell" href="<?= e($invoiceBase . '/pdf?id=' . (int) $note->getId()) ?>" target="_blank" rel="noopener">PDF</a><?php else: ?><span class="be-list__cell"></span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </details>
        <?php endif; ?>
    </form>
</div>
