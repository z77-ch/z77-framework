<?php
/** @var \Z77\Shared\Entities\NavigationAlias $alias */
/** @var \Z77\Shared\Entities\Navigation[] $navOptions */
/** @var string $entityCsrf */
/** @var \Z77\Persistence\Validation\EntityValidator $validator */

$isNew = $alias->getId() === null;

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post>
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Neuer URL-Alias' : 'Alias bearbeiten' ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>

        <div class="be-modal__switches">
            <label class="be-switch">
                <input type="checkbox" class="be-switch__input" name="active" value="1"<?= $alias->isActive() ? ' checked' : '' ?>>
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                <span class="be-switch__label">Aktiv</span>
            </label>
        </div>

        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Navigations-Ziel</label>
                <select name="navigation_id" required
                        aria-invalid="<?= $validator->hasFieldError('navigation_id') ? 'true' : 'false' ?>">
                    <option value="">— wählen —</option>
                    <?php foreach ($navOptions as $opt): ?>
                    <option value="<?= e($opt->getId()) ?>"<?= $alias->getNavigationId() === $opt->getId() ? ' selected' : '' ?>>
                        <?= e($opt->getName()) ?> (<?= e($opt->getCanonicalPath()) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('navigation_id')) ?>
            </div>

            <div class="be-form__field" data-z77-field-wrapper>
                <label>Pfad (canonical, z.B. <code>/home</code> oder <code>/schweiz/stadt</code>)</label>
                <input type="text" name="path" value="<?= e($alias->getPath()) ?>" required autocomplete="off"
                       placeholder="/pfad"
                       aria-invalid="<?= $validator->hasFieldError('path') ? 'true' : 'false' ?>">
                <?= raw($fieldError('path')) ?>
            </div>

            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" name="is_canonical" value="1"<?= $alias->isCanonical() ? ' checked' : '' ?>>
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Canonical <small>(öffentlicher Einstiegspfad)</small></span>
                </label>
                <?= raw($fieldError('is_canonical')) ?>
            </div>

        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
