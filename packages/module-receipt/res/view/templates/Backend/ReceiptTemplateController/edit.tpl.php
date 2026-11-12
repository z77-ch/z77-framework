<?php
/**
 * The template window (board «Belegvorlagen», owner-approved 2026-11-12; E10: no Haben — the credit
 * side is the payment method's) — one form, one save: Name (the tile's caption), Code (fixed after
 * creation), Symbol (the fixed set `ReceiptTemplate::ICONS`; '' = the app's default), Soll (the
 * chart's `<datalist>`), MWST-Code («ohne MWST» a deliberate choice — a template is decided in full),
 * Reihenfolge. Active is the list's switch. A plain form, no JavaScript of its own (Rule 7):
 * `csrf_token` (`#[Csrf]`), an edit also carries the entity token.
 *
 * Fetched, a WINDOW (ADR-047): the root declares the mask `receipt-template-edit` and the record,
 * the title moves into the title bar, the form carries `_origin` back. The action row (ADR-049) is
 * the shared `partials/modalActions`, first in the form. No delete (receipts keep the code) — the
 * facts line says how many receipts carry it. No explanations in the form — the help (`edit.help`)
 * has a section per field.
 *
 * @var \Z77\Module\Receipt\Entities\ReceiptTemplate $entry
 * @var \Z77\Module\Receipt\Validators\ReceiptTemplateValidator $validator
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var list<\Z77\Module\Financial\Entities\Account> $accounts
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
 * @var int    $usage
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Receipt\Entities\ReceiptTemplate;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::TEMPLATE_BASE;
$isNew      = $entry->getId() === null;
$window     = !empty($window);
$title      = $isNew ? 'Belegvorlage anlegen' : 'Belegvorlage «' . $entry->getLabel() . '»';
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . (int) $entry->getId());
$fields     = ['label', 'code', 'icon', 'debit_account', 'tax_code', 'position'];
$invalidIds = array_values(array_map(static fn(string $f) => 'receipt-template-' . $f, array_filter($fields, static fn(string $f) => $validator->hasFieldError($f))));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
$icon       = $entry->getIcon();
$winAttr    = $window
    ? ' data-window="receipt-template-edit"' . ($isNew ? '' : ' data-window-entity="receiptTemplate:' . (int) $entry->getId() . '"')
        . ' data-window-title="' . e($title) . '" data-window-width="40rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="receipt-template-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'     => $isNew ? 'Anlegen' : 'Speichern',
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

        <datalist id="receipt-template-accounts">
            <?php foreach ($accounts as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="receipt-template-label">Name</label>
                <input type="text" id="receipt-template-label" name="label" value="<?= e($entry->getLabel()) ?>" maxlength="80" required aria-invalid="<?= $invalid('label') ?>">
                <?= raw($fieldError('label')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="receipt-template-code">Code</label>
                <input type="text" id="receipt-template-code" name="code" value="<?= e($entry->getCode()) ?>" maxlength="16" spellcheck="false"<?= $isNew ? ' required' : ' readonly' ?> aria-invalid="<?= $invalid('code') ?>">
                <?= raw($fieldError('code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="receipt-template-icon">Symbol</label>
                <select id="receipt-template-icon" name="icon" aria-invalid="<?= $invalid('icon') ?>">
                    <option value=""<?= $icon === '' ? ' selected' : '' ?>>Standard</option>
                    <?php foreach (ReceiptTemplate::ICONS as $key => $name): ?>
                    <option value="<?= e($key) ?>"<?= $icon === $key ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                    <?php if ($icon !== '' && !isset(ReceiptTemplate::ICONS[$icon])): ?>
                    <option value="<?= e($icon) ?>" selected><?= e($icon) ?></option>
                    <?php endif; ?>
                </select>
                <?= raw($fieldError('icon')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="receipt-template-position">Reihenfolge</label>
                <input type="number" id="receipt-template-position" name="position" value="<?= (int) $entry->getPosition() ?>" min="0" max="9999" required aria-invalid="<?= $invalid('position') ?>">
                <?= raw($fieldError('position')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="receipt-template-debit_account">Soll</label>
                <input type="text" id="receipt-template-debit_account" name="debit_account" list="receipt-template-accounts" value="<?= e($entry->getDebitAccount()) ?>" inputmode="numeric" required aria-invalid="<?= $invalid('debit_account') ?>">
                <?php if ($choices->accountName($entry->getDebitAccount()) !== ''): ?><small class="be-form__resolved"><?= e($choices->accountName($entry->getDebitAccount())) ?></small><?php endif; ?>
                <?= raw($fieldError('debit_account')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="receipt-template-tax_code">MWST-Code</label>
                <select id="receipt-template-tax_code" name="tax_code" aria-invalid="<?= $invalid('tax_code') ?>">
                    <option value=""<?= $entry->getTaxCode() === '' ? ' selected' : '' ?>>ohne MWST</option>
                    <?php foreach ($taxCodes as $code): ?>
                    <option value="<?= e($code->getCode()) ?>"<?= $entry->getTaxCode() === $code->getCode() ? ' selected' : '' ?>><?= e($code->getCode() . ' — ' . $code->getLabel()) ?><?= $code->isActive() ? '' : ' (inaktiv)' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('tax_code')) ?>
            </div>
        </div>
        <?php if (!$isNew): ?>
        <p class="be-form__hint" data-template-usage="<?= (int) $usage ?>"><?= $usage === 0 ? 'Noch kein Beleg trägt diese Vorlage.' : ($usage === 1 ? '1 Beleg trägt' : (int) $usage . ' Belege tragen') . ' diese Vorlage — nicht mehr gewünscht: in der Liste deaktivieren.' ?><?= $entry->isActive() ? '' : ' Inaktiv: nicht mehr auf dem Handy.' ?></p>
        <?php endif; ?>
    </form>
</div>
