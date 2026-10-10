<?php
/**
 * Add / edit a payment target. The code is immutable after creation — it is
 * the key payments carry (ADR-043 decision 19). Two IBAN fields (P3 part 3,
 * owner 2026-09-23): the plain IBAN (reference type NON) and the QR-IBAN
 * (QRR) — both optional, at least one. Each is checked for shape, check
 * digits, CH / LI origin and its kind; the ledger account is checked against
 * the bookkeeping when module-financial is installed.
 *
 * The creditor block — the ACCOUNT HOLDER as registered with the bank. An
 * empty field takes the mandator's value (field by field); the field SHOWS
 * that value as its placeholder, so what lands on the bill is visible before
 * printing (the orchestrator's point on the open question of 2026-09-23).
 *
 * @var \Z77\Module\Debtor\Entities\PaymentTarget $entry
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator  the fallback, null = none saved / not readable
 * @var bool $ledgerKnown
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $actionBase
 */
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Services\Iban;

$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/finance/payment-target';

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
$holder = [
    'holder_name'     => ['Kontoinhaber', $entry->getHolderName(), $mandator?->getName() ?? '', PaymentTarget::HOLDER_NAME_LENGTH],
    'holder_street'   => ['Strasse', $entry->getHolderStreet(), $mandator?->getStreet() ?? '', PaymentTarget::HOLDER_STREET_LENGTH],
    'holder_house_no' => ['Nr.', $entry->getHolderHouseNo(), $mandator?->getHouseNo() ?? '', PaymentTarget::HOLDER_HOUSE_NO_LENGTH],
    'holder_zip'      => ['PLZ', $entry->getHolderZip(), $mandator?->getZip() ?? '', PaymentTarget::HOLDER_ZIP_LENGTH],
    'holder_city'     => ['Ort', $entry->getHolderCity(), $mandator?->getCity() ?? '', PaymentTarget::HOLDER_CITY_LENGTH],
    'holder_country'  => ['Land', $entry->getHolderCountry(), $mandator?->getCountry() ?? '', 2],
];
?>
<form data-fetch-post="<?= e($actionBase) ?>/<?= $isNew ? 'add' : 'edit?id=' . e((string) $entry->getId()) ?>">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Zahlungsziel anlegen' : 'Zahlungsziel «' . e($entry->getCode()) . '» bearbeiten' ?></h2>
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
                <label>QR-IBAN <small>(mit QR-Referenz)</small></label>
                <input type="text" name="qr_iban" value="<?= e(Iban::format($entry->getQrIban())) ?>" maxlength="42" autocomplete="off" spellcheck="false"
                       aria-invalid="<?= $validator->hasFieldError('qr_iban') ? 'true' : 'false' ?>">
                <?= raw($fieldError('qr_iban')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>IBAN <small>(ohne Referenz)</small></label>
                <input type="text" name="iban" value="<?= e(Iban::format($entry->getIban())) ?>" maxlength="42" autocomplete="off" spellcheck="false"
                       aria-invalid="<?= $validator->hasFieldError('iban') ? 'true' : 'false' ?>">
                <?= raw($fieldError('iban')) ?>
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
        <h3 class="be-form__section">Zahlungsempfänger <small>(leer = Angabe des Mandanten)</small></h3>
        <div class="be-form__grid">
            <?php foreach ($holder as $name => [$label, $value, $fallback, $max]): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-target-<?= e($name) ?>"><?= e($label) ?></label>
                <input type="text" id="payment-target-<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" maxlength="<?= (int) $max ?>" autocomplete="off"
                       placeholder="<?= e($fallback) ?>" aria-invalid="<?= $validator->hasFieldError($name) ? 'true' : 'false' ?>">
                <?= raw($fieldError($name)) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</form>
