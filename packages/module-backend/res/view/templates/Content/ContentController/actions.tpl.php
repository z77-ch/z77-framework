<?php
/**
 * Content row action hub (⋮): edit + delete; «Variante anlegen» on a live row,
 * «Vorschau öffnen» + «Satz veröffentlichen» on a variant row. Identity is
 * (slug, language, variant) — variant '' = the live copy. Launches the specific
 * modals via data-fetch-get (a click replaces this hub). Mirrors the DMS drive actions hub.
 *
 * @var \Z77\Shared\Entities\Content $entry
 * @var string $previewUrl  website start page with ?preview=<set>, '' on a live row
 */
$qs  = 'slug=' . rawurlencode($entry->getSlug())
     . '&language=' . rawurlencode($entry->getLanguage())
     . '&variant=' . rawurlencode($entry->getVariant());
$ic  = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($entry->getTitle() !== '' ? $entry->getTitle() : $entry->getSlug()) ?>»<?= $entry->isLive() ? '' : ' · Variante ' . e($entry->getVariant()) ?></h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/content/edit?<?= e($qs) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
            <?php if ($entry->isLive()): ?>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/content/create-variant?<?= e($qs) ?>"><?= raw($ic('icon-copy')) ?> Variante anlegen</button>
            <?php else: ?>
            <a class="be-btn be-btn--ghost be-actions__item" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener"><?= raw($ic('icon-eye')) ?> Vorschau öffnen</a>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/content/confirm-publish?variant=<?= e(rawurlencode($entry->getVariant())) ?>"><?= raw($ic('icon-upload')) ?> Satz veröffentlichen</button>
            <?php endif; ?>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/content/content/confirm-delete?<?= e($qs) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
