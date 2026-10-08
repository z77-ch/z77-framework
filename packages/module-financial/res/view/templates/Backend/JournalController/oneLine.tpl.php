<?php
/**
 * The ONE-LINE manual entry (owner, 2026-09-22 — P2 exit check 3b), the
 * default capture form: Soll | Datum | Bu-Nr | Text | Haben | Betrag, the
 * wdv row. A PAGE with a plain form, no JavaScript (Rule 7):
 *
 *   - the «MwSt» checkbox reveals the VAT row through CSS (`.be-reveal`, the
 *     `:checked` sibling selector); it is a submitted field, so the server
 *     knows whether the row was on and renders it `checked` again. Its
 *     control is a SWITCH right AFTER «Betrag» (owner 2026-10-08: «ich gebe
 *     den Betrag ein und klicke gleich MwSt») — a `<label for>` of the
 *     checkbox (`.be-switch--for`), which stays BEFORE the row so the same
 *     `:checked ~` reaches the panel AND the switch's look; no `:has()`, no JS;
 *   - the Bu-Nr is shown (an `<output>`), never entered: «neu» for a new entry (the ledger
 *     draws the number at post time — it is not known before), the number
 *     when editing;
 *   - accounts are typed by number with the shared `<datalist>` (the option
 *     text shows «1020 Bankguthaben»); after a submit the resolved name
 *     stands under the field;
 *   - NO placeholders in the fields (P2 exit check 3a: a grey «1020» read as
 *     a prefilled value).
 *
 * New entry = the CAPTURE area of the journal page (FIN-JOURNAL-CAPTURE-001,
 * owner 2026-09-28): no title, no explanatory paragraph («jeder, der bucht,
 * weiss, was da rein kommt»), no buttons of its own — «Buchen» sits in the action cell (`captureAct`, ADR-033 rev. 2026-10-08)
 * and reaches this form through `form="journal-capture"`. The date is free: the
 * fiscal year follows it. The list below is `listAction`. Edit: the entity
 * token, the version and a link to the Sammelbuchung form of the same entry.
 *
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = new
 * @var int $version  edit only — the entry's version this form was rendered from (optimistic lock)
 * @var string $entityCsrf  edit only
 * @var string $csrfToken  provided by html()
 * @var callable $fmt
 * @var string|null $configNotice  the red band while no VAT account can be resolved (leftover config key, mandator unavailable) — null normally
 * @var string $actionBase
 * @var bool   $window  edit only — fetched as a window (ADR-047): the root declares mask + entity, the form carries `_origin` back, the links stay in the window
 * @var string $origin  edit only — where the window came from (WindowOrigin)
 * @var string $windowWidth  edit only — the window's width, the controller's call
 */
$window  = !empty($window) && $entry !== null;
$winAttr = $window
    ? ' data-window="journal-entry-edit" data-window-entity="journal-entry:' . (int) $entry->getId() . '" data-window-title="' . e('Buchung ' . $year->getCode() . '/' . $entry->getNumber() . ' bearbeiten') . '"'
        . (!empty($windowWidth) ? ' data-window-width="' . e($windowWidth) . '"' : '')
    : '';
$winLink = $window ? ' data-window-link' : '';
$actionBase = $actionBase ?? '/backend/finance/journal';
$isNew      = $entry === null;
$action     = $isNew ? $actionBase . '/add' : $actionBase . '/edit?id=' . (int) $entry->getId();
$compound   = $isNew ? '' : $actionBase . '/edit?id=' . (int) $entry->getId() . '&form=compound';

