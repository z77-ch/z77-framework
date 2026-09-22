<?php
/**
 * «Wiederherstellen» (version rows): says what ContentVariantService::restore()
 * does — the version becomes live, the current live copy becomes a version.
 *
 * @var \Z77\Shared\Entities\Content      $version
 * @var \Z77\Shared\Entities\Content|null $live    the current live copy, null if none
 * @var string $entityCsrf  bound to the version's key (slug.language.variant)
 */
$when = function (string $iso): string {
    try {
        return $iso !== '' ? (new DateTimeImmutable($iso))->format('d.m.Y H:i') : '';
    } catch (Exception) {
        return '';
    }
};
$stand = function (\Z77\Shared\Entities\Content $c) use ($when): string {
    $at = $when($c->getChangedAt());
    if ($at === '') {
        return 'ein älterer Stand ohne Angabe';
    }
    return 'Stand vom ' . $at . ($c->getChangedBy() !== '' ? ' (' . $c->getChangedBy() . ')' : '');
};
?>
<form data-fetch-post="/backend/content/content/restore">
    <input type="hidden" name="slug"        value="<?= e($version->getSlug()) ?>">
    <input type="hidden" name="language"    value="<?= e($version->getLanguage()) ?>">
    <input type="hidden" name="variant"     value="<?= e($version->getVariant()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Version wiederherstellen</h2>
        <span class="be-lang-tag" title="Sprache"><?= e(strtoupper($version->getLanguage())) ?></span>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($version->getTitle() !== '' ? $version->getTitle() : $version->getSlug()) ?>» — <?= e($stand($version)) ?> — wird wieder die Live-Fassung.</p>
        <?php if ($live !== null): ?>
        <p>Die jetzige Live-Fassung (<?= e($stand($live)) ?>) wird vorher als Version gesichert; wer zurück will, stellt diese wieder her.</p>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Wiederherstellen</button>
    </div>
</form>
