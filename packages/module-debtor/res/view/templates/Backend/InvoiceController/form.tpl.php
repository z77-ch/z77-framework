<?php
/**
 * The document editor (P3 part 3) — a new invoice, «Bearbeiten» of a
 * document in `invoicing`, or a credit note against a final invoice. A
 * plain form (Rule 7): a fixed number of line rows, «Weitere Zeilen» submits
 * and comes back with more. `csrf_token` is the page-mode field (`#[Csrf]`);
 * an edit also carries the entity token and the VERSION it was rendered from
 * (the optimistic lock of `reinvoice()`).
 *
 * LAYOUT = the order window's head (owner 2026-10-19; ADR-051 «cards on the
 * ground»): the form is the window ground (`.be-form--blocks`, `_forms.scss`),
 * its parts are CARDS. A head row of three blocks —
 *   (1) Kunde: the title «Kunde 20417» + phone (debtor's shared head,
 *       `CustomerPickerTrait::customerTitleHtml()`), the customer as text with
 *       «Kunde wechseln» (`CustomerPicker::fieldHtml(showNumber: false)`; no
 *       «+ Kontakt» — the picker window offers it) or, with a fixed party
 *       (edit, credit note), the name alone (`CustomerPicker/_fixed`); the card
 *       «Rechnung» with the address the save takes, read-only (`_billAddress` —
 *       a draft has no address choice of its own; no delivery card);
 *   (2) Betreff as the lead input, «Herkunft» of an existing document (an
 *       invoice has no notes);
 *   (3) Rechnungsdatum, «Leistung von | bis», «Zahlungskonditionen» with
 *       «Debitor anpassen» beside the label (the shared `_terms` / `_debtorEdit`,
 *       reloaded when the debtor dialog closes — `data-popup-refresh`), the
 *       Zahlungsziel (an invoice; a credit note has no payment part) —,
 * then the positions as one block with «Preise» in its head. «Preise» converts
 * the typed prices at once (core.js «price mode», owner 2026-10-19 — the block
 * is the scope, each row's MWST select carries its rate as `data-rate`,
 * following the service date through core.js «dated rates»); the hidden
 * `prices_mode` tells the server the mode the prices are in — without the
 * script the server converts them at the save (`InvoiceForm::toDraft()`).
 *
 * Fetched, a WINDOW (ADR-047, addendum 2026-10-10): the root declares the
 * mask `invoice-edit` (a credit note: `invoice-credit-note`) and the record,
 * the title moves into the title bar, the form carries `_origin` back, and
 * «Abbrechen» returns to the read view inside the window. Without the script
 * the same markup is the page it always was.
 *
 * The action row (ADR-049, revision 2026-10-10; the order window's, ADR-033
 * addendum 2026-10-18) is the shared `partials/modalActions`, FIRST in the
 * form — directly under the window's title bar: Erstellen / Speichern (core.js
 * «dirty forms»: quiet while nothing changed — `data-dirty`, `_dirty_base`
 * echoed through «Weitere Zeilen» and errors) · «N Fehler» · a quiet
 * «Abbrechen» at the end. First in the document, so Enter saves (not «Weitere
 * Zeilen»). No explanations in the form (ADR-048) — the rules are the help
 * (`form.help`). The pickers are shared building blocks: the tax code
 * (`partials/taxCodeSelect`, module-vat — active codes plus the ones the
 * document carries) and the revenue account (`partials/accountDatalist`,
 * module-mandator — `LedgerAccountCheck::documentAccounts()`: the postable,
 * active accounts of type «Ertrag» flagged «auf Belegen wählbar» only,
 * the service refuses any other on an invoice; a credit note adds the revenue
 * accounts of the invoice it corrects, owner 2026-10-13; a stored other value still shows; a plain text field
 * without module-financial).
 *
 * @var \Z77\Module\Debtor\Ui\InvoiceForm $form
 * @var \Z77\Module\Debtor\Entities\Invoice|null $document  edit only
 * @var int|null $version  edit only
 * @var string $entityCsrf  edit only
 * @var string $dirtyBase  the posted `_dirty_base` echoed back (core.js «dirty forms»), '' on a GET
 * @var string $customerField  a new invoice only — `CustomerPicker::fieldHtml()`: the chosen customer + «Kunde wählen …»
 * @var string $customerTitle  block (1)'s title — debtor's shared `CustomerPicker/_customerTitle` (`#invoice-customer-title`)
 * @var string $billAddress  the card «Rechnung» — `_billAddress` (`#invoice-bill-address`)
 * @var string $termsSelect  «Zahlungskonditionen» — the shared `CustomerPicker/_terms` (`#invoice-terms`, «wie beim Debitor — …»)
 * @var string $debtorEdit  «Debitor anpassen» — the shared `CustomerPicker/_debtorEdit` (`#invoice-debtor-edit`)
 * @var list<\Z77\Module\Debtor\Entities\PaymentTarget> $targets
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
 * @var array<string, string> $taxRates  code → rate in percent on the service date («8.1») — `data-rate`
 * @var array<string, list<array{0: string, 1: string}>> $rateSchedule  code → [[valid from, rate], …] for core.js «dated rates»
 * @var list<array{number: string, label: string}> $accounts
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 * @var bool   $window       fetched as a window (ADR-047)
 * @var string $origin       where the window came from (WindowOrigin) — travels back as `_origin`
 * @var string $windowWidth  the window's width, the controller's call
 */
