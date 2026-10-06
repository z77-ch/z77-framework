<?php
/**
 * The document editor (P3 part 3) — a new invoice, «neu fakturieren» of a
 * document in `invoicing`, or a credit note against a final invoice. A PAGE
 * with a plain form, no JavaScript (Rule 7): a fixed number of line rows,
 * «Weitere Zeilen» submits and comes back with more. `csrf_token` is the
 * page-mode field (`#[Csrf]`); an edit also carries the entity token and the
 * VERSION it was rendered from (the optimistic lock of `reinvoice()`).
 *
 * The action bar (ADR-049) stands first: Speichern · Abbrechen · «N Fehler».
 * No explanations in the form (ADR-048) — the rules are the help
 * (`form.help`). The pickers are shared building blocks: the tax code
 * (`partials/taxCodeSelect`, module-vat — active codes plus the ones the
 * document carries) and the revenue account (`partials/accountDatalist`,
 * module-mandator — the postable, active accounts; a plain text field
 * without module-financial).
 *
 * @var \Z77\Module\Debtor\Ui\InvoiceForm $form
 * @var \Z77\Module\Debtor\Entities\Invoice|null $document  edit only
 * @var int|null $version  edit only
 * @var string $entityCsrf  edit only
 * @var list<\Z77\Module\Debtor\Entities\DebtorProfile> $debtors  a new invoice only
 * @var list<\Z77\Module\Debtor\Entities\PaymentTerms> $terms
 * @var list<\Z77\Module\Debtor\Entities\PaymentTarget> $targets
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
 * @var list<array{number: string, label: string}> $accounts
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 */
use Z77\Module\Debtor\Ui\InvoiceForm;

$actionBase = $actionBase ?? '/backend/finance/invoice';
$isCredit   = $form->kind === \Z77\Module\Debtor\Entities\InvoiceKind::CreditNote;
$isEdit     = $document !== null;
$action     = $isEdit ? $actionBase . '/edit?id=' . (int) $document->getId()
    : ($isCredit ? $actionBase . '/credit-note?of=' . (int) $form->creditNoteOf->getId() : $actionBase . '/add');
$cancel     = $isEdit ? $actionBase . '/detail?id=' . (int) $document->getId()
    : ($isCredit ? $actionBase . '/detail?id=' . (int) $form->creditNoteOf->getId() : $actionBase . '/list');
$title      = $isEdit ? $document->documentName() . ' neu fakturieren'
    : ($isCredit ? 'Gutschrift zu ' . $form->creditNoteOf->documentName() : 'Rechnung erstellen');
