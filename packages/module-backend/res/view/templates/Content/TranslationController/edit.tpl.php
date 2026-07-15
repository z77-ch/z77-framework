<?php
/** @var string $kind 'ui' | 'slug' */
/** @var bool $isNew */
/** @var string $formKey */
/** @var array<string, string> $formValues */
/** @var list<string> $languages */
/** @var string $defaultLang */
/** @var list<string> $errors */
/** @var string $entityCsrf */

$isSlug   = $kind === 'slug';
$keyName  = $isSlug ? 'canonical' : 'key';
$keyLabel = $isSlug ? 'Kanonischer Slug (Standardsprache)' : 'Schlüssel';
$keyHint  = $isSlug ? 'home' : 'nav.home';
$title    = ($isNew ? 'Neuer ' : '') . ($isSlug ? 'Routen-Slug' : 'UI-Text') . (!$isNew ? ' bearbeiten' : '');
?>
<form data-fetch-post>
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= e($title) ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if (!empty($errors)): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <ul style="margin:0;padding-left:1.1rem">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field">
                <label><?= e($keyLabel) ?></label>
                <input type="text" name="<?= e($keyName) ?>" value="<?= e($formKey) ?>" required autocomplete="off"
                       placeholder="<?= e($keyHint) ?>">
            </div>

            <?php foreach ($languages as $lang): ?>
            <div class="be-form__field">
                <label>
                    <?= e($lang) ?><?php if (!$isSlug && $lang === $defaultLang): ?> <small>(Master / Fallback)</small><?php endif; ?>
                </label>
                <input type="text" name="value[<?= e($lang) ?>]" value="<?= e($formValues[$lang] ?? '') ?>" autocomplete="off"
                       placeholder="<?= $isSlug ? 'leer = bleibt kanonisch' : 'leer = Fallback auf ' . e($defaultLang) ?>">
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
