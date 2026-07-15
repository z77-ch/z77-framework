<?php
/** @var \Z77\Shared\Entities\MetaData $meta */
/** @var \Z77\Shared\Entities\Navigation|null $page */
/** @var bool $isNew */
/** @var string $entityCsrf */
/** @var \Z77\Persistence\Validation\EntityValidator $validator */
/** @var string $rawLd */

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};

// Editing textarea content: prefer the raw (rejected) submission so the user keeps
// their input; otherwise pretty-print the stored JSON-LD. Empty map → empty field.
$ldValue = $rawLd;
if ($ldValue === '') {
    $ld = $meta->getApplicationLd();
    $ldValue = $ld === [] ? '' : json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

$pageLabel = $page !== null
    ? $page->getName() . ' (' . $page->getUrl() . ')'
    : ($meta->getNavigationId() ? '#' . $meta->getNavigationId() : '—');
?>
<form data-fetch-post>
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php else: ?>
    <input type="hidden" name="navigation_id" value="<?= e((string)$meta->getNavigationId()) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Metadaten anlegen' : 'Metadaten bearbeiten' ?></h2>
        <span class="be-lang-tag" title="Bearbeitungssprache"><?= e(strtoupper($meta->getLanguage())) ?></span>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">Bitte überprüfe die markierten Eingaben.</div>
        <?php endif; ?>

        <div class="be-form__grid" style="grid-template-columns:2fr 1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Seite</label>
                <input type="text" value="<?= e($pageLabel) ?>" disabled>
                <?= raw($fieldError('navigation_id')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Sprache</label>
                <input type="text" name="language" value="<?= e($meta->getLanguage()) ?>" autocomplete="off" disabled
                       aria-invalid="<?= $validator->hasFieldError('language') ? 'true' : 'false' ?>">
                <small class="be-form__hint"><?= $isNew ? 'Folgt der Bearbeitungssprache' : 'Sprache ist unveränderlich' ?></small>
                <?= raw($fieldError('language')) ?>
            </div>
        </div>

        <div class="be-form__field" data-z77-field-wrapper>
            <label>Titel</label>
            <input type="text" name="title" value="<?= e($meta->getTitle()) ?>" required autocomplete="off"
                   aria-invalid="<?= $validator->hasFieldError('title') ? 'true' : 'false' ?>">
            <?= raw($fieldError('title')) ?>
        </div>

        <div class="be-form__field" data-z77-field-wrapper>
            <label>Beschreibung</label>
            <textarea name="description" rows="2" autocomplete="off"
                      aria-invalid="<?= $validator->hasFieldError('description') ? 'true' : 'false' ?>"><?= e($meta->getDescription()) ?></textarea>
            <?= raw($fieldError('description')) ?>
        </div>

        <div class="be-form__field" data-z77-field-wrapper>
            <label>Theme-Color</label>
            <input type="text" name="theme_color" value="<?= e($meta->getThemeColor()) ?>" autocomplete="off"
                   placeholder="#ffffff"
                   aria-invalid="<?= $validator->hasFieldError('theme_color') ? 'true' : 'false' ?>">
            <?= raw($fieldError('theme_color')) ?>
        </div>

        <div class="be-form__field" data-z77-field-wrapper>
            <label>JSON-LD <small>(strukturierte Daten, optional)</small></label>
            <textarea name="application_ld" rows="10" spellcheck="false" autocomplete="off"
                      style="font-family:var(--be-mono,monospace);font-size:.8rem"
                      aria-invalid="<?= $validator->hasFieldError('application_ld') ? 'true' : 'false' ?>"><?= e($ldValue) ?></textarea>
            <?= raw($fieldError('application_ld')) ?>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
