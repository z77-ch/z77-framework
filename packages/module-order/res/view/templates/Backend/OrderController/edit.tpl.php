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
 * shared `partials/modalActions`, FIRST in the form: Speichern · Offerte / Auftragsbestätigung
 * (PDF, new tab) · Lieferschein … · Rechnungsvorschau (PDF) · Status ändern … · Buchungsdruck …
 * (only `invoiceable` without an invoice — a plain link that leaves the window, the
 * DEBTOR-WIN-002 pattern) · Abbrechen · «N Fehler».
 *
 * RADIO TABS (css-backend.md, `components/_radiotabs.scss`) over the sections of the one form:
 * Auftrag (head, positions, totals) · Verlauf (the status log) · Belege (the invoice with its
 * payment state). In a window the tab labels stand INSIDE the radio-tab container, after the
 * radios (the sibling variant of `_radiotabs.scss` — a window has no toolbar). A new order has
 * only the first section and no tabs.
 *
 * Read-only (D7: an invoice is booked, or the status is final): the head and the positions sit
 * in a disabled fieldset, no «Speichern», the reason on top. No explanations in the form
 * (ADR-048) — the help (`edit.help`) has a section per field.
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
 * @var list<\Z77\Module\Debtor\Entities\DebtorProfile> $debtors
 * @var list<\Z77\Module\Contact\Entities\Contact> $candidates  active contacts without a debtor profile (D9)
 * @var list<\Z77\Module\Debtor\Entities\PaymentTarget> $targets
 * @var array<string, string> $ships  delivery-address choice → label
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
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
 * @var string $orderTitle  «Offerte» / «Auftragsbestätigung» (by the flag `offer`)
 * @var bool $canBook
 * @var \Z77\Module\Order\Entities\OrderStatus|null $deliveryTarget
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
$title      = $isNew ? 'Neuer Auftrag' : 'Auftrag ' . $order->documentNumber() . ' · ' . $order->getContact()->displayName();
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

