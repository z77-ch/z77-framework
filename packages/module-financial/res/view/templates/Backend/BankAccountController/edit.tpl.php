<?php
/**
 * The bank account window (ADR-060; board «Bankkonten», slim form) — Bezeichnung, Kürzel (fixed
 * after creation), IBAN (fixed once a statement was read), Konto (the chart as a `<datalist>`),
 * the switch «Kontoauszug einlesen». Labels only: what a field means is in the help (`edit.help`,
 * a section per field — backend-screen.md §5). A contradiction with a record of another module
 * for the same IBAN shows as ONE short line. One form, one save, no JavaScript of its own
 * (Rule 7): `csrf_token` (`#[Csrf]`), an edit also carries the entity token. Fetched, a WINDOW
 * (ADR-047), action row first (ADR-049). No delete.
 *
 * @var \Z77\Module\Financial\Entities\BankAccount $entry  the account, or the refused draft
 * @var bool     $isNew
 * @var int|null $entryId
 * @var \Z77\Module\Financial\Validators\BankAccountValidator $validator
 * @var list<\Z77\Module\Financial\Entities\Account> $accounts
 * @var array<string, string> $ledger  account number → name
 * @var string   $currency
 * @var bool     $ibanFixed
 * @var list<string> $hints
 * @var string   $entityCsrf
 * @var string   $csrfToken
 * @var bool     $window
 * @var string   $origin
 * @var string   $actionBase
 */
use Z77\Shared\Payment\Iban;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::ACCOUNT_BASE;
$window     = !empty($window);
$title      = $isNew ? 'Bankkonto anlegen' : 'Bankkonto «' . $entry->getLabel() . '»';
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . (int) $entryId);
$fields     = ['label', 'code', 'iban', 'ledger_account'];
$invalidIds = array_values(array_map(static fn(string $f) => 'bank-account-' . $f, array_filter($fields, static fn(string $f) => $validator->hasFieldError($f))));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
$ledgerName = $ledger[$entry->getLedgerAccount()] ?? '';
$winAttr    = $window
    ? ' data-window="bank-account-edit"' . ($isNew ? '' : ' data-window-entity="bankAccount:' . (int) $entryId . '"')
        . ' data-window-title="' . e($title) . '" data-window-width="40rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="bank-account-form" autocomplete="off" novalidate>
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

        <?php if ($validator->getErrors() !== [] || $validator->hasFieldError('currency')): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($validator->hasFieldError('currency')): ?><div><?= e($validator->getFieldError('currency')) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php foreach ($hints as $hint): ?>
        <p class="be-form__missing" data-bank-account-hint><?= e($hint) ?></p>
        <?php endforeach; ?>

        <datalist id="bank-account-accounts">
            <?php foreach ($accounts as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="bank-account-label">Bezeichnung</label>
                <input type="text" id="bank-account-label" name="label" value="<?= e($entry->getLabel()) ?>" maxlength="80" required aria-invalid="<?= $invalid('label') ?>">
                <?= raw($fieldError('label')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="bank-account-code">Kürzel</label>
                <input type="text" id="bank-account-code" name="code" value="<?= e($entry->getCode()) ?>" maxlength="16" spellcheck="false"<?= $isNew ? ' required' : ' readonly' ?> aria-invalid="<?= $invalid('code') ?>">
                <?= raw($fieldError('code')) ?>
            </div>
            <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                <label for="bank-account-iban">IBAN</label>
                <input type="text" id="bank-account-iban" name="iban" value="<?= e(Iban::format($entry->getIban())) ?>" maxlength="42" spellcheck="false"<?= $ibanFixed ? ' readonly' : ' required' ?> aria-invalid="<?= $invalid('iban') ?>">
                <?= raw($fieldError('iban')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="bank-account-ledger_account">Konto</label>
                <input type="text" id="bank-account-ledger_account" name="ledger_account" list="bank-account-accounts" value="<?= e($entry->getLedgerAccount()) ?>" inputmode="numeric" required aria-invalid="<?= $invalid('ledger_account') ?>">
                <?php if ($ledgerName !== ''): ?><small class="be-form__resolved"><?= e($ledgerName) ?></small><?php endif; ?>
                <?= raw($fieldError('ledger_account')) ?>
            </div>
            <div class="be-form__field">
                <label for="bank-account-currency">Währung</label>
                <input type="text" id="bank-account-currency" value="<?= e($currency) ?>" readonly data-help-key="currency">
            </div>
            <div class="be-form__field be-form__field--full">
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" id="bank-account-statement_import" name="statement_import" value="1"<?= $entry->isStatementImport() ? ' checked' : '' ?>>
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Kontoauszug einlesen</span>
                </label>
            </div>
        </div>
    </form>
</div>