$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
$invalid    = static fn(string $field): string => $form->error($field) !== '' ? 'true' : 'false';
?>
<div class="be-list"<?= $winAttr ?>>
    <?php if (!empty($configNotice)): ?>
    <div class="be-modal__alert be-modal__alert--error"><?= e($configNotice) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e($action) ?>" class="be-list__section"<?= $isNew ? ' id="journal-capture"' : '' ?> autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="form" value="one-line">
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="version" value="<?= (int) ($version ?? $entry->getVersion()) ?>">
        <?php endif; ?>

        <?php if (!$isNew): ?>
        <?php /* The action bar (ADR-049): sticky at the top of the form — change a field, save,
                 without scrolling; first in the document, so Enter saves. «N Fehler» leads to the
                 first invalid field. A new entry has none: «Buchen» is in the action cell. */ ?>
        <?php
        $invalidIds = [];
        foreach (['debit' => 'journal-debit', 'date' => 'journal-date', 'text' => 'journal-text', 'credit' => 'journal-credit', 'amount' => 'journal-amount', 'tax_code' => 'journal-tax-code', 'tax_amount' => 'journal-tax-amount'] as $field => $id) {
            if ($form->error($field) !== '') { $invalidIds[] = $id; }
        }
        ?>
        <div class="z77-form-actions">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm">Speichern (Änderung wird protokolliert)</button>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($compound) ?>"<?= $winLink ?>>Als Sammelbuchung bearbeiten …</a>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/detail?id=' . (int) $entry->getId()) ?>"<?= $winLink ?>>Abbrechen</a>
            <?= $this->partial('partials/formErrorsLink', ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''], 'Z77\\Shared') ?>
        </div>
        <?php endif; ?>

        <?php if (!$isNew && !$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Buchung <code><?= e($year->getCode() . '/' . $entry->getNumber()) ?></code> bearbeiten
                <small class="be-list__cell--muted">· Geschäftsjahr <?= e($year->getCode()) ?> (<?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?>)</small>
            </h2>
        </div>
        <?php endif; ?>

        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <datalist id="journal-accounts">
            <?php foreach ($form->postableAccounts() as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="be-reveal">
            <input class="be-reveal__toggle" type="checkbox" id="journal-vat" name="vat" value="1"<?= $form->vat() ? ' checked' : '' ?>>
            <div class="be-form__row" style="--be-form-cols: 8rem 9.5rem 4.5rem minmax(10rem, 1fr) 8rem 8rem 4.5rem">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-debit">Soll</label>
                    <input type="text" id="journal-debit" name="debit" list="journal-accounts" value="<?= e($form->debit()) ?>" inputmode="numeric" required<?= $isNew ? ' autofocus' : '' ?> aria-invalid="<?= $invalid('debit') ?>">
                    <?php if ($form->accountName($form->debit()) !== ''): ?><small class="be-form__resolved"><?= e($form->accountName($form->debit())) ?></small><?php endif; ?>
                    <?= raw($fieldError($form->error('debit'))) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-date">Datum</label>
                    <?php /* New: any date — the fiscal year follows it. Edit: the number belongs to the year, so the date stays in it. */ ?>
                    <input type="date" id="journal-date" name="date" value="<?= e($form->date()) ?>" required
                           <?php if (!$isNew): ?>min="<?= e($year->getStartDate()->format('Y-m-d')) ?>" max="<?= e($year->getEndDate()->format('Y-m-d')) ?>" <?php endif; ?>aria-invalid="<?= $invalid('date') ?>">
                    <?= raw($fieldError($form->error('date'))) ?>
                </div>
                <div class="be-form__field">
                    <label for="journal-number">Bu-Nr</label>
                    <output class="be-form__static" id="journal-number" title="<?= $isNew ? 'Die Nummer vergibt das Journal beim Buchen — lückenlos pro Geschäftsjahr.' : 'Die Nummer bleibt.' ?>"><?= $isNew ? 'neu' : e((string) $entry->getNumber()) ?></output>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-text">Text</label>
                    <input type="text" id="journal-text" name="text" value="<?= e($form->text()) ?>" maxlength="255" required aria-invalid="<?= $invalid('text') ?>">
                    <?= raw($fieldError($form->error('text'))) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-credit">Haben</label>
                    <input type="text" id="journal-credit" name="credit" list="journal-accounts" value="<?= e($form->credit()) ?>" inputmode="numeric" required aria-invalid="<?= $invalid('credit') ?>">
                    <?php if ($form->accountName($form->credit()) !== ''): ?><small class="be-form__resolved"><?= e($form->accountName($form->credit())) ?></small><?php endif; ?>
                    <?= raw($fieldError($form->error('credit'))) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-amount">Betrag</label>
                    <input type="text" id="journal-amount" name="amount" value="<?= e($form->amount()) ?>" inputmode="decimal" required aria-invalid="<?= $invalid('amount') ?>">
                    <?= raw($fieldError($form->error('amount'))) ?>
                </div>
                <div class="be-form__field">
                    <label class="be-switch be-switch--for" for="journal-vat" title="MWST-Zeile ein / aus">
                        <span class="be-switch__label">MwSt</span>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>
                </div>
            </div>

            <div class="be-reveal__panel">
                <div class="be-form__row" style="--be-form-cols: minmax(12rem, 20rem) 9rem">
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="journal-tax-code">MWST-Code</label>
                        <select id="journal-tax-code" name="tax_code" aria-invalid="<?= $invalid('tax_code') ?>">
                            <option value=""<?= $form->taxCode() === '' ? ' selected' : '' ?>>– wählen –</option>
                            <?php foreach ($form->selectableCodes() as $code): ?>
                            <option value="<?= e($code->getCode()) ?>"<?= $form->taxCode() === $code->getCode() ? ' selected' : '' ?>><?= e($code->getCode() . ' — ' . $code->getLabel()) ?><?= $code->isActive() ? '' : ' (inaktiv)' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= raw($fieldError($form->error('tax_code'))) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="journal-tax-amount">Steuerbetrag</label>
                        <input type="text" id="journal-tax-amount" name="tax_amount" value="<?= e($form->taxAmount()) ?>" inputmode="decimal" aria-invalid="<?= $invalid('tax_amount') ?>">
                        <?= raw($fieldError($form->error('tax_amount'))) ?>
                    </div>
                    <?php /* The explanations (tolerance rule, the computed VAT line) are in the help
                             (`oneLine.help`, ADR-048) — owner 2026-09-29: no text in the form. */ ?>
                </div>
            </div>
        </div>

    </form>

</div>
