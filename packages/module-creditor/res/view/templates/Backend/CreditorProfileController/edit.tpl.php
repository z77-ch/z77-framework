<?php
/**
 * The creditor window (creditor plan §2.2, §5, §8) — the look of the master-data windows (ADR-047,
 * action row first, ADR-049). The CONTACT is fixed — shown, never edited (name, address, language
 * live on the contact). Fields:
 *
 *   - UID (optional, `CHE-123.456.789`);
 *   - the defaults: Konto (the chart's `<datalist>`; «automatisch», read-only, while the default
 *     is «nur Steuer» — z77 derives the account from the code, S56-4), MWST (module-receipt's
 *     select: a code, a code «— nur Steuer»; «ohne MWST» = the select value `-`; «— offen —» = not
 *     decided; a mandator not liable to VAT offers only «ohne MWST»),
 *     «Bezahlt mit» (module-receipt's payment methods) — and when / by whom they were decided;
 *   - the IBAN rows: IBAN, hint (what tells creditors apart when they share an IBAN), active. An
 *     empty row at the end ADDS one; emptying an IBAN REMOVES its row. A shared IBAN names the other
 *     creditors behind it;
 *   - «Gelernt aus Rechnungen» (owner 2026-11-16, read-only): what the creditor learned from its
 *     invoices — the features it is recognised by, the labels its values are read at, whether the
 *     AI is still compared — and «Gelerntes vergessen» in the action row (a submit of this form to
 *     `forget-learned`, nothing typed is saved).
 *
 * One form, one save, no JavaScript of its own (Rule 7): `csrf_token` (`#[Csrf]`), the entity token
 * and the VERSION shown (the service compares it). Active is the list's switch. The help
 * (`edit.help`) has a section per field.
 *
 * @var \Z77\Module\Creditor\Entities\CreditorProfile $entry
 * @var \Z77\Module\Contact\Entities\Contact $contact
 * @var int $version
 * @var \Z77\Module\Creditor\Validators\CreditorProfileValidator $validator
 * @var list<array{iban: string, hint: string, active: bool}> $ibanRows
 * @var array<string, list<string>> $sharedWith  IBAN → the other creditors it is linked to
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var list<\Z77\Module\Financial\Entities\Account> $accounts
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
 * @var list<array{group: string, options: list<array{value: string, label: string}>}> $taxChoices  module-receipt's VAT select (with «nur Steuer»)
 * @var list<\Z77\Module\Receipt\Entities\PaymentMethod> $methods
 * @var bool   $liable  the mandator is liable to VAT
 * @var array{features: array<string, list<string>>, labels: array<string, array{label: string, currency: ?string, agreed: int}>, trusted: bool, empty: bool} $learned
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Shared\Payment\Iban;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::BASE;
$window     = !empty($window);
$title      = 'Kreditor «' . $contact->displayName() . '»';
$action     = $actionBase . '/edit?id=' . (int) $entry->getId();
$fieldIds   = ['uid' => 'creditor-uid', 'bank_text' => 'creditor-bank_text', 'default_account' => 'creditor-default_account', 'default_tax' => 'creditor-default_tax', 'default_payment_method_code' => 'creditor-default_payment_method_code', 'default_line_text' => 'creditor-default_line_text'];
foreach (array_keys($ibanRows) as $n) {
    $fieldIds['iban_' . $n] = 'creditor-iban-' . $n;
    $fieldIds['hint_' . $n] = 'creditor-hint-' . $n;
}
$invalidIds = array_values(array_filter($fieldIds, static fn(string $id, string $field) => $validator->hasFieldError($field), ARRAY_FILTER_USE_BOTH));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
// The VAT select's token (module-receipt's one parser): '-', a code, or a code + «:nur-steuer».
$tax        = \Z77\Module\Receipt\Services\ReceiptFields::vatToken($entry->getDefaultTaxCode(), $entry->isDefaultWithoutVat(), $entry->isDefaultTaxOnly());
$taxOnly    = $entry->isDefaultTaxOnly() && $entry->getDefaultTaxCode() !== null;
$taxChoices = $taxChoices ?? [];
$winAttr    = $window
    ? ' data-window="creditor-edit" data-window-entity="creditorProfile:' . (int) $entry->getId() . '" data-window-title="' . e($title) . '" data-window-width="46rem"'
    : '';
$rows       = [...$ibanRows, ['iban' => '', 'hint' => '', 'active' => true]];   // the empty row adds one
$learned    = $learned ?? ['features' => [], 'labels' => [], 'trusted' => false, 'empty' => true];
$extra      = $learned['empty'] ? '' : '<button type="submit" class="be-btn be-btn--ghost" formaction="' . e($actionBase . '/forget-learned?id=' . (int) $entry->getId()) . '" formnovalidate data-help-key="learned" title="Vergisst, woran dieser Kreditor erkannt wird und wo seine Werte stehen — Eingaben hier werden nicht gespeichert">Gelerntes vergessen</button>';
$kindWords  = ['vat' => 'MWST-Nr.', 'domain' => 'Domain', 'sender' => 'Absender'];
$fieldWords = ['invoice_number' => 'Rechnungs-Nr.', 'invoice_date' => 'Rechnungsdatum', 'due_date' => 'Fällig am', 'amount' => 'Betrag', 'line_text' => 'Kontierungstext'];
$required   = array_intersect_key($learned['labels'], array_flip(\Z77\Module\Creditor\Recognition\LearnedLabels::REQUIRED));   // the column header of the line text is optional — no trust line for it alone
$agreedMin  = $learned['labels'] === [] ? 0 : min(array_map(static fn(array $l): int => $l['agreed'], array_intersect_key($learned['labels'], array_flip(\Z77\Module\Creditor\Recognition\LearnedLabels::REQUIRED))) ?: [0]);
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="creditor-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) $version ?>">

        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Speichern',
            'extra'      => $extra,
            'cancelHref' => $window ? '' : $actionBase . '/list',
            'errors'     => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>
        <?php endif; ?>

        <?php if ($validator->getErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <datalist id="creditor-accounts">
            <?php foreach ($accounts as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Kontakt</label>
                <input type="text" value="<?= e($contact->displayName()) ?>" readonly>
                <?= raw($fieldError('contact_id')) ?>
                <small class="be-form__hint">Adresse, Sprache und E-Mail stehen beim Kontakt.<?= $contact->isActive() ? '' : ' Der Kontakt ist inaktiv.' ?></small>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-uid">UID</label>
                <input type="text" id="creditor-uid" name="uid" value="<?= e((string) $entry->getUid()) ?>" maxlength="24" placeholder="CHE-123.456.789" spellcheck="false" aria-invalid="<?= $invalid('uid') ?>">
                <?= raw($fieldError('uid')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-bank_text">Banktext</label>
                <input type="text" id="creditor-bank_text" name="bank_text" value="<?= e((string) $entry->getBankText()) ?>" maxlength="<?= \Z77\Module\Creditor\Entities\CreditorProfile::BANK_TEXT_LENGTH ?>" spellcheck="false" aria-invalid="<?= $invalid('bank_text') ?>">
                <?= raw($fieldError('bank_text')) ?>
            </div>
        </div>

        <div class="be-form__section">Standardwerte für neue Rechnungen</div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-default_account">Konto</label>
                <?php if ($taxOnly): // «nur Steuer»: z77 derives the account from the code (S56-4) ?>
                <input type="text" id="creditor-default_account" name="default_account" value="" placeholder="automatisch" readonly aria-invalid="<?= $invalid('default_account') ?>">
                <?php else: ?>
                <input type="text" id="creditor-default_account" name="default_account" list="creditor-accounts" value="<?= e((string) $entry->getDefaultAccount()) ?>" inputmode="numeric" aria-invalid="<?= $invalid('default_account') ?>">
                <?php endif; ?>
                <?php if (!$taxOnly && $choices->accountName($entry->getDefaultAccount()) !== ''): ?><small class="be-form__resolved"><?= e($choices->accountName($entry->getDefaultAccount())) ?></small><?php endif; ?>
                <?= raw($fieldError('default_account')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-default_tax">MWST</label>
                <select id="creditor-default_tax" name="default_tax" aria-invalid="<?= $invalid('default_tax') ?>">
                    <option value=""<?= $tax === '' ? ' selected' : '' ?>>— offen —</option>
                    <option value="-"<?= $tax === '-' ? ' selected' : '' ?>>ohne MWST</option>
                    <?php foreach ($liable ? $taxChoices : [] as $group): if ($group['group'] === '') { continue; } // «ohne MWST» stands above ?>
                    <optgroup label="<?= e($group['group']) ?>">
                        <?php foreach ($group['options'] as $option): ?>
                        <option value="<?= e($option['value']) ?>"<?= $option['value'] === $tax ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                    <?php if (!$liable && $tax !== '' && $tax !== '-'): ?>
                    <option value="<?= e($tax) ?>" selected><?= e($tax) ?> (Mandant nicht MWST-pflichtig)</option>
                    <?php endif; ?>
                </select>
                <?= raw($fieldError('default_tax')) ?>
                <?php if (!$liable): ?><small class="be-form__hint">Der Mandant ist nicht MWST-pflichtig — Rechnungen von Kreditoren werden ohne MWST gebucht.</small><?php endif; ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-default_payment_method_code">Bezahlt mit</label>
                <select id="creditor-default_payment_method_code" name="default_payment_method_code" aria-invalid="<?= $invalid('default_payment_method_code') ?>">
                    <option value="">— offen —</option>
                    <?php foreach ($methods as $method): ?>
                    <option value="<?= e($method->getCode()) ?>"<?= $method->getCode() === $entry->getDefaultPaymentMethodCode() ? ' selected' : '' ?>><?= e($method->getLabel()) ?><?= $method->isActive() ? '' : ' (inaktiv)' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('default_payment_method_code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-default_line_text">Kontierungstext</label>
                <input type="text" id="creditor-default_line_text" name="default_line_text" value="<?= e((string) $entry->getDefaultLineText()) ?>" maxlength="<?= \Z77\Module\Creditor\Entities\CreditorProfile::LINE_TEXT_LENGTH ?>" aria-invalid="<?= $invalid('default_line_text') ?>">
                <?= raw($fieldError('default_line_text')) ?>
            </div>
        </div>
        <p class="be-form__hint" data-defaults-set="<?= $entry->getDefaultsSetAt() === null ? '' : e($entry->getDefaultsSetAt()->format('Y-m-d')) ?>"><?= $entry->getDefaultsSetAt() === null
            ? 'Noch nicht festgelegt — die erste vollständig erfasste Rechnung dieses Kreditors legt sie fest, oder du trägst sie hier ein.'
            : 'Festgelegt am ' . e($entry->getDefaultsSetAt()->format('d.m.Y')) . ' von ' . e((string) $entry->getDefaultsSetBy()) . ' — spätere Rechnungen ändern sie nicht.' ?></p>

        <div class="be-form__section">IBAN</div>
        <?php foreach ($rows as $n => $row): $isNew = $row['iban'] === ''; $shared = $sharedWith[$row['iban']] ?? []; ?>
        <div class="be-form__grid" data-iban-row="<?= $n ?>">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-iban-<?= $n ?>"><?= $isNew ? 'Neue IBAN' : 'IBAN' ?></label>
                <input type="text" id="creditor-iban-<?= $n ?>" name="ibans[<?= $n ?>][iban]" value="<?= e($isNew ? '' : Iban::format($row['iban'])) ?>" maxlength="42" spellcheck="false" data-help-key="iban" aria-invalid="<?= $invalid('iban_' . $n) ?>">
                <?php if (!$isNew && Iban::isQrIban($row['iban'])): ?><small class="be-form__resolved">QR-IBAN</small><?php endif; ?>
                <?= raw($fieldError('iban_' . $n)) ?>
                <?php if ($shared !== []): ?><small class="be-form__hint" data-iban-shared>Auch bei: <?= e(implode(', ', $shared)) ?> — der Hinweis unterscheidet sie.</small><?php endif; ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="creditor-hint-<?= $n ?>">Hinweis</label>
                <input type="text" id="creditor-hint-<?= $n ?>" name="ibans[<?= $n ?>][hint]" value="<?= e($row['hint']) ?>" maxlength="<?= \Z77\Module\Creditor\Entities\CreditorIban::HINT_LENGTH ?>" data-help-key="hint" aria-invalid="<?= $invalid('hint_' . $n) ?>">
                <?= raw($fieldError('hint_' . $n)) ?>
            </div>
            <div class="be-form__field">
                <input type="hidden" name="ibans[<?= $n ?>][active]" value="0">
                <label class="be-switch" for="creditor-iban-active-<?= $n ?>" title="Aktiv — für die Zuordnung von Rechnungen">
                    <input type="checkbox" id="creditor-iban-active-<?= $n ?>" class="be-switch__input" name="ibans[<?= $n ?>][active]" value="1" data-help-key="iban_active"<?= $row['active'] ? ' checked' : '' ?>>
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Aktiv</span>
                </label>
            </div>
        </div>
        <?php endforeach; ?>
        <p class="be-form__hint">Eine IBAN entfernen: das Feld leeren und speichern. Eine weitere: in «Neue IBAN» eintragen und speichern.</p>

        <div class="be-form__section">Gelernt aus Rechnungen</div>
        <?php if ($learned['empty']): ?>
        <p class="be-form__hint" data-learned="none">Noch nichts gelernt. Speicherst du eine Rechnung dieses Kreditors, merkt sich z77, woran er zu erkennen ist und wo auf seiner Rechnung Nummer, Datum und Betrag stehen.</p>
        <?php else: ?>
        <?php if ($learned['features'] !== []): ?>
        <p class="be-form__hint" data-learned="features">Erkannt an: <?= e(implode(' · ', array_merge(...array_map(
            static fn(string $kind, array $values): array => array_map(static fn(string $v): string => ($kindWords[$kind] ?? $kind) . ' ' . ($kind === 'sender' ? '«' . $v . '»' : $v), $values),
            array_keys($learned['features']), array_values($learned['features'])
        )))) ?></p>
        <?php endif; ?>
        <?php if ($learned['labels'] !== []): ?>
        <p class="be-form__hint" data-learned="labels">Liest: <?= e(implode(' · ', array_map(
            static fn(string $field, array $l): string => ($fieldWords[$field] ?? $field) . ($l['currency'] !== null ? ' in ' . $l['currency'] : '') . ' bei «' . $l['label'] . '»',
            array_keys($learned['labels']), array_values($learned['labels'])
        ))) ?></p>
        <?php if ($required !== []): ?>
        <p class="be-form__hint" data-learned-trusted="<?= $learned['trusted'] ? '1' : '0' ?>"><?= $learned['trusted']
            ? 'Mit der KI verglichen und gleich — die KI wird für diesen Kreditor nicht mehr gebraucht.'
            : 'Noch in Prüfung (' . min($agreedMin, \Z77\Module\Creditor\Services\CreditorLearningService::TRUST_AFTER) . ' von ' . \Z77\Module\Creditor\Services\CreditorLearningService::TRUST_AFTER . ' Vergleichen mit der KI) — solange liest die KI mit, wenn sie eingeschaltet ist.' ?></p>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </form>
</div>
