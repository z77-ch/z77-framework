<?php
/**
 * Add / edit the debtor profile of a contact. Once known, the CONTACT is
 * fixed — it travels in the URL and is shown, never edited: a different
 * party is a different profile. «+ Debitor» from the action cell opens the
 * dialog without one (`$contact` null): the first field is then the CHOICE
 * among the active contacts without a profile (`$candidates`, a select —
 * typing a name jumps to it, no script), posted as `contact_id`.
 * The payment-terms select offers the ACTIVE rows plus
 * the profile's own code when it was deactivated since (ADR-043
 * decision 19: a deactivated row may be kept, never newly chosen).
 *
 * @var \Z77\Module\Debtor\Entities\DebtorProfile $entry
 * @var \Z77\Module\Contact\Entities\Contact|null $contact  null = still to choose
 * @var list<\Z77\Module\Contact\Entities\Contact>|null $candidates  the choice when $contact is null
 * @var list<\Z77\Module\Debtor\Entities\PaymentTerms> $terms
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $actionBase
 */
$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/finance/debtor-profile';
$contact    = $contact ?? null;
$candidates = $candidates ?? [];
$target     = !$isNew ? 'edit?id=' . $entry->getId() : ($contact !== null ? 'add?contact=' . $contact->getId() : 'add');

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post="<?= e($actionBase . '/' . $target) ?>">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Debitor anlegen' : 'Debitor bearbeiten' ?></h2>
    </div>
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
                <?php if ($contact === null): ?>
                <label for="debtor-contact">Kontakt</label>
                <select id="debtor-contact" name="contact_id" required>
                    <option value="">— wählen —</option>
                    <?php foreach ($candidates as $candidate): ?>
                    <option value="<?= e((string) $candidate->getId()) ?>"><?= e($candidate->displayName()) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="be-form__hint"><?= $candidates === [] ? 'Jeder aktive Kontakt hat schon einen Debitor — zuerst den Kontakt erfassen.' : 'Aktive Kontakte ohne Debitor. Die Sprache des Belegs steht am Kontakt, nicht am Debitor.' ?></small>
                <?php else: ?>
                <label>Kontakt</label>
                <input type="text" value="<?= e($contact->displayName()) ?>" readonly>
                <?= raw($fieldError('contact_id')) ?>
                <small class="be-form__hint">Sprache des Belegs: <?= e(mb_strtoupper($contact->getLanguage())) ?> — sie steht am Kontakt, nicht am Debitor.</small>
                <?php endif; ?>
            </div>
            <div class="be-form__field">
                <label>Kundennummer</label>
                <input type="text" value="<?= $isNew ? '' : e((string) $entry->getCustomerNumber()) ?>" placeholder="<?= $isNew ? 'wird beim Speichern vergeben' : '' ?>" readonly>
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
            <div class="be-form__field">
                <label class="be-choice">
                    <input type="checkbox" class="be-choice__input" name="dunning_block" value="1"<?= $entry->hasDunningBlock() ? ' checked' : '' ?>>
                    <span class="be-choice__label">Mahnsperre — kein Mahnlauf erfasst diesen Debitor</span>
                </label>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
