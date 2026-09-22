<?php
/**
 * «Satz veröffentlichen»: lists every document of the set (all languages) and
 * says what the publish does (ContentVariantService::publish()).
 *
 * @var string $setKey
 * @var \Z77\Shared\Entities\Content[] $documents  the set, sorted by slug + language
 * @var string $entityCsrf  bound to the set key (entity 'content-set')
 */
?>
<form data-fetch-post="/backend/content/content/publish">
    <input type="hidden" name="variant"     value="<?= e($setKey) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Satz «<?= e($setKey) ?>» veröffentlichen</h2>
    </div>
    <div class="be-modal__body">
        <p>Diese <?= count($documents) ?> Dokument<?= count($documents) === 1 ? '' : 'e' ?> werden zur Live-Fassung:</p>
        <ul style="margin:0 0 .75rem;padding-left:1.1rem">
            <?php foreach ($documents as $doc): ?>
            <li><?= e($doc->getSlug()) ?> (<?= e($doc->getLanguage()) ?>)<?= $doc->isActive() ? '' : ' — inaktiv' ?></li>
            <?php endforeach; ?>
        </ul>
        <p>Jede bisherige Live-Fassung wird als Version «v-…» gesichert; wer zurück will, stellt beim betreffenden Dokument diese Version wieder her. Die Dokumente von «<?= e($setKey) ?>» verschwinden danach aus der Liste.</p>
        <p>Ein Dokument, das bisher keine Live-Fassung hatte, wird neu angelegt; dafür gibt es keine Version, bei einer Rücknahme bleibt es bestehen.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Veröffentlichen</button>
    </div>
</form>
