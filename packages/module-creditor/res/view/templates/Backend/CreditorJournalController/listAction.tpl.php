<?php
/**
 * The «Kreditorenjournal» page (creditor plan §8, stages 1b/1c). The upload lives in the TOOLBAR
 * (`toolbar.tpl.php`): its button is a drop target itself, and while files hang over the window the
 * whole work area is one too (upload.js — no `[data-upload-area]` here, so it takes the work
 * column). No intro band (owner 2026-11-16: «ein geübter User sollte die Dateien einfach auf PDF
 * hochladen ziehen können, es braucht nicht ein extra Input» — the help explains it). The upload's
 * PROGRESS runs in a window (upload.js, UPLOAD-DIALOG-001, owner 2026-11-17) — nothing of it in
 * this page. The list itself is `_journal` (the same partial the upload answer replaces).
 *
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $actionBase
 */
?>
<div class="be-list">
    <?= $this->partial('Backend/CreditorJournalController/_journal', [
        'view' => $view, 'totals' => $totals, 'definition' => $definition, 'state' => $state, 'paging' => $paging,
        'invoices' => $invoices, 'lines' => $lines, 'pdfs' => $pdfs, 'protocols' => $protocols, 'actionBase' => $actionBase, 'fmt' => $fmt,
        'progress' => $progress ?? [], 'live' => $live ?? null, 'bankLinks' => $bankLinks ?? [],
        'failedRows' => $failedRows ?? [], 'fileThere' => $fileThere ?? null, 'folderNote' => $folderNote ?? null, 'csrfToken' => $csrfToken ?? '',
    ], 'Z77\Module\Creditor') ?>
</div>
