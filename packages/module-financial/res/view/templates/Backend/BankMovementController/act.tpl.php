<?php
/**
 * Bankkonto — the action cell (hc1): «camt.053 Upload», the most frequent action of «Finanzen ›
 * Bankkonto» (backend-screen.md §2). It IS the upload: the shared upload component in its `cell`
 * shape — a click opens the file dialog, and while files hang over the window the whole work
 * area is the drop target. Without JavaScript a file field plus «Hochladen».
 *
 * Part of the fragment: added by `listAction()` when a bank account takes statements.
 *
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $csrfToken
 */
?>
<?= $this->partial('partials/upload', [
    'policy'    => $uploadPolicy->withShape('cell'),
    'csrfField' => '<input type="hidden" name="csrf_token" value="' . e($csrfToken ?? '') . '">',
], 'Z77\Shared') ?>