use Z77\Module\Debtor\Ui\InvoiceForm;

$actionBase = $actionBase ?? '/backend/finance/invoice';
$isCredit   = $form->kind === \Z77\Module\Debtor\Entities\InvoiceKind::CreditNote;
$isEdit     = $document !== null;
$isNew      = !$isEdit;
$action     = $isEdit ? $actionBase . '/edit?id=' . (int) $document->getId()
    : ($isCredit ? $actionBase . '/credit-note?of=' . (int) $form->creditNoteOf->getId() : $actionBase . '/add');
$cancel     = $isEdit ? $actionBase . '/detail?id=' . (int) $document->getId()
    : ($isCredit ? $actionBase . '/detail?id=' . (int) $form->creditNoteOf->getId() : $actionBase . '/list');
$title      = $isEdit ? $document->documentName() . ' bearbeiten'
    : ($isCredit ? 'Gutschrift zu ' . $form->creditNoteOf->documentName() : 'Rechnung erstellen');
$party      = $isEdit ? $document->getContact() : ($isCredit ? $form->creditNoteOf->getContact() : null);
$invalidIds = $form->invalidIds();
$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
$invalid    = static fn(string $message): string => $message !== '' ? 'true' : 'false';

$window  = !empty($window);
$winAttr = '';
if ($window) {
    // A new invoice has no record yet: mask only. A credit note is a mask of its own on the invoice it credits.
    $winAttr = ' data-window="' . ($isCredit && !$isEdit ? 'invoice-credit-note' : 'invoice-edit') . '"'
        . ($isEdit ? ' data-window-entity="invoice:' . (int) $document->getId() . '"' : ($isCredit ? ' data-window-entity="invoice:' . (int) $form->creditNoteOf->getId() . '"' : ''))
        . ' data-window-title="' . e($title) . '"'
        . (!empty($windowWidth) ? ' data-window-width="' . e($windowWidth) . '"' : '');
}
// Cancel: the read view. Inside a window a new invoice has none — the button closes the window.
$cancelHref = $window && !$isEdit && !$isCredit ? '' : $cancel;
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-form--blocks" id="invoice-form" autocomplete="off" data-dirty="<?= $isNew ? 'dirty' : 'clean' ?>">
        <input type="hidden" name="_dirty_base" value="<?= e($dirtyBase ?? '') ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if ($isEdit): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) ($version ?? $document->getVersion()) ?>">
        <?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'      => $isEdit ? 'Speichern' : 'Erstellen',
            'submitAttrs' => ['name' => 'op', 'value' => 'save', 'data-dirty-submit' => ''],
            'extra'       => '<span class="z77-form-actions__spacer"></span>',
            'cancelKind'  => 'quiet',
            'cancelHref'  => $cancelHref,
            'errors'      => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <h2 class="be-list__section-title"><?= e($title) ?></h2>
        <?php endif; ?>

        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="be-form__blocks">
            <?php // (1) Kunde — the customer and the address the document goes to. ?>
            <div class="be-form__block">
                <div class="be-form__block-head">
                    <?php // «Kunde 20417» + phone (debtor's shared `_customerTitle`, `#invoice-customer-title`) — «Kunde wechseln» replaces it. ?>
                    <?= raw($customerTitle) ?>
                </div>
                <div data-z77-field-wrapper>
                    <?php if ($party !== null): ?>
                    <?php // The party is fixed — the form takes it from the document, not from this field (InvoiceForm::fromPost()). ?>
                    <input type="hidden" name="contact" value="<?= (int) $party->getId() ?>">
                    <?= $this->partial('Backend/CustomerPicker/_fixed', ['name' => $party->displayName()], 'Z77\Module\Debtor') ?>
                    <?php else: ?>
                    <?php // The customer field: the name, «Kunde wechseln» opens the searching picker over this window
                          // (CustomerPicker); no «+ Kontakt» here — the picker window offers it (owner 2026-10-19). ?>
                    <?= raw($customerField) ?>
                    <?php endif; ?>
                    <?= raw($fieldError($form->error('contact'))) ?>
                </div>
                <div class="be-form__card">
                    <div class="be-form__card-head">
                        <span class="be-form__card-label">Rechnung</span>
                    </div>
                    <?= raw($billAddress) ?>
                </div>
            </div>

            <?php // (2) What it is about. ?>
            <div class="be-form__block">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="invoice-subject">Betreff</label>
                    <input type="text" class="be-form__input--lead" id="invoice-subject" name="subject" value="<?= e($form->header('subject')) ?>" maxlength="<?= \Z77\Module\Debtor\Entities\Invoice::SUBJECT_LENGTH ?>" aria-invalid="<?= $invalid($form->error('subject')) ?>">
                    <?= raw($fieldError($form->error('subject'))) ?>
                </div>
                <?php if ($isEdit): ?>
                <div class="be-form__field">
                    <span class="be-form__card-label">Herkunft</span>
                    <div class="be-form__fact be-form__fact--mono"><span class="badge badge--primary"><?= e($document->getSourceType() ?? 'Backend') ?></span><?= $document->getSourceRef() !== null ? e($document->getSourceRef()) . ' · ' : '' ?>erfasst <?= e($document->getCreatedAt()->format('d.m.Y')) ?>, <?= e($document->getCreatedBy()) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php // (3) When, and how it is paid. ?>
            <div class="be-form__block">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="invoice-invoice_date"><?= $isCredit ? 'Gutschriftsdatum' : 'Rechnungsdatum' ?></label>
                    <input type="date" id="invoice-invoice_date" name="invoice_date" value="<?= e($form->header('invoice_date')) ?>" required aria-invalid="<?= $invalid($form->error('invoice_date')) ?>">
                    <?= raw($fieldError($form->error('invoice_date'))) ?>
                </div>
                <div class="be-form__pair">
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="invoice-service_from">Leistung von</label>
                        <input type="date" id="invoice-service_from" name="service_from" value="<?= e($form->header('service_from')) ?>"<?= $isCredit ? '' : ' required' ?> aria-invalid="<?= $invalid($form->error('service_from')) ?>">
                        <?= raw($fieldError($form->error('service_from'))) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="invoice-service_to">bis</label>
                        <input type="date" id="invoice-service_to" name="service_to" value="<?= e($form->header('service_to')) ?>" aria-invalid="<?= $invalid($form->error('service_to')) ?>">
                        <?= raw($fieldError($form->error('service_to'))) ?>
                    </div>
                </div>
                <?php // The payment terms — «Debitor anpassen» right of the label changes the customer's
                      // standing terms; the select reloads when that dialog closes (the shared head). ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <div class="be-form__card-head">
                        <label for="invoice-terms">Zahlungskonditionen</label>
                        <?= raw($debtorEdit) ?>
                    </div>
                    <?= raw($termsSelect) ?>
                    <?= raw($fieldError($form->error('terms'))) ?>
                </div>
                <?php if (!$isCredit): ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="invoice-target">Zahlungsziel</label>
                    <select id="invoice-target" name="target">
                        <option value="">kein Zahlteil</option>
                        <?php foreach ($targets as $row): ?>
                        <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $form->header('target') ? ' selected' : '' ?>><?= e($row->getLabel()) ?> (<?= $row->hasQrIban() ? 'QR-IBAN' : 'IBAN' ?>)<?= $row->isActive() ? '' : ' (inaktiv)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // The positions: one block, «Preise» in its head (the order window's place, owner 2026-10-19). ?>
        <div class="be-form__block" data-price-mode>
            <div class="be-form__block-head">
                <h3 class="be-list__section-title">Positionen</h3>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="invoice-price_mode">Preise</label>
                    <select id="invoice-price_mode" name="price_mode" data-price-mode-select aria-invalid="<?= $invalid($form->error('price_mode')) ?>">
                        <option value="net"<?= $form->header('price_mode') === 'net' ? ' selected' : '' ?>>netto (exkl. MWST)</option>
                        <option value="gross"<?= $form->header('price_mode') === 'gross' ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                    </select>
                    <?= raw($fieldError($form->error('price_mode'))) ?>
                    <input type="hidden" name="prices_mode" value="<?= e($form->header('prices_mode')) ?>" data-price-mode-shown>
                </div>
            </div>
            <?= $this->partial('partials/accountDatalist', ['id' => 'invoice-accounts', 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>
            <?php // The rate history for core.js «dated rates»: the rows' `data-rate` follows «Leistung von» (else the invoice date) live. ?>
            <script type="application/json" data-rate-schedule="invoice-vat" data-rate-date="service_from invoice_date"><?= json_encode($rateSchedule ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <div class="be-list__frame">
                <div class="be-list__table" style="--be-list-cols: 7.5rem 3rem minmax(12rem, 3fr) 5rem 4.5rem 7rem 4.5rem 7rem 6rem">
                    <div class="be-list__head">
                        <span class="be-list__col">Art</span>
                        <span class="be-list__col" title="unter der Position darüber">unter</span>
                        <span class="be-list__col">Text</span>
                        <span class="be-list__col be-list__col--num">Menge</span>
                        <span class="be-list__col">Einheit</span>
                        <span class="be-list__col be-list__col--num">Preis</span>
                        <span class="be-list__col be-list__col--num">Rabatt %</span>
                        <span class="be-list__col">MWST</span>
                        <span class="be-list__col">Konto</span>
                    </div>
                    <?php foreach ($form->rows() as $i => $row): $p = 'rows[' . $i . ']'; $id = 'invoice-row-' . $i . '-'; ?>
                    <div class="be-list__item" data-row>
                        <div class="be-list__row">
                            <span class="be-list__cell">
                                <select class="be-input be-input--sm" id="<?= $id ?>type" name="<?= $p ?>[type]" aria-label="Art Zeile <?= $i + 1 ?>">
                                    <?php foreach (InvoiceForm::TYPES as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $row['type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </span>
                            <span class="be-list__cell"><label class="be-switch be-switch--sm"><input type="checkbox" class="be-switch__input" id="<?= $id ?>child" name="<?= $p ?>[child]" value="1"<?= $row['child'] !== '' ? ' checked' : '' ?> aria-label="Zeile <?= $i + 1 ?> unter der Position darüber" aria-invalid="<?= $invalid($form->rowError($i, 'child')) ?>"><span class="be-switch__track"><span class="be-switch__thumb"></span></span></label></span>
                            <span class="be-list__cell"><textarea class="be-input be-input--sm" id="<?= $id ?>text" name="<?= $p ?>[text]" rows="1" aria-label="Text Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'text')) ?>"><?= e($row['text']) ?></textarea></span>
                            <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $id ?>quantity" name="<?= $p ?>[quantity]" value="<?= e($row['quantity']) ?>" inputmode="decimal" aria-label="Menge Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'quantity')) ?>"></span>
                            <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $id ?>unit" name="<?= $p ?>[unit]" value="<?= e($row['unit']) ?>" maxlength="16" aria-label="Einheit Zeile <?= $i + 1 ?>"></span>
                            <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $id ?>price" name="<?= $p ?>[price]" value="<?= e($row['price']) ?>" inputmode="decimal" data-price-mode-price aria-label="Preis Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'price')) ?>"></span>
                            <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $id ?>discount" name="<?= $p ?>[discount]" value="<?= e($row['discount']) ?>" inputmode="decimal" aria-label="Rabatt Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'discount')) ?>"></span>
                            <span class="be-list__cell"><?= $this->partial('partials/taxCodeSelect', [
                                'codes' => $taxCodes, 'name' => $p . '[tax_code]', 'selected' => $row['tax_code'], 'id' => $id . 'tax_code',
                                'label' => 'MWST-Code Zeile ' . ($i + 1), 'invalid' => $form->rowError($i, 'tax_code') !== '',
                                // The rate per code for «Preise» (core.js «price mode»); the label stays the code.
                                'rates' => $taxRates ?? [],
                                'attrs' => ['data-price-mode-rate' => true, 'data-rate-schedule-ref' => 'invoice-vat', 'data-rate-keep-label' => true],
                            ], 'Z77\\Module\\Vat') ?></span>
                            <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $id ?>account" name="<?= $p ?>[account]" value="<?= e($row['account']) ?>"<?= $accounts !== [] ? ' list="invoice-accounts"' : '' ?> inputmode="numeric" aria-label="Konto Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'account')) ?>"></span>
                        </div>
                        <?php $messages = array_filter(array_map(fn($f) => $form->rowError($i, $f), ['child', 'text', 'quantity', 'price', 'discount', 'tax_code', 'account'])); ?>
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
            <div class="be-rows__adders">
                <button type="submit" class="be-btn be-btn--add be-btn--sm" name="op" value="more" formnovalidate><span aria-hidden="true">+</span> Weitere Zeilen</button>
            </div>
        </div>
    </form>
</div>
