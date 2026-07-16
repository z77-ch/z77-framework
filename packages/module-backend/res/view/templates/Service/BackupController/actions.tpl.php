<?php
/**
 * Backup row action hub (⋮): download + delete for one archive. Download is a
 * plain link (binary FileResponse — deliberately NOT a fetch); delete launches
 * the confirm modal via data-fetch-get (a click replaces this hub).
 *
 * @var string $type     'data' | 'db' | 'full'
 * @var string $fileName the archive file name
 */
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$qs = 'type=' . e(rawurlencode($type)) . '&file=' . e(rawurlencode($fileName));
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($fileName) ?>»</h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <a class="be-btn be-btn--ghost be-actions__item" href="/backend/service/backup/download?<?= $qs ?>"><?= raw($ic('icon-download')) ?> Herunterladen</a>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/service/backup/confirm-delete?<?= $qs ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
