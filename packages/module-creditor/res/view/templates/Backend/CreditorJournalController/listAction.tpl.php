<?php
/**
 * The «Kreditorenjournal» page (creditor plan §8, stages 1b/1c). The upload lives in the TOOLBAR
 * (`toolbar.tpl.php`): its button is a drop target itself, and while files hang over the window the
 * whole work area is one too (upload.js — no `[data-upload-area]` here, so it takes the work
 * column). No intro band (owner 2026-11-16: «ein geübter User sollte die Dateien einfach auf PDF
 * hochladen ziehen können, es braucht nicht ein extra Input» — the help explains it). What stays is
 * the place the upload's PROGRESS goes (`[data-upload-progress]` — upload.js moves the queue of a
 * `cell` component there; empty, it shows nothing), kept OUTSIDE the list region, so a region
 * reload after the run leaves the rows of refused files readable. The list itself is `_journal`
 * (the same partial the upload answer replaces).
 *
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $actionBase
 */
?>
<div class="be-list">
    <div data-upload-progress></div>
    <?= $this->partial('Backend/CreditorJournalController/_journal', [
        'view' => $view, 'totals' => $totals, 'definition' => $definition, 'state' => $state, 'paging' => $paging,
        'invoices' => $invoices, 'lines' => $lines, 'pdfs' => $pdfs, 'protocols' => $protocols, 'actionBase' => $actionBase, 'fmt' => $fmt,
        'progress' => $progress ?? [], 'live' => $live ?? null,
        'failedRows' => $failedRows ?? [], 'fileThere' => $fileThere ?? null, 'folderNote' => $folderNote ?? null, 'csrfToken' => $csrfToken ?? '',
    ], 'Z77\Module\Creditor') ?>
</div>
