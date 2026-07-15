<?php
/** @var \Z77\Shared\Entities\Navigation $entry */
/** @var \Z77\Shared\Entities\Navigation|null $parent */
/** @var ?string $lockedSlot */
/** @var array<string, string> $navSlots slug => label (config, ADR-022) */
/** @var \Z77\Shared\Entities\Navigation[] $refTargets */
/** @var string $entityCsrf */
/** @var \Z77\Persistence\Validation\EntityValidator $validator */

$isNew    = $entry->getId() === null;
$hasParent = $isNew && isset($parent) && $parent !== null;
$currentRef = $entry->getRef();

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post data-check-url="/backend/content/navigation/check-field">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <?php if ($hasParent): ?>
    <input type="hidden" name="parent_id" value="<?= e($parent->getId()) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title">
            <?php if ($hasParent): ?>
                Neues Kind in «<?= e($parent->getName()) ?>»
            <?php else: ?>
                <?= $isNew ? 'Neuer Eintrag' : 'Eintrag bearbeiten' ?>
            <?php endif; ?>
        </h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
        <div class="be-modal__switches">
            <label class="be-switch">
                <input type="checkbox" class="be-switch__input" name="active" value="1"<?= $entry->isActive() ? ' checked' : '' ?>>
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                <span class="be-switch__label">Aktiv <small>(deaktivierte Einträge erscheinen nicht in Navigation/Sidebar; URL bleibt erreichbar)</small></span>
            </label>
        </div>
        <div class="be-form__section">Allgemein</div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Name</label>
                <input type="text" name="name" value="<?= e($entry->getName()) ?>" required autocomplete="off"
                       aria-invalid="<?= $validator->hasFieldError('name') ? 'true' : 'false' ?>">
                <?= raw($fieldError('name')) ?>
            </div>
        </div>
        <p style="font-size:.75rem;color:var(--be-muted,#94a3b8);margin:-.25rem 0 .5rem">
            Öffentliche URLs (Aliase) werden separat unter
            <a href="/backend/content/navigation-alias/list">URL-Aliase</a> verwaltet (ADR-015).
        </p>
        <div class="be-form__section">Verweis <small>(optional — Eintrag zeigt auf existierende Seite; Routing-Felder bleiben leer)</small></div>
        <div class="be-form__field" data-z77-field-wrapper>
            <select name="ref">
                <option value="">— kein Verweis</option>
                <?php foreach ($refTargets as $target): ?>
                <option value="<?= e($target->getId()) ?>"<?= $currentRef === $target->getId() ? ' selected' : '' ?>>
                    #<?= e($target->getId()) ?> · <?= e($target->getName()) ?> (<?= e($target->getUrl()) ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?= raw($fieldError('ref')) ?>
        </div>
        <div data-section="routing">
            <div class="be-form__section">Routing <small>(alle vier setzen für eine Seite, alle vier leer lassen für Öffner/Container)</small></div>
            <div class="be-form__grid">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Modul</label>
                    <input type="text" name="module" value="<?= e($entry->getModule()) ?>" autocomplete="off"
                           aria-invalid="<?= $validator->hasFieldError('module') ? 'true' : 'false' ?>">
                    <?= raw($fieldError('module')) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Group</label>
                    <input type="text" name="group" value="<?= e($entry->getGroup()) ?>" autocomplete="off"
                           aria-invalid="<?= $validator->hasFieldError('group') ? 'true' : 'false' ?>">
                    <?= raw($fieldError('group')) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Controller</label>
                    <input type="text" name="controller" value="<?= e($entry->getController()) ?>" autocomplete="off"
                           aria-invalid="<?= $validator->hasFieldError('controller') ? 'true' : 'false' ?>">
                    <?= raw($fieldError('controller')) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Action</label>
                    <input type="text" name="action" value="<?= e($entry->getAction()) ?>" autocomplete="off"
                           aria-invalid="<?= $validator->hasFieldError('action') ? 'true' : 'false' ?>">
                    <?= raw($fieldError('action')) ?>
                </div>
            </div>
        </div>
        <div class="be-form__section">UI-Parameter <small>(optional — als Query an den Link gehängt; setzt eine ziel-seitige Session-Ansicht)</small></div>
        <div class="be-form__field" data-z77-field-wrapper>
            <label>Parameter</label>
            <input type="text" name="param" value="<?= e($entry->getParam()) ?>" autocomplete="off" placeholder="key=front">
            <small style="font-size:.72rem;color:var(--be-muted,#94a3b8)">z.B. <code>key=front</code> öffnet den DMS-Drive auf den Root «front»; <code>key=</code> setzt zurück auf die Vollansicht.</small>
        </div>
        <?php if ($hasParent): ?>
        <input type="hidden" name="slot" value="">
        <?php elseif ($isNew && $lockedSlot !== null): ?>
        <input type="hidden" name="slot" value="<?= e($lockedSlot) ?>">
        <?php else: ?>
        <div class="be-form__section">Slot <small>(Render-Slot dieser Umgebung; Kinder lassen leer)</small></div>
        <div class="be-form__field" data-z77-field-wrapper>
            <div class="be-form__tag-grid">
                <label class="be-choice">
                    <input type="radio" class="be-choice__input" name="slot" value=""<?= $entry->getSlot() === '' ? ' checked' : '' ?>>
                    <span class="be-choice__label"><em>— keiner (Kind eines anderen Eintrags)</em></span>
                </label>
                <?php foreach ($navSlots as $slug => $label): ?>
                <label class="be-choice">
                    <input type="radio" class="be-choice__input" name="slot" value="<?= e($slug) ?>"<?= $entry->getSlot() === $slug ? ' checked' : '' ?>>
                    <span class="be-choice__label"><?= e($label) ?> <small style="color:var(--be-muted,#94a3b8)"><?= e($slug) ?></small></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?= raw($fieldError('slot')) ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
