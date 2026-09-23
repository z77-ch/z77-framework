<?php
/**
 * Add / edit a payment target. The code is immutable after creation — it is
 * the key payments carry (ADR-043 decision 19). The IBAN is checked for
 * shape, check digits and CH / LI origin; the ledger account is checked
 * against the bookkeeping when module-financial is installed.
 *
 * @var \Z77\Module\Debtor\Entities\PaymentTarget $entry
 * @var bool $ledgerKnown
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $actionBase
 */
$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/finance/payment-target';

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post="<?= e($actionBase) ?>/<?= $isNew ? 'add' : 'edit?id=' . e((string) $entry->getId()) ?>">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Zahlungsziel anlegen' : 'Zahlungsziel «' . e($entry->getCode()) . '» bearbeiten' ?></h2>
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
                <label>Code <small>(2–16 Zeichen a–z, 0–9, Bindestrich<?= $isNew ? '' : ' — nach dem Anlegen fix' ?>)</small></label>
                <input type="text" name="code" value="<?= e($entry->getCode()) ?>" maxlength="16" autocomplete="off"<?= $isNew ? ' required' : ' readonly' ?>
                       aria-invalid="<?= $validator->hasFieldError('code') ? 'true' : 'false' ?>">
                <?= raw($fieldError('code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Bezeichnung</label>
                <input type="text" name="label" value="<?= e($entry->getLabel()) ?>" maxlength="80" required autocomplete="off"
                       aria-invalid="<?= $validator->hasFieldError('label') ? 'true' : 'false' ?>">
                <?= raw($fieldError('label')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>IBAN oder QR-IBAN</label>
                <input type="text" name="iban" value="<?= e($entry->formattedIban()) ?>" maxlength="42" required autocomplete="off" spellcheck="false"
                       aria-invalid="<?= $validator->hasFieldError('iban') ? 'true' : 'false' ?>">
                <?= raw($fieldError('iban')) ?>
                <?php if ($entry->getIban() !== '' && !$validator->hasFieldError('iban')): ?>
                <small class="be-form__hint"><?= $entry->isQrIban() ? 'QR-IBAN — der Beleg trägt eine QR-Referenz.' : 'Normale IBAN — der Beleg trägt eine Creditor Reference oder keine.' ?></small>
                <?php endif; ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Konto in der Buchhaltung <small>(Nummer, z.B. 1020)</small></label>
                <input type="text" name="account_number" value="<?= e($entry->getAccountNumber()) ?>" maxlength="10" required autocomplete="off" inputmode="numeric"
                       aria-invalid="<?= $validator->hasFieldError('account_number') ? 'true' : 'false' ?>">
                <?= raw($fieldError('account_number')) ?>
                <?php if (!$ledgerKnown): ?>
                <small class="be-form__hint">Ohne z77/module-financial wird die Nummer nicht gegen die Buchhaltung geprüft.</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
