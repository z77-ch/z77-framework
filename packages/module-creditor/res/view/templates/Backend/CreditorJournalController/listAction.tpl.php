<?php
/**
 * The «Kreditorenjournal» page (creditor plan §8, stages 1b/1c). The upload itself lives in the
 * TOOLBAR (`toolbar.tpl.php`); here is the AREA a dropped file lands on and the place its PROGRESS goes
 * (`[data-upload-progress]` — upload.js moves the queue of a `cell` component there), kept OUTSIDE
 * the list region, so a region reload after the run leaves the rows of refused files readable.
 * The list itself is `_journal` (the same partial the upload answer replaces).
 *
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $actionBase
 */
?>
<div class="be-list">
    <div class="be-list__section" data-upload-area data-upload-progress>
        <p class="be-form__hint">Lieferantenrechnungen als PDF hierher ziehen oder «PDF hochladen». Jede wird eine Zeile im Journal und unverändert unter «Kreditorenrechnungen» in den Dokumenten abgelegt. In die Buchhaltung kommt sie erst bezahlt, mit «Journal verbuchen …».</p>
    </div>
    <?= $this->partial('Backend/CreditorJournalController/_journal', [
        'view' => $view, 'totals' => $totals, 'definition' => $definition, 'state' => $state, 'paging' => $paging,
        'invoices' => $invoices, 'lines' => $lines, 'pdfs' => $pdfs, 'protocols' => $protocols, 'actionBase' => $actionBase, 'fmt' => $fmt,
        'progress' => $progress ?? [], 'live' => $live ?? null,
        'failedRows' => $failedRows ?? [], 'fileThere' => $fileThere ?? null, 'folderNote' => $folderNote ?? null, 'csrfToken' => $csrfToken ?? '',
    ], 'Z77\Module\Creditor') ?>
</div>
