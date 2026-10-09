<?php
/**
 * Zahlungseingänge — the action cell (hc1): «camt.054 einlesen», the most frequent action
 * of «Aufträge › Zahlungseingänge» (ADR-033 rev. 2026-10-08). Exactly one button.
 *
 * Since 2026-10-09 it IS the upload (UPLOAD-001, owner): a click opens the file dialog
 * right here instead of scrolling to a form, and while files hang over the window the whole
 * work area is the drop target. Same policy object as the zone in the list, in the `cell`
 * shape — one endpoint, one set of limits.
 *
 * Without JavaScript this renders a file field plus «Hochladen» in the cell and posts
 * normally; the list's own zone (`#bank-upload`) is the fuller version of the same thing.
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $csrfToken
 * @var string $actionBase
 */
?>
<?= $this->partial('partials/upload', [
    'policy'    => $uploadPolicy->withShape('cell'),
    'csrfField' => '<input type="hidden" name="csrf_token" value="' . e($csrfToken ?? '') . '">',
], 'Z77\Shared') ?>
