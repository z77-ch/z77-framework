<?php
/**
 * The payment-method window («Bezahlt mit», owner decision E10) — the look of the template window:
 * Name (what the phone offers), Code (fixed after creation), Haben (the chart's `<datalist>`),
 * Reihenfolge. Active is the list's switch. One form, one save, no JavaScript of its own (Rule 7):
 * `csrf_token` (`#[Csrf]`), an edit also carries the entity token. Fetched, a WINDOW (ADR-047),
 * action row first (ADR-049). No delete (receipts keep the code). The help (`edit.help`) has a
 * section per field.
 *
 * @var \Z77\Module\Receipt\Entities\PaymentMethod $entry
 * @var \Z77\Module\Receipt\Validators\PaymentMethodValidator $validator
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var list<\Z77\Module\Financial\Entities\Account> $accounts
 * @var int    $usage
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::PAYMENT_METHOD_BASE;
$isNew      = $entry->getId() === null;
$window     = !empty($window);
$title      = $isNew ? 'Zahlungsart anlegen' : 'Zahlungsart «' . $entry->getLabel() . '»';
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . (int) $entry->getId());
$fields     = ['label', 'code', 'credit_account', 'position'];
$invalidIds = array_values(array_map(static fn(string $f) => 'payment-method-' . $f, array_filter($fields, static fn(string $f) => $validator->hasFieldError($f))));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
$winAttr    = $window
    ? ' data-window="payment-method-edit"' . ($isNew ? '' : ' data-window-entity="paymentMethod:' . (int) $entry->getId() . '"')
        . ' data-window-title="' . e($title) . '" data-window-width="40rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="payment-method-form" autocomplete="off" novalidate>
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

        <datalist id="payment-method-accounts">
            <?php foreach ($accounts as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-method-label">Name</label>
                <input type="text" id="payment-method-label" name="label" value="<?= e($entry->getLabel()) ?>" maxlength="80" required aria-invalid="<?= $invalid('label') ?>">
                <?= raw($fieldError('label')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-method-code">Code</label>
                <input type="text" id="payment-method-code" name="code" value="<?= e($entry->getCode()) ?>" maxlength="16" spellcheck="false"<?= $isNew ? ' required' : ' readonly' ?> aria-invalid="<?= $invalid('code') ?>">
                <?= raw($fieldError('code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-method-credit_account">Haben</label>
                <input type="text" id="payment-method-credit_account" name="credit_account" list="payment-method-accounts" value="<?= e($entry->getCreditAccount()) ?>" inputmode="numeric" required aria-invalid="<?= $invalid('credit_account') ?>">
                <?php if ($choices->accountName($entry->getCreditAccount()) !== ''): ?><small class="be-form__resolved"><?= e($choices->accountName($entry->getCreditAccount())) ?></small><?php endif; ?>
                <?= raw($fieldError('credit_account')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="payment-method-position">Reihenfolge</label>
                <input type="number" id="payment-method-position" name="position" value="<?= (int) $entry->getPosition() ?>" min="0" max="9999" required aria-invalid="<?= $invalid('position') ?>">
                <?= raw($fieldError('position')) ?>
            </div>
        </div>
        <?php if (!$isNew): ?>
        <p class="be-form__hint" data-method-usage="<?= (int) $usage ?>"><?= $usage === 0 ? 'Noch kein Beleg trägt diese Zahlungsart.' : ($usage === 1 ? '1 Beleg trägt' : (int) $usage . ' Belege tragen') . ' diese Zahlungsart — nicht mehr gewünscht: in der Liste deaktivieren.' ?><?= $entry->isActive() ? '' : ' Inaktiv: nicht mehr auf dem Handy.' ?></p>
        <?php endif; ?>
    </form>
</div>
