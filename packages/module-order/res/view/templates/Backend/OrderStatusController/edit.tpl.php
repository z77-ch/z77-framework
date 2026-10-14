<?php
/**
 * The order-status window (plan §9.5) — one form, one save: code (fixed after creation), label,
 * level and the seven behaviour flags as switches (owner O4: status as DATA). The validator's
 * invariants come back as field errors on the switch that breaks them. A plain form, no
 * JavaScript of its own (Rule 7): `csrf_token` (`#[Csrf]`), an edit also carries the entity token.
 *
 * Fetched, a WINDOW (ADR-047): the root declares the mask `order-status-edit` and the record, the
 * title moves into the title bar, the form carries `_origin` back. The action row (ADR-049) is the
 * shared `partials/modalActions`, first in the form. No explanations in the form — the help
 * (ADR-048, `edit.help`) has a section per field and per flag.
 *
 * @var \Z77\Module\Order\Entities\OrderStatus $entry
 * @var \Z77\Module\Order\Validators\OrderStatusValidator $validator
 * @var array<string, string> $flags  field → label
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderStatusLayout::BASE;
$isNew      = $entry->getId() === null;
$window     = !empty($window);
$title      = $isNew ? 'Auftragsstatus anlegen' : 'Auftragsstatus «' . $entry->getLabel() . '»';
$action     = $actionBase . ($isNew ? '/add' : '/edit?id=' . (int) $entry->getId());
$fields     = array_merge(['code', 'label', 'level'], array_keys($flags));
$invalidIds = array_values(array_map(static fn(string $f) => 'order-status-' . $f, array_filter($fields, static fn(string $f) => $validator->hasFieldError($f))));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
$values     = $entry->flags();
$winAttr    = $window
    ? ' data-window="order-status-edit"' . ($isNew ? '' : ' data-window-entity="orderStatus:' . (int) $entry->getId() . '"')
        . ' data-window-title="' . e($title) . '" data-window-width="40rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="order-status-form" autocomplete="off" novalidate>
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

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-status-code">Code</label>
                <input type="text" id="order-status-code" name="code" value="<?= e($entry->getCode()) ?>" maxlength="16" spellcheck="false"<?= $isNew ? ' required' : ' readonly' ?> aria-invalid="<?= $invalid('code') ?>">
                <?= raw($fieldError('code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-status-label">Bezeichnung</label>
                <input type="text" id="order-status-label" name="label" value="<?= e($entry->getLabel()) ?>" maxlength="80" required aria-invalid="<?= $invalid('label') ?>">
                <?= raw($fieldError('label')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-status-level">Stufe</label>
                <input type="number" id="order-status-level" name="level" value="<?= (int) $entry->getLevel() ?>" min="1" max="<?= \Z77\Module\Order\Validators\OrderStatusValidator::MAX_LEVEL ?>" required aria-invalid="<?= $invalid('level') ?>">
                <?= raw($fieldError('level')) ?>
            </div>
        </div>

        <div class="be-form__section">Verhalten</div>
        <div class="be-form__grid">
            <?php foreach ($flags as $flag => $label): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" id="order-status-<?= e($flag) ?>" name="<?= e($flag) ?>" value="1"<?= !empty($values[$flag]) ? ' checked' : '' ?> aria-invalid="<?= $invalid($flag) ?>">
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label"><?= e(ucfirst($label)) ?></span>
                </label>
                <?= raw($fieldError($flag)) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </form>
</div>
