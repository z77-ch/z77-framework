<?php
/**
 * Kreditorenjournal — the toolbar (hc2): the three tabs (`.be-viewtabs`), then the tool «PDF
 * hochladen» — the kernel upload in its `cell` shape (UPLOAD-001: a click opens the file dialog,
 * several PDFs at once; while files hang over the window the whole work area is the drop target,
 * the queue moves into `[data-upload-progress]` of the list). It stood in the action cell until
 * «Journal verbuchen …» took that place (stage 1c, plan §8). «Hilfe» / «KI-Assistent» is written by
 * the shell at the right end (HELP-003). «Gesperrt» names its count while invoices wait for the
 * owner's decision on a suspected duplicate; «Eingang» (stage 2) the files of the intake folder
 * that could not be read.
 *
 * Part of the fragment: added by the trait.
 *
 * @var string $view
 * @var array{blocked: int, failed?: int} $totals
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $csrfToken
 * @var string $actionBase
 */
use Z77\Module\Creditor\Ui\CreditorJournalListing;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$blocked    = (int) ($totals['blocked'] ?? 0);
$failed     = (int) ($totals['failed'] ?? 0);
$tabs       = [
    CreditorJournalListing::VIEW_OPEN    => 'Im Journal',
    CreditorJournalListing::VIEW_BLOCKED => 'Gesperrt' . ($blocked > 0 ? ' (' . $blocked . ')' : ''),
    CreditorJournalListing::VIEW_POSTED  => 'Verbuchte Journale',
    CreditorJournalListing::VIEW_INTAKE  => 'Eingang' . ($failed > 0 ? ' (' . $failed . ')' : ''),
];
?>
<nav class="be-viewtabs" aria-label="Kreditorenjournal">
    <?php foreach ($tabs as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($actionBase . '/list' . ($key === CreditorJournalListing::VIEW_OPEN ? '' : '?view=' . $key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (isset($uploadPolicy)): ?>
<?= $this->partial('partials/upload', [
    'policy'    => $uploadPolicy->withShape('cell'),
    'csrfField' => '<input type="hidden" name="csrf_token" value="' . e($csrfToken ?? '') . '">',
], 'Z77\Shared') ?>
<?php endif; ?>
