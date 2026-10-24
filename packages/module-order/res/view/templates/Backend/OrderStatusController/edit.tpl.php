<?php
/**
 * The order-status window (plan §9.5; ADR-043 addendum 2026-10-15) — one form, one save: code
 * (fixed after creation), label, PHASE (a select of the fixed phases — the phase carries all
 * behaviour), the sort order within the phase («Sortierung») and the default switch (the status an action sets). No
 * behaviour switches: a status is a label. A plain form, no JavaScript of its own (Rule 7):
 * `csrf_token` (`#[Csrf]`), an edit also carries the entity token.
 *
 * Fetched, a WINDOW (ADR-047): the root declares the mask `order-status-edit` and the record, the
 * title moves into the title bar, the form carries `_origin` back. The action row (ADR-049) is the
 * shared `partials/modalActions`, first in the form. No explanations in the form — the help
 * (ADR-048, `edit.help`) has a section per field and explains the phases. Two switches, each shown
 * where the phase knows it (owner 2026-10-16): «Startstatus für neue Aufträge» (`start` — one for
 * all, in «Eröffnet» and «Termin») and the action default (`phase_default`), worded by the phase —
 * «Wird gesetzt beim Druck der Auftragsbestätigung» … (`OrderPhase::defaultSwitchLabel()`); «Eröffnet»
 * and «Storniert» have no action default. A status of «Abgeschlossen» has a third switch, «Wird gesetzt beim
 * Verbuchen» (`set_on_posting`, owner 2026-10-24 — the status «Journal verbuchen» sets, e.g. «Gebucht»).
 * Setting a switch takes it from the status that held it.
 *
 * @var \Z77\Module\Order\Entities\OrderStatus $entry
 * @var \Z77\Module\Order\Validators\OrderStatusValidator $validator
 * @var array<string, string> $phases  value → label, along the ladder
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
$fields     = ['code', 'label', 'phase', 'position', 'start', 'phase_default', 'set_on_posting'];
$invalidIds = array_values(array_map(static fn(string $f) => 'order-status-' . $f, array_filter($fields, static fn(string $f) => $validator->hasFieldError($f))));
$fieldError = static fn(string $name): string => $validator->hasFieldError($name)
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
    : '';
$invalid    = static fn(string $name): string => $validator->hasFieldError($name) ? 'true' : 'false';
$entryPhase  = \Z77\Module\Order\Entities\OrderPhase::tryFrom($entry->getPhaseValue());
$switchLabel = $entryPhase === null ? 'Wird von der Aktion seiner Phase gesetzt' : $entryPhase->defaultSwitchLabel();
$showStart   = $entryPhase === null || $entryPhase->allowsStart();
$showPosting = $entryPhase === \Z77\Module\Order\Entities\OrderPhase::Closed;
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
                <label for="order-status-phase">Phase</label>
                <select id="order-status-phase" name="phase" required aria-invalid="<?= $invalid('phase') ?>">
                    <?php foreach ($phases as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $value === $entry->getPhaseValue() ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('phase')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-status-position">Sortierung</label>
                <input type="number" id="order-status-position" name="position" value="<?= (int) $entry->getPosition() ?>" min="0" max="<?= \Z77\Module\Order\Validators\OrderStatusValidator::MAX_POSITION ?>" required aria-invalid="<?= $invalid('position') ?>">
                <?= raw($fieldError('position')) ?>
            </div>
            <?php // Each switch where THIS status' phase knows it (owner 2026-10-16); a changed phase shows its switches after saving. ?>
            <?php if ($showStart): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" id="order-status-start" name="start" value="1"<?= $entry->isStart() ? ' checked' : '' ?> aria-invalid="<?= $invalid('start') ?>" data-help-key="start">
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Startstatus für neue Aufträge</span>
                </label>
                <?= raw($fieldError('start')) ?>
            </div>
            <?php endif; ?>
            <?php if ($switchLabel !== null): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" id="order-status-phase_default" name="phase_default" value="1"<?= $entry->isPhaseDefault() ? ' checked' : '' ?> aria-invalid="<?= $invalid('phase_default') ?>">
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label"><?= e($switchLabel) ?></span>
                </label>
                <?= raw($fieldError('phase_default')) ?>
            </div>
            <?php endif; ?>
            <?php if ($showPosting): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" id="order-status-set_on_posting" name="set_on_posting" value="1"<?= $entry->isSetOnPosting() ? ' checked' : '' ?> aria-invalid="<?= $invalid('set_on_posting') ?>">
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Wird gesetzt beim Verbuchen</span>
                </label>
                <?= raw($fieldError('set_on_posting')) ?>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>
