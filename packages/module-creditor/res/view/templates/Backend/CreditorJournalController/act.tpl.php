<?php
/**
 * Kreditorenjournal — the action cell (hc1): «↑ PDF hochladen», the most frequent action of stage
 * 1b (backend-screen.md §2). It IS the upload (the kernel `partials/upload` in its `cell` shape,
 * UPLOAD-001): a click opens the file dialog, several PDFs at once; while files hang over the window
 * the whole work area is the drop target, and the queue moves into `[data-upload-progress]` of the
 * list. In stage 1c «Journal verbuchen …» takes this cell (creditor plan §8).
 *
 * Part of the fragment: added by the trait.
 *
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $csrfToken
 */
?>
<?= $this->partial('partials/upload', [
    'policy'    => $uploadPolicy->withShape('cell'),
    'csrfField' => '<input type="hidden" name="csrf_token" value="' . e($csrfToken ?? '') . '">',
], 'Z77\Shared') ?>
