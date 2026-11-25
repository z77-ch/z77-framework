<?php
/**
 * The mandator — one record, one page (owner decisions E1 / E2). A plain
 * page form with `csrf_token` (`#[Csrf]`), no JavaScript (Rule 7):
 *
 *   - Briefkopf: name, two address lines, street / no, zip / city, country;
 *     e-mail, phone, website, and the block
 *     «Firmenlogo» — what each use shows today and «Firmenlogo …», which opens the logo window
 *     (2026-11-20, `logoAction()`);
 *   - UID und MWST: the UID (canonical `CHE-123.456.789`, required while
 *     liable — owner 2026-11-24), the liability switch (a hidden `0` before
 *     it, so «off» is submitted too) and, under it, the note a document
 *     without VAT prints (owner 2026-10-22); while the switch is on, the VAT
 *     return's settings (a CSS reveal, 2026-11-24): Abrechnungsmethode,
 *     Abrechnungsart, Abrechnungsrhythmus, Abrechnung ab / bis;
 *   - Konten: the ledger accounts the business modules post with — typed by
 *     number with the shared `<datalist>` when module-financial is
 *     registered; each row shows its status;
 *   - Belege: what a NEW order or invoice starts with — the default «Betreffname»
 *     (owner 2026-10-21; a debtor's own label wins).
 *
 * The four sections are RADIO TABS (owner 2026-10-08, the `.be-radiotabs`
 * pattern of module-backend): one hidden radio per section BEFORE the panels,
 * a panel shows through `:checked ~` — no JavaScript, still ONE form, so
 * «Speichern» writes all four sections. The tab labels stand in the toolbar
 * (`editToolbar`, `<label for>` of these radios). The server checks the radio
 * of `$openTab`: the tab with the first field error after a refused save, else
 * the first. `novalidate`: the browser cannot focus an invalid field in a
 * hidden panel (the submit would silently do nothing) — the server validates.
 *
 * The submit is NOT at the end of the body: «Speichern» («Mandant anlegen») stands in
 * the action cell (`editAct`, ADR-033 rev. 2026-10-08) and reaches this form through
 * `form="mandator-edit"`.
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
 * @var array<string,string> $mandatorTabs  tab key → label, in form order (MandatorLayout::TABS)
 * @var string $openTab  the tab the page opens on
 * @var list<array{use: string, label: string, state: string, preview: string|null, path: string}> $logoRows  existing record only
 * @var bool $logoWindow  the logo window is available (a record and module-dms)
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
    <form method="post" action="<?= e($actionBase) ?>/edit" id="mandator-edit" class="be-list__section be-radiotabs" autocomplete="off" novalidate>
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

        <?php /* The radios first — the panels are their later siblings (`:checked ~`). Named, so they
                 form one group (arrow keys switch); the server ignores `mandator_tab`. */ ?>
        <?php $n = 0; foreach ($mandatorTabs as $key => $label): $n++; ?>
        <input type="radio" class="be-radiotabs__radio" name="mandator_tab" id="mandator-tab-<?= e($key) ?>" value="<?= e($key) ?>" data-tab="<?= $n ?>"<?= $key === $openTab ? ' checked' : '' ?>>
        <?php endforeach; ?>
        <?php $panel = static fn(string $key): string => ' data-tab="' . (array_search($key, array_keys($mandatorTabs), true) + 1) . '" aria-label="' . e($mandatorTabs[$key]) . '"'; ?>

        <section class="be-radiotabs__panel"<?= raw($panel('briefkopf')) ?>>
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
        </div>

        <?php /* The company logo (2026-11-20): what each use shows today; «Firmenlogo …» opens the logo window
                 (ADR-047 — without the script the same address as a page). Not a form field: the window has its own form. */ ?>
        <div class="be-form__field be-form__field--full" data-help-key="logos" data-mandator-logos>
            <span class="be-form__label">Firmenlogo</span>
            <?php if ($isNew): ?>
            <small class="be-form__hint">Nach dem Anlegen des Mandanten.</small>
            <?php else: ?>
            <div class="be-list__table" style="--be-list-cols: 8rem minmax(0, 1fr)">
                <?php foreach ($logoRows ?? [] as $row): ?>
                <div class="be-list__item"><div class="be-list__row" data-logo-use="<?= e($row['use']) ?>">
                    <span class="be-list__cell be-list__cell--muted"><?= e($row['label']) ?></span>
                    <span class="be-list__cell be-list__cell--wrap">
                        <?php if ($row['state'] === 'document' && $row['preview'] !== null): ?>
                        <img class="be-list__preview" src="<?= e($row['preview']) ?>" alt="Logo <?= e($row['label']) ?>">
                        <?php elseif ($row['state'] === 'document'): ?>gespeichert
                        <?php elseif ($row['state'] === 'own'): ?><span class="be-list__cell--muted">eigenes Design</span>
                        <?php else: ?><span class="be-list__cell--muted">kein Logo</span>
                        <?php endif; ?>
                    </span>
                </div></div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($logoWindow)): ?>
            <div><a class="be-btn be-btn--ghost" href="<?= e($actionBase) ?>/logo" data-window-open="<?= e($actionBase) ?>/logo">Firmenlogo …</a></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        </section>

        <section class="be-radiotabs__panel"<?= raw($panel('uid')) ?>>
        <?php /* The liability switch drives a CSS reveal (`.be-reveal__toggle` + `.be-switch--for`, no script —
                 Rule 7): the VAT return's settings show only while it is on. The checkbox stays the submitted
                 `liable_to_vat` (the hidden 0 before it); hidden fields are still submitted, so a stored setting
                 survives switching off — «Abrechnung bis» needs it for the last returns. The UID is `required`
                 while the record (or the refused draft) is liable — the server's rule (MandatorValidator). */ ?>
        <div class="be-reveal">
        <input type="hidden" name="liable_to_vat" value="0">
        <input type="checkbox" id="mandator-liable" class="be-reveal__toggle" name="liable_to_vat" value="1"<?= $entry->isLiableToVat() ? ' checked' : '' ?>>
        <div class="be-form__grid">
            <?= raw($text('uid', 'UID', $entry->getUid(), \Z77\Module\Mandator\Entities\Mandator::UID_LENGTH + 5, 'CHE-123.456.789', $entry->isLiableToVat())) ?>
            <div class="be-form__field">
                <label class="be-switch be-switch--for" for="mandator-liable">
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Mehrwertsteuerpflichtig</span>
                </label>
                <?php /* A refusal of the posted VAT returns stands HERE when the switch was turned off (the
                         panel below is hidden then) — VatSettingsGuard, field `liable_to_vat`. */ ?>
                <?= raw($fieldError('liable_to_vat')) ?>
                <small class="be-form__hint">Aus: Rechnungen, Gutschriften, Offerten und Auftragsbestätigungen werden ohne MWST berechnet, verbucht und gedruckt. Eine Gutschrift folgt ihrer Rechnung.</small>
            </div>
            <?php /* The note a document without VAT prints (owner 2026-10-22) — directly under the switch it
                     belongs to. No placeholder (the backend's rule: a grey suggestion reads as a value);
                     the usual wording stands in the hint. */ ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="mandator-vat_exempt_note">Hinweis ohne MWST</label>
                <input type="text" id="mandator-vat_exempt_note" name="vat_exempt_note" value="<?= e($entry->getVatExemptNote()) ?>" maxlength="<?= \Z77\Module\Mandator\Entities\Mandator::VAT_EXEMPT_NOTE_LENGTH ?>" autocomplete="off" aria-invalid="<?= $invalid('vat_exempt_note') ?>">
                <?= raw($fieldError('vat_exempt_note')) ?>
                <small class="be-form__hint">Wird auf jeder Rechnung, Gutschrift, Offerte und Auftragsbestätigung unter dem Total gedruckt, solange der Schalter aus ist. Üblich: «Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig.» Ein verbuchter Beleg behält den Text, wie er beim Erstellen lautete.</small>
            </div>
        </div>
        <?php /* The VAT return's settings (ADR-041 addendum 2026-11-24): two selects for the two axes of the
                 method (the record's two fields, the law's two terms), the rhythm, the two dates. No hint
                 text — the help explains each (owner 2026-11-17). The two accounts are on «Konten». */
              $select = function (string $field, string $label, array $choices, ?string $value) use ($fieldError, $invalid): string {
                  $html  = '<div class="be-form__field" data-z77-field-wrapper>';
                  $html .= '<label for="mandator-' . e($field) . '">' . e($label) . '</label>';
                  $html .= '<select id="mandator-' . e($field) . '" name="' . e($field) . '" aria-invalid="' . $invalid($field) . '">';
                  $html .= '<option value=""' . ($value === null ? ' selected' : '') . '>nicht festgelegt</option>';
                  foreach ($choices as $key => $text) {
                      $html .= '<option value="' . e($key) . '"' . ($value === $key ? ' selected' : '') . '>' . e($text) . '</option>';
                  }
                  $html .= '</select>' . $fieldError($field) . '</div>';

                  return $html;
              };
              $date = function (string $field, string $label, ?\DateTimeImmutable $value, string $raw) use ($fieldError, $invalid): string {
                  return '<div class="be-form__field" data-z77-field-wrapper>'
                      . '<label for="mandator-' . e($field) . '">' . e($label) . '</label>'
                      . '<input type="date" id="mandator-' . e($field) . '" name="' . e($field) . '" value="' . e($raw !== '' ? $raw : (string) $value?->format('Y-m-d')) . '" aria-invalid="' . $invalid($field) . '">'
                      . $fieldError($field) . '</div>';
              };
        ?>
        <div class="be-reveal__panel" data-mandator-vat-return>
            <div class="be-form__grid">
                <?= raw($select('vat_method', 'Abrechnungsmethode', \Z77\Module\Mandator\Ui\MandatorLayout::VAT_METHODS, $entry->getVatMethod()?->value)) ?>
                <?= raw($select('vat_consideration', 'Abrechnungsart', \Z77\Module\Mandator\Ui\MandatorLayout::VAT_CONSIDERATIONS, $entry->getVatConsideration()?->value)) ?>
                <?= raw($select('vat_settlement_rhythm', 'Abrechnungsrhythmus', \Z77\Module\Mandator\Ui\MandatorLayout::VAT_RHYTHMS, $entry->getVatSettlementRhythm()?->value)) ?>
                <?= raw($date('vat_settlement_from', 'Abrechnung ab', $entry->getVatSettlementFrom(), $entry->getVatSettlementFromInput())) ?>
                <?= raw($date('vat_settlement_until', 'Abrechnung bis', $entry->getVatSettlementUntil(), $entry->getVatSettlementUntilInput())) ?>
            </div>
        </div>
        </div>
        </section>

        <section class="be-radiotabs__panel"<?= raw($panel('konten')) ?>>
        <p class="be-form__hint">Die Konten, auf die Debitoren, MWST, die Jahreseröffnung und das Bankkonto buchen. Leer = nicht hinterlegt; eine Buchung, die das Konto braucht, wird dann mit Hinweis abgelehnt.<?php if (!$ledgerKnown): ?> Ohne z77/module-financial werden die Nummern nicht gegen die Buchhaltung geprüft.<?php endif; ?></p>
        <?= $this->partial('partials/accountDatalist', ['id' => 'mandator-accounts', 'accounts' => $accounts], 'Z77\\Module\\Mandator') ?>
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
        </section>

        <section class="be-radiotabs__panel"<?= raw($panel('belege')) ?>>
        <p class="be-form__hint">Womit jeder neue Auftrag und jede neue Rechnung beginnt — im Beleg änderbar. Ein Debitor mit eigenem Betreffnamen geht vor.</p>
        <div class="be-form__grid">
            <?= raw($text('default_subject_label', 'Betreffname', $entry->getDefaultSubjectLabel(), \Z77\Module\Mandator\Entities\Mandator::SUBJECT_LABEL_LENGTH, 'z.B. Projekt, Referenz — leer = keiner')) ?>
        </div>
        </section>

    </form>
</div>