// The action row's further controls (ADR-049): PDFs in a new tab, the confirm windows in THIS window
// (`data-window-link`), the booking print a plain link that leaves the window (DEBTOR-WIN-002 pattern).
$extra = '';
if (!$isNew) {
    $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/pdf?id=' . $id) . '" target="_blank" rel="noopener" title="' . e($orderTitle) . ' als PDF (neuer Tab)">' . e($orderTitle) . '</a>';
    if (!($status?->isOffer() ?? false)) {
        $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/confirm-delivery-note?id=' . $id) . '" data-window-link>Lieferschein …</a>';
    }
    if ($invoice === null) {
        $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/invoice-preview?id=' . $id) . '" target="_blank" rel="noopener" title="Die Rechnung, wie der Buchungsdruck sie heute erstellen würde — ohne Nummer und ohne Zahlteil">Rechnungsvorschau</a>';
    }
    $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/status?id=' . $id) . '" data-window-link>Status ändern …</a>';
    if ($canBook) {
        $extra .= '<a class="be-btn be-btn--confirm" href="' . e($actionBase . '/confirm-book?' . http_build_query(['order' => [$id . ':' . $order->getVersion()]])) . '" title="Rechnung erstellen, verbuchen und als PDF drucken">Buchungsdruck …</a>';
    }
}
$tabs = $isNew ? [] : [1 => 'Auftrag', 2 => 'Verlauf', 3 => 'Belege'];
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section<?= $tabs !== [] ? ' be-radiotabs' : '' ?>" id="order-form-<?= e($key) ?>" autocomplete="off" novalidate>
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
            'cancelHref'  => $window ? '' : $actionBase . '/list',
            'errors'      => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>
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
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-<?= e($key) ?>-stock_reason">Grund der Rückbuchung</label>
                <textarea id="order-<?= e($key) ?>-stock_reason" name="stock_reason" rows="2" maxlength="255" required aria-invalid="true"></textarea>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($tabs as $n => $label): ?>
        <input type="radio" class="be-radiotabs__radio" name="order_tab" id="order-<?= e($key) ?>-tab-<?= $n ?>" data-tab="<?= $n ?>"<?= $n === 1 ? ' checked' : '' ?>>
        <?php endforeach; ?>
        <?php if ($tabs !== []): ?>
        <div class="be-viewtabs" role="group" aria-label="Abschnitte">
            <?php foreach ($tabs as $n => $label): ?>
            <label class="be-viewtabs__tab be-viewtabs__tab--for" for="order-<?= e($key) ?>-tab-<?= $n ?>" data-tab="<?= $n ?>"><?= e($label) ?><?= $n === 2 ? ' <small>' . count($history) . '</small>' : '' ?><?= $n === 3 ? ' <small>' . ($invoice !== null ? 1 + count($creditNotes) : 0) . '</small>' : '' ?></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <section<?= $tabs !== [] ? ' class="be-radiotabs__panel" data-tab="1"' : '' ?> aria-label="Auftrag">
        <fieldset class="be-form__fieldset"<?= $editable ? '' : ' disabled' ?> style="border: 0; padding: 0; margin: 0; min-width: 0">
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="invoice-contact">Kunde</label>
                <?= $this->partial('Backend/InvoiceController/_debtorSelect', [
                    'debtors'    => $debtors,
                    'candidates' => $candidates,
                    'selected'   => $form->header('contact'),
                    'invalid'    => $form->error('contact') !== '',
                ], 'Z77\\Module\\Debtor') ?>
                <?php if ($editable): ?>
                <?php // «+ Kontakt»: a window over this one; its save replaces the select above — the order stays as typed. ?>
                <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/new-contact') ?>" data-window-open="<?= e($actionBase . '/new-contact') ?>">+ Kontakt</a>
                <?php endif; ?>
                <?= raw($fieldError($form->error('contact'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-subject">Betreff</label>
                <input type="text" id="order-subject" name="subject" value="<?= e($form->header('subject')) ?>" maxlength="<?= \Z77\Module\Order\Entities\SalesOrder::SUBJECT_LENGTH ?>" aria-invalid="<?= $invalid($form->error('subject')) ?>">
                <?= raw($fieldError($form->error('subject'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-order_date">Auftragsdatum</label>
                <input type="date" id="order-order_date" name="order_date" value="<?= e($form->header('order_date')) ?>" required aria-invalid="<?= $invalid($form->error('order_date')) ?>">
                <?= raw($fieldError($form->error('order_date'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-service_from">Leistung von</label>
                <input type="date" id="order-service_from" name="service_from" value="<?= e($form->header('service_from')) ?>" aria-invalid="<?= $invalid($form->error('service_from')) ?>">
                <?= raw($fieldError($form->error('service_from'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-service_to">Leistung bis</label>
                <input type="date" id="order-service_to" name="service_to" value="<?= e($form->header('service_to')) ?>" aria-invalid="<?= $invalid($form->error('service_to')) ?>">
                <?= raw($fieldError($form->error('service_to'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-status">Status</label>
                <?php if ($isNew): ?>
                <select id="order-status" name="status" aria-invalid="<?= $invalid($form->error('status')) ?>">
                    <?php foreach ($startable as $row): ?>
                    <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $form->header('status') ? ' selected' : '' ?>><?= e($row->getLabel()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="text" id="order-status" value="<?= e($status?->getLabel() ?? $order->getStatusCode()) ?>" readonly>
                <?php endif; ?>
                <?= raw($fieldError($form->error('status'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-ship">Lieferadresse</label>
                <select id="order-ship" name="ship" aria-invalid="<?= $invalid($form->error('ship')) ?>">
                    <?php foreach ($ships as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>"<?= (string) $value === $form->header('ship') ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError($form->error('ship'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-price_mode">Preise</label>
                <select id="order-price_mode" name="price_mode" aria-invalid="<?= $invalid($form->error('price_mode')) ?>">
                    <option value="net"<?= $form->header('price_mode') === 'net' ? ' selected' : '' ?>>netto</option>
                    <option value="gross"<?= $form->header('price_mode') === 'gross' ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                </select>
                <?= raw($fieldError($form->error('price_mode'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-target">Zahlungsziel</label>
                <select id="order-target" name="target" aria-invalid="<?= $invalid($form->error('target')) ?>">
                    <option value="">kein Zahlteil</option>
                    <?php foreach ($targets as $row): ?>
                    <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $form->header('target') ? ' selected' : '' ?>><?= e($row->getLabel()) ?> (<?= $row->hasQrIban() ? 'QR-IBAN' : 'IBAN' ?>)<?= $row->isActive() ? '' : ' (inaktiv)' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError($form->error('target'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-notes">Notiz (intern)</label>
                <textarea id="order-notes" name="notes" rows="1" maxlength="<?= \Z77\Module\Order\Entities\SalesOrder::NOTES_LENGTH ?>" aria-invalid="<?= $invalid($form->error('notes')) ?>"><?= e($form->header('notes')) ?></textarea>
                <?= raw($fieldError($form->error('notes'))) ?>
            </div>
        </div>

        <div class="be-form__section">Positionen</div>
        <datalist id="order-articles-<?= e($key) ?>">
            <?php foreach ($articles as $number => $article): ?>
            <?php if ($article['offered']): ?>
            <option value="<?= e((string) $number) ?>"><?= e($article['name'] . ($article['unit'] !== '' ? ' · ' . $article['unit'] : '')) ?></option>
            <?php endif; ?>
            <?php endforeach; ?>
        </datalist>
        <?= $this->partial('partials/accountDatalist', ['id' => 'order-accounts-' . $key, 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>
        <div class="be-list__frame">
            <div class="be-list__table" style="--be-list-cols: 7rem 2.5rem 6rem minmax(11rem, 3fr) 4.5rem 4rem 6.5rem 4rem 6.5rem 5.5rem">
                <div class="be-list__head">
                    <span class="be-list__col">Art</span>
                    <span class="be-list__col" title="unter der Position darüber">unter</span>
                    <span class="be-list__col">Artikel</span>
                    <span class="be-list__col">Text</span>
                    <span class="be-list__col be-list__col--num">Menge</span>
                    <span class="be-list__col">Einheit</span>
                    <span class="be-list__col be-list__col--num">Preis</span>
                    <span class="be-list__col be-list__col--num">Rabatt %</span>
                    <span class="be-list__col">MWST</span>
                    <span class="be-list__col">Konto</span>
                </div>
                <?php foreach ($form->rows() as $i => $row): $p = 'rows[' . $i . ']'; $rid = 'order-row-' . $i . '-'; $art = $row['article'] !== '' ? ($articles[$row['article']] ?? null) : null; ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell">
                            <?php if ($row['id'] !== ''): ?><input type="hidden" name="<?= $p ?>[id]" value="<?= e($row['id']) ?>"><?php endif; ?>
                            <select class="be-input be-input--sm" id="<?= $rid ?>type" name="<?= $p ?>[type]" data-help-key="type" aria-label="Art Zeile <?= $i + 1 ?>">
                                <?php foreach (OrderForm::TYPES as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $row['type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                        <span class="be-list__cell"><input type="checkbox" id="<?= $rid ?>child" name="<?= $p ?>[child]" value="1"<?= $row['child'] !== '' ? ' checked' : '' ?> data-help-key="child" aria-label="Zeile <?= $i + 1 ?> unter der Position darüber" aria-invalid="<?= $invalid($form->rowError($i, 'child')) ?>"></span>
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>article" name="<?= $p ?>[article]" value="<?= e($row['article']) ?>" list="order-articles-<?= e($key) ?>" spellcheck="false" data-help-key="article" aria-label="Artikel Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'article')) ?>"<?= $art !== null ? ' title="' . e($art['name']) . '"' : '' ?>></span>
                        <span class="be-list__cell"><textarea class="be-input be-input--sm" id="<?= $rid ?>text" name="<?= $p ?>[text]" rows="1" data-help-key="text" aria-label="Text Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'text')) ?>"><?= e($row['text']) ?></textarea></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>quantity" name="<?= $p ?>[quantity]" value="<?= e($row['quantity']) ?>" inputmode="decimal" data-help-key="quantity" aria-label="Menge Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'quantity')) ?>"></span>
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>unit" name="<?= $p ?>[unit]" value="<?= e($row['unit']) ?>" maxlength="16" data-help-key="unit" aria-label="Einheit Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'unit')) ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>price" name="<?= $p ?>[price]" value="<?= e($row['price']) ?>" inputmode="decimal" data-help-key="price" aria-label="Preis Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'price')) ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>discount" name="<?= $p ?>[discount]" value="<?= e($row['discount']) ?>" inputmode="decimal" data-help-key="discount" aria-label="Rabatt Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'discount')) ?>"></span>
                        <?php if ($art !== null): ?>
                        <span class="be-list__cell be-list__cell--muted" title="vom Artikel"><?= e($art['taxCode']) ?></span>
                        <span class="be-list__cell be-list__cell--muted be-list__cell--mono" title="vom Artikel"><?= e($art['account']) ?></span>
                        <?php else: ?>
                        <span class="be-list__cell"><?= $this->partial('partials/taxCodeSelect', [
                            'codes' => $taxCodes, 'name' => $p . '[tax_code]', 'selected' => $row['tax_code'], 'id' => $rid . 'tax_code',
                            'label' => 'MWST-Code Zeile ' . ($i + 1), 'invalid' => $form->rowError($i, 'tax_code') !== '',
                        ], 'Z77\\Module\\Vat') ?></span>
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $rid ?>account" name="<?= $p ?>[account]" value="<?= e($row['account']) ?>"<?= $accounts !== [] ? ' list="order-accounts-' . e($key) . '"' : '' ?> inputmode="numeric" data-help-key="account" aria-label="Konto Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'account')) ?>"></span>
                        <?php endif; ?>
                    </div>
                    <?php $messages = array_filter(array_map(fn($f) => $form->rowError($i, $f), ['type', 'child', 'article', 'text', 'quantity', 'unit', 'price', 'discount', 'tax_code', 'account'])); ?>
                    <?php if ($messages !== []): ?>
                    <div class="be-list__row">
                        <span class="be-list__cell be-list__cell--wrap" style="grid-column: 1 / -1">
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
        <p class="be-form__hint">
            <button type="submit" class="be-btn be-btn--ghost be-btn--sm" name="op" value="more">Weitere Zeilen</button>
        </p>
        <?php endif; ?>
        </fieldset>

        <?php if ($preview !== null): ?>
        <div class="be-form__section">Total <small>(gespeichert — so würde der Buchungsdruck heute fakturieren)</small></div>
        <div class="be-list__table" style="--be-list-cols: minmax(12rem, 1fr) 5rem 8rem">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell">Netto</span><span class="be-list__cell"></span><span class="be-list__cell be-list__cell--num"><?= e($fmt($preview->getNetTotal())) ?></span></div></div>
            <?php foreach ($preview->getTaxes() as $tax): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">MWST <?= e(TaxRate::formatPercent($tax->getTaxRate())) ?> % auf <?= e($fmt($tax->getBase())) ?></span><span class="be-list__cell be-list__cell--muted"><?= e($tax->getTaxCode()) ?></span><span class="be-list__cell be-list__cell--num"><?= e($fmt($tax->getTax())) ?></span></div></div>
            <?php endforeach; ?>
            <?php if (!$preview->getRounding()->isZero()): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Rundung</span><span class="be-list__cell"></span><span class="be-list__cell be-list__cell--num"><?= e($fmt($preview->getRounding())) ?></span></div></div>
            <?php endif; ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell"><strong>Total <?= e($preview->getCurrency()) ?></strong></span><span class="be-list__cell"></span><span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($preview->getGrossTotal())) ?></strong></span></div></div>
        </div>
        <?php endif; ?>
        <?php if ($previewProblems !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <strong>Vor dem Buchungsdruck beheben:</strong>
            <?php foreach ($previewProblems as $problem): ?>
            <div><?= e($problem) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </section>

        <?php if ($tabs !== []): ?>
        <section class="be-radiotabs__panel" data-tab="2" aria-label="Verlauf">
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
        </section>

        <section class="be-radiotabs__panel" data-tab="3" aria-label="Belege">
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
        </section>
        <?php endif; ?>
    </form>
</div>
