<?php
/**
 * Backup row action hub (⋮): download + delete for one archive. Download is a
 * plain link (binary FileResponse — deliberately NOT a fetch); delete launches
 * the confirm modal via data-fetch-get (a click replaces this hub).
 *
 * Above the download limit (BACKUP-DOWNLOAD-LIMIT-001, see BackupController) there is no
 * link: the web server would cut the file and the zip would not open. The hub names the
 * server path for SFTP instead.
 *
 * @var string      $type     'data' | 'db' | 'full'
 * @var string      $fileName the archive file name
 * @var int         $size     archive size in bytes
 * @var string|null $sftpPath absolute server path when the archive is over the limit, else null
 */
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$qs = 'type=' . e(rawurlencode($type)) . '&file=' . e(rawurlencode($fileName));
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($fileName) ?>»</h2></div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if ($sftpPath !== null): ?>
        <p class="be-modal__alert be-modal__alert--error">
            Das Archiv ist <?= e(number_format($size / 1048576, 1, '.', "'")) ?> MB gross.
            Downloads über 500 MB bricht der Webserver ab — die Datei käme unvollständig an
            und liesse sich nicht öffnen. Bitte per SFTP holen:<br>
            <code><?= e($sftpPath) ?></code>
        </p>
        <?php endif; ?>
        <div class="be-actions__list">
            <?php if ($sftpPath === null): ?>
            <a class="be-btn be-btn--ghost be-actions__item" href="/backend/service/backup/download?<?= $qs ?>"><?= raw($ic('icon-download')) ?> Herunterladen</a>
            <?php endif; ?>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/service/backup/confirm-delete?<?= $qs ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
        </div>
    </div>
</div>
