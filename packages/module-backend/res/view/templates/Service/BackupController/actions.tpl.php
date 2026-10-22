<?php
/**
 * Backup row context menu (⋮, core.js «context menu», owner 2026-10-22): download + delete
 * for one archive. Download is a plain link (binary FileResponse — deliberately NOT a fetch);
 * delete opens the confirm dialog via data-fetch-get.
 *
 * Above the download limit (BACKUP-DOWNLOAD-LIMIT-001, see BackupController) there is no
 * link: the web server would cut the file and the zip would not open. A short line above the
 * items names the server path for SFTP instead.
 *
 * @var string      $type     'data' | 'db' | 'full'
 * @var string      $fileName the archive file name
 * @var int         $size     archive size in bytes
 * @var string|null $sftpPath absolute server path when the archive is over the limit, else null
 */
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$qs = 'type=' . e(rawurlencode($type)) . '&file=' . e(rawurlencode($fileName));
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($fileName) ?>»">
    <?php if ($sftpPath !== null): ?>
    <p class="be-menu__note be-menu__note--error">
        <?= e(number_format($size / 1048576, 1, '.', "'")) ?> MB — über 500 MB bricht der Download ab. Per SFTP holen:
        <code><?= e($sftpPath) ?></code>
    </p>
    <?php else: ?>
    <a class="be-menu__item" role="menuitem" href="/backend/service/backup/download?<?= $qs ?>"><?= raw($ic('icon-download')) ?> Herunterladen</a>
    <?php endif; ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="/backend/service/backup/confirm-delete?<?= $qs ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
</div>
