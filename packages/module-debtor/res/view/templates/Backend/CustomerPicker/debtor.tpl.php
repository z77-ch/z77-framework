<?php
/**
 * «Debitor anlegen» (`Ui\CustomerPickerTrait::customerDebtorAction()`, owner decision 2026-10-18 —
 * no document for a contact without a debtor profile): the contact chosen in the customer picker,
 * or just captured with «+ Kontakt», is no debtor yet — before it becomes the customer of the
 * order or the invoice, it becomes a debtor with the PRICE MODE and the PAYMENT TERMS asked here
 * (preset: net and the terms a new debtor gets). The customer number is drawn on «Debitor
 * anlegen». «Abbrechen» chooses no customer. Fetched: the content of the picker's (or the
 * contact's) window — its save fills the editor and closes it; without the script a page that
 * returns to $back.
 *
 * @var \Z77\Module\Contact\Entities\Contact $contact
 * @var list<\Z77\Module\Debtor\Entities\PaymentTerms> $terms  the active terms (+ the one posted)
 * @var string $termsCode   the terms shown as chosen
 * @var string $priceMode   `net` | `gross` shown as chosen
 * @var \Z77\Persistence\Validation\EntityValidator|null $validator  a refused «Debitor anlegen»
 * @var string $debtorUrl   the host's `customer-debtor` URL
 * @var string $origin      the editor window (WindowOrigin) — travels back as `_origin`
 * @var string $back        the editor's own URL (the way back without the script)
 * @var bool   $window
 * @var string $csrfToken provided by html()
 */
$window  = !empty($window);
$name    = $contact->displayName();
$winAttr = $window ? ' data-window="customer-debtor" data-window-title="Debitor anlegen" data-window-width="36rem"' : '';
$error   = static fn(string $field): string => $validator !== null && $validator->hasFieldError($field)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($field)) . '</small>'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($debtorUrl) ?>" class="be-list__section" id="customer-debtor" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="_origin" value="<?= e($origin) ?>">
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <input type="hidden" name="contact" value="<?= (int) $contact->getId() ?>">

        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Debitor anlegen',
            // In a window «Abbrechen» closes it — no customer is chosen. As a page: back to the editor.
            'cancelHref' => $window ? '' : $back,
        ], 'Z77\\Shared') ?>

        <div class="be-list__section-header">
            <h2 class="be-list__section-title">«<?= e($name) ?>» ist noch kein Debitor — Debitor anlegen</h2>
        </div>

        <?php if ($validator !== null && $validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $message): ?>
            <div><?= e($message) ?></div>
            <?php endforeach; ?>
            <?php if ($validator->hasFieldError('contact_id')): ?><div><?= e($validator->getFieldError('contact_id')) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="be-form__grid">
            <div class="be-form__field">
                <label>Kundennummer</label>
                <input type="text" value="wird beim Anlegen vergeben" readonly>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="customer-debtor-price_mode">Preise</label>
                <select id="customer-debtor-price_mode" name="price_mode" aria-invalid="<?= $error('price_mode') !== '' ? 'true' : 'false' ?>">
                    <option value="net"<?= $priceMode !== 'gross' ? ' selected' : '' ?>>netto (exkl. MWST)</option>
                    <option value="gross"<?= $priceMode === 'gross' ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                </select>
                <?= raw($error('price_mode')) ?>
                <small class="be-form__hint">Damit beginnt jeder Auftrag und jede Rechnung dieses Kunden — im Beleg änderbar.</small>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="customer-debtor-terms">Zahlungskonditionen</label>
                <select id="customer-debtor-terms" name="terms" required aria-invalid="<?= $error('payment_terms_code') !== '' ? 'true' : 'false' ?>">
                    <option value="">— wählen —</option>
                    <?php foreach ($terms as $row): ?>
                    <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $termsCode ? ' selected' : '' ?>><?= e($row->getLabel()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($error('payment_terms_code')) ?>
            </div>
        </div>
    </form>
</div>
