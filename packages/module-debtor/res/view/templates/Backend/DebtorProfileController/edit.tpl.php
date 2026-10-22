<?php
/**
 * Edit the debtor profile of a contact. The CONTACT is fixed — shown, never
 * edited: a different party is a different profile. A NEW profile is not made
 * here (owner 2026-10-12): the contact editor's role «Debitor» opens it with the
 * default terms, or the first draft of an invoice does; «+ Debitor» and its
 * contact choice are gone. The payment-terms select offers the ACTIVE rows plus
 * the profile's own code when it was deactivated since (ADR-043
 * decision 19: a deactivated row may be kept, never newly chosen).
 *
 * @var \Z77\Module\Debtor\Entities\DebtorProfile $entry
 * @var \Z77\Module\Contact\Entities\Contact $contact
 * @var list<\Z77\Module\Debtor\Entities\PaymentTerms> $terms
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $actionBase
 * @var string|null $globalSubjectLabel  the installation's default «Betreffname» (the mandator's) — the placeholder of the debtor's own
 */
$actionBase = $actionBase ?? '/backend/finance/debtor-profile';

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post="<?= e($actionBase . '/edit?id=' . $entry->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Debitor bearbeiten</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Speichern'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($validator->getFieldErrors()): ?>Bitte überprüfe die markierten Eingaben.<?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Kontakt</label>
                <input type="text" value="<?= e($contact->displayName()) ?>" readonly>
                <?= raw($fieldError('contact_id')) ?>
                <small class="be-form__hint">Sprache des Belegs: <?= e(mb_strtoupper($contact->getLanguage())) ?> — sie steht am Kontakt, nicht am Debitor.</small>
            </div>
            <div class="be-form__field">
                <label>Kundennummer</label>
                <input type="text" value="<?= e((string) $entry->getCustomerNumber()) ?>" readonly>
                <small class="be-form__hint">Fortlaufend aus dem Nummernkreis, nie geändert — sie steht auf der QR-Referenz jeder Rechnung.</small>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Zahlungskonditionen</label>
                <select name="payment_terms_code" required aria-invalid="<?= $validator->hasFieldError('payment_terms_code') ? 'true' : 'false' ?>">
                    <option value="">— wählen —</option>
                    <?php foreach ($terms as $row): ?>
                    <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $entry->getPaymentTermsCode() ? ' selected' : '' ?>>
                        <?= e($row->getLabel()) ?><?= $row->isActive() ? '' : ' (inaktiv)' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('payment_terms_code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="debtor-price_mode">Preise</label>
                <select id="debtor-price_mode" name="price_mode" aria-invalid="<?= $validator->hasFieldError('price_mode') ? 'true' : 'false' ?>">
                    <option value="net"<?= $entry->getPriceMode() !== 'gross' ? ' selected' : '' ?>>netto (exkl. MWST)</option>
                    <option value="gross"<?= $entry->getPriceMode() === 'gross' ? ' selected' : '' ?>>brutto (inkl. MWST)</option>
                </select>
                <?= raw($fieldError('price_mode')) ?>
                <small class="be-form__hint">Damit beginnt jeder neue Auftrag und jede neue Rechnung dieses Kunden — im Beleg änderbar.</small>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="debtor-default_subject_label">Betreffname</label>
                <input type="text" id="debtor-default_subject_label" name="default_subject_label" value="<?= e((string) $entry->getDefaultSubjectLabel()) ?>"
                       maxlength="<?= \Z77\Module\Debtor\Invoicing\SubjectLabel::LENGTH ?>" autocomplete="off" placeholder="<?= e($globalSubjectLabel ?? '') ?>"
                       aria-invalid="<?= $validator->hasFieldError('default_subject_label') ? 'true' : 'false' ?>">
                <?= raw($fieldError('default_subject_label')) ?>
                <small class="be-form__hint">Steht vor dem Betreff jedes neuen Belegs, z.B. «Projekt» oder «Ihre Bestellnummer» — leer = die Vorgabe beim Mandanten<?= !empty($globalSubjectLabel) ? ' («' . e($globalSubjectLabel) . '»)' : '' ?>.</small>
            </div>
            <div class="be-form__field">
                <label class="be-choice">
                    <input type="checkbox" class="be-choice__input" name="dunning_block" value="1"<?= $entry->hasDunningBlock() ? ' checked' : '' ?>>
                    <span class="be-choice__label">Mahnsperre — kein Mahnlauf erfasst diesen Debitor</span>
                </label>
            </div>
        </div>
    </div>
</form>