$party      = $isEdit ? $document->getContact() : ($isCredit ? $form->creditNoteOf->getContact() : null);
$invalidIds = $form->invalidIds();
$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
$invalid    = static fn(string $message): string => $message !== '' ? 'true' : 'false';
?>
<div class="be-list">
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="invoice-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($isEdit): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) ($version ?? $document->getVersion()) ?>">
        <?php endif; ?>

        <?php /* The action bar (ADR-049): first in the document, so Enter saves (not «Weitere Zeilen»). */ ?>
        <div class="z77-form-actions">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm" name="op" value="save"><?= $isEdit ? 'Neu fakturieren' : 'Erstellen' ?></button>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($cancel) ?>">Abbrechen</a>
            <?= $this->partial('partials/formErrorsLink', ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''], 'Z77\\Shared') ?>
        </div>

        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>

        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="invoice-contact">Debitor</label>
                <?php if ($party !== null): ?>
                <input type="text" id="invoice-contact" value="<?= e($party->displayName()) ?>" readonly>
                <input type="hidden" name="contact" value="<?= (int) $party->getId() ?>">
                <?php else: ?>
                <select id="invoice-contact" name="contact" required aria-invalid="<?= $invalid($form->error('contact')) ?>">
                    <option value="">–</option>
                    <?php foreach ($debtors as $profile): $contact = $profile->getContact(); ?>
                    <option value="<?= (int) $contact->getId() ?>"<?= (string) $contact->getId() === $form->header('contact') ? ' selected' : '' ?>><?= e($contact->displayName()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?= raw($fieldError($form->error('contact'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="invoice-invoice_date"><?= $isCredit ? 'Gutschriftsdatum' : 'Rechnungsdatum' ?></label>
                <input type="date" id="invoice-invoice_date" name="invoice_date" value="<?= e($form->header('invoice_date')) ?>" required aria-invalid="<?= $invalid($form->error('invoice_date')) ?>">
                <?= raw($fieldError($form->error('invoice_date'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="invoice-service_from">Leistung ab</label>
                <input type="date" id="invoice-service_from" name="service_from" value="<?= e($form->header('service_from')) ?>"<?= $isCredit ? '' : ' required' ?> aria-invalid="<?= $invalid($form->error('service_from')) ?>">
                <?= raw($fieldError($form->error('service_from'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="invoice-service_to">Leistung bis</label>
                <input type="date" id="invoice-service_to" name="service_to" value="<?= e($form->header('service_to')) ?>" aria-invalid="<?= $invalid($form->error('service_to')) ?>">
                <?= raw($fieldError($form->error('service_to'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="invoice-price_mode">Preise</label>
                <select id="invoice-price_mode" name="price_mode" aria-invalid="<?= $invalid($form->error('price_mode')) ?>">
                    <option value="net"<?= $form->header('price_mode') === 'net' ? ' selected' : '' ?>>netto</option>
                    <option value="gross"<?= $form->header('price_mode') === 'gross' ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                </select>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="invoice-terms">Zahlungskonditionen</label>
                <select id="invoice-terms" name="terms">
                    <option value="">wie beim Debitor</option>
                    <?php foreach ($terms as $row): ?>
                    <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $form->header('terms') ? ' selected' : '' ?>><?= e($row->getLabel()) ?><?= $row->isActive() ? '' : ' (inaktiv)' ?></option>
                    <?php endforeach; ?>
                </select>
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

        <div class="be-form__section">Positionen</div>
        <?= $this->partial('partials/accountDatalist', ['id' => 'invoice-accounts', 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>
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
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell">
                            <select class="be-input be-input--sm" id="<?= $id ?>type" name="<?= $p ?>[type]" aria-label="Art Zeile <?= $i + 1 ?>">
                                <?php foreach (InvoiceForm::TYPES as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $row['type'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                        <span class="be-list__cell"><input type="checkbox" id="<?= $id ?>child" name="<?= $p ?>[child]" value="1"<?= $row['child'] !== '' ? ' checked' : '' ?> aria-label="Zeile <?= $i + 1 ?> unter der Position darüber" aria-invalid="<?= $invalid($form->rowError($i, 'child')) ?>"></span>
                        <span class="be-list__cell"><textarea class="be-input be-input--sm" id="<?= $id ?>text" name="<?= $p ?>[text]" rows="1" aria-label="Text Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'text')) ?>"><?= e($row['text']) ?></textarea></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $id ?>quantity" name="<?= $p ?>[quantity]" value="<?= e($row['quantity']) ?>" inputmode="decimal" aria-label="Menge Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'quantity')) ?>"></span>
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $id ?>unit" name="<?= $p ?>[unit]" value="<?= e($row['unit']) ?>" maxlength="16" aria-label="Einheit Zeile <?= $i + 1 ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $id ?>price" name="<?= $p ?>[price]" value="<?= e($row['price']) ?>" inputmode="decimal" aria-label="Preis Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'price')) ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="<?= $id ?>discount" name="<?= $p ?>[discount]" value="<?= e($row['discount']) ?>" inputmode="decimal" aria-label="Rabatt Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'discount')) ?>"></span>
                        <span class="be-list__cell"><?= $this->partial('partials/taxCodeSelect', [
                            'codes' => $taxCodes, 'name' => $p . '[tax_code]', 'selected' => $row['tax_code'], 'id' => $id . 'tax_code',
                            'label' => 'MWST-Code Zeile ' . ($i + 1), 'invalid' => $form->rowError($i, 'tax_code') !== '',
                        ], 'Z77\\Module\\Vat') ?></span>
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="<?= $id ?>account" name="<?= $p ?>[account]" value="<?= e($row['account']) ?>"<?= $accounts !== [] ? ' list="invoice-accounts"' : '' ?> inputmode="numeric" aria-label="Konto Zeile <?= $i + 1 ?>" aria-invalid="<?= $invalid($form->rowError($i, 'account')) ?>"></span>
                    </div>
                    <?php $messages = array_filter(array_map(fn($f) => $form->rowError($i, $f), ['child', 'text', 'quantity', 'price', 'discount', 'tax_code', 'account'])); ?>
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
        <p class="be-form__hint">
            <button type="submit" class="be-btn be-btn--ghost be-btn--sm" name="op" value="more">Weitere Zeilen</button>
        </p>
    </form>
</div>
