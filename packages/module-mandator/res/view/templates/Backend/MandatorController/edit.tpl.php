<?php
/**
 * The mandator — one record, one page (owner decisions E1 / E2). A plain
 * page form with `csrf_token` (`#[Csrf]`), no JavaScript (Rule 7):
 *
 *   - Briefkopf: name, two address lines, street / no, zip / city, country;
 *     e-mail, phone, website; the logo path;
 *   - UID und MWST: the UID (canonical `CHE-123.456.789`) and the liability
 *     checkbox (a hidden `0` before it, so «unchecked» is submitted too);
 *   - Konten: the ledger accounts the business modules post with — typed by
 *     number with the shared `<datalist>` when module-financial is
 *     registered; each row shows its status.
 *
 * The bank connection is NOT here on purpose: IBAN and the creditor block
 * of the QR-bill belong to the payment target (debtor). The mandator is the
 * letterhead, the payment target is the payee.
 *
 * @var \Z77\Module\Mandator\Entities\Mandator $entry
 * @var bool $isNew
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $entityCsrf  existing record only
 * @var list<string> $accountKeys
 * @var array<string,string> $accountLabels
 * @var array<string,array{number: string, error: string|null}> $accountStatus
 * @var list<array{number: string, label: string}> $accounts  postable accounts for the datalist; [] without financial
 * @var bool $ledgerKnown
 * @var string|null $unavailable  the German sentence when the record cannot be read (module not registered, table missing) — then nothing else is set
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/mandator';
if (!empty($unavailable)):
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Mandant</h2>
        </div>
        <div class="be-modal__alert be-modal__alert--error"><?= e($unavailable) ?></div>
    </div>
</div>
<?php
return;
endif;

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
$invalid = static fn(string $field) => $validator->hasFieldError($field) ? 'true' : 'false';
$text = function (string $field, string $label, string $value, int $maxlength, string $hint = '', bool $required = false) use ($fieldError, $invalid): string {
    $html  = '<div class="be-form__field" data-z77-field-wrapper>';
    $html .= '<label for="mandator-' . e($field) . '">' . e($label) . ($hint !== '' ? ' <small>(' . e($hint) . ')</small>' : '') . '</label>';
    $html .= '<input type="text" id="mandator-' . e($field) . '" name="' . e($field) . '" value="' . e($value) . '" maxlength="' . $maxlength . '" autocomplete="off"' . ($required ? ' required' : '') . ' aria-invalid="' . $invalid($field) . '">';
    $html .= $fieldError($field);
    $html .= '</div>';

    return $html;
};
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase) ?>/edit" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <?php endif; ?>

        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= $isNew ? 'Mandant anlegen' : 'Mandant «' . e($entry->getName()) . '»' ?></h2>
        </div>

        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($validator->getFieldErrors()): ?>Bitte überprüfe die markierten Eingaben.<?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isNew): ?>
        <p class="be-form__hint">Noch kein Mandant erfasst. Die Konten sind mit den Nummern des KMU-Kontenrahmens vorbelegt — beim Speichern wird der eine Datensatz angelegt.</p>
        <?php endif; ?>

        <p class="be-form__hint">Der Mandant ist der Briefkopf: was oben auf Auswertungen und Briefen steht. Bankverbindung und Zahlungsempfänger gehören zum Zahlungsziel — beides kann verschieden heissen, und das ist kein Fehler.</p>

        <h3 class="be-form__section">Briefkopf</h3>
        <div class="be-form__grid">
            <?= raw($text('name', 'Name', $entry->getName(), \Z77\Module\Mandator\Entities\Mandator::NAME_LENGTH, '', true)) ?>
            <?= raw($text('address_suffix_one', 'Adresszusatz 1', $entry->getAddressSuffixOne(), \Z77\Module\Mandator\Entities\Mandator::SUFFIX_LENGTH)) ?>
            <?= raw($text('address_suffix_two', 'Adresszusatz 2', $entry->getAddressSuffixTwo(), \Z77\Module\Mandator\Entities\Mandator::SUFFIX_LENGTH)) ?>
            <?= raw($text('street', 'Strasse', $entry->getStreet(), \Z77\Module\Mandator\Entities\Mandator::STREET_LENGTH)) ?>
            <?= raw($text('house_no', 'Hausnummer', $entry->getHouseNo(), \Z77\Module\Mandator\Entities\Mandator::HOUSE_NO_LENGTH)) ?>
            <?= raw($text('zip', 'PLZ', $entry->getZip(), \Z77\Module\Mandator\Entities\Mandator::ZIP_LENGTH)) ?>
            <?= raw($text('city', 'Ort', $entry->getCity(), \Z77\Module\Mandator\Entities\Mandator::CITY_LENGTH)) ?>
            <?= raw($text('country', 'Land', $entry->getCountry(), 2, 'ISO-Code, z.B. CH', true)) ?>
            <?= raw($text('email', 'E-Mail', $entry->getEmail(), \Z77\Module\Mandator\Entities\Mandator::EMAIL_LENGTH)) ?>
            <?= raw($text('phone', 'Telefon', $entry->getPhone(), \Z77\Module\Mandator\Entities\Mandator::PHONE_LENGTH)) ?>
            <?= raw($text('website', 'Website', $entry->getWebsite(), \Z77\Module\Mandator\Entities\Mandator::WEBSITE_LENGTH)) ?>
            <?= raw($text('logo_path', 'Logo', $entry->getLogoPath(), \Z77\Module\Mandator\Entities\Mandator::LOGO_LENGTH, 'Pfad relativ zum Projekt')) ?>
        </div>

        <h3 class="be-form__section">UID und MWST</h3>
        <div class="be-form__grid">
            <?= raw($text('uid', 'UID', $entry->getUid(), \Z77\Module\Mandator\Entities\Mandator::UID_LENGTH + 5, 'CHE-123.456.789')) ?>
            <div class="be-form__field">
                <label for="mandator-liable">Mehrwertsteuer</label>
                <input type="hidden" name="liable_to_vat" value="0">
                <label class="be-choice"><input type="checkbox" id="mandator-liable" name="liable_to_vat" value="1"<?= $entry->isLiableToVat() ? ' checked' : '' ?>> mehrwertsteuerpflichtig</label>
                <small class="be-form__hint">Merkmal — was es abschaltet (MWST-Zeilen der Rechnung, MwSt-Zeile der Buchungsmaske), entscheidet der Eigentümer separat.</small>
            </div>
        </div>

        <h3 class="be-form__section">Konten</h3>
        <p class="be-form__hint">Die Konten, auf die Debitoren und MWST buchen. Leer = nicht hinterlegt; eine Buchung, die das Konto braucht, wird dann mit Hinweis abgelehnt.<?php if (!$ledgerKnown): ?> Ohne z77/module-financial werden die Nummern nicht gegen die Buchhaltung geprüft.<?php endif; ?></p>
        <?php if ($accounts !== []): ?>
        <datalist id="mandator-accounts">
            <?php foreach ($accounts as $account): ?>
            <option value="<?= e($account['number']) ?>"><?= e($account['label']) ?></option>
            <?php endforeach; ?>
        </datalist>
        <?php endif; ?>
        <div class="be-form__grid">
            <?php foreach ($accountKeys as $key): ?>
            <?php $field = \Z77\Module\Mandator\Entities\Mandator::accountField($key); $status = $accountStatus[$key] ?? ['number' => '', 'error' => null]; ?>
            <div class="be-form__field" data-z77-field-wrapper data-mandator-account="<?= e($key) ?>">
                <label for="mandator-<?= e($field) ?>"><?= e($accountLabels[$key] ?? $key) ?></label>
                <input type="text" id="mandator-<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($entry->account($key)) ?>" maxlength="<?= \Z77\Module\Mandator\Entities\Mandator::ACCOUNT_NUMBER_LENGTH ?>"
                       inputmode="numeric" autocomplete="off"<?= $accounts !== [] ? ' list="mandator-accounts"' : '' ?> aria-invalid="<?= $invalid($field) ?>">
                <?= raw($fieldError($field)) ?>
                <?php if (!$validator->hasFieldError($field)): ?>
                    <?php if ($status['error'] === null && $status['number'] !== '' && $ledgerKnown): ?>
                    <small class="be-form__resolved"><span class="badge badge--success">ok</span></small>
                    <?php elseif ($status['error'] === null && $status['number'] !== ''): ?>
                    <small class="be-form__resolved"><span class="badge badge--muted">ungeprüft</span></small>
                    <?php elseif ($status['error'] !== null): ?>
                    <small class="be-form__hint"><span class="badge badge--warning">prüfen</span> <?= e($status['error']) ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="be-form__row" style="--be-form-cols: auto">
            <div class="be-form__field">
                <button type="submit" class="be-btn be-btn--primary"><?= $isNew ? 'Mandant anlegen' : 'Speichern' ?></button>
            </div>
        </div>
    </form>
</div>
