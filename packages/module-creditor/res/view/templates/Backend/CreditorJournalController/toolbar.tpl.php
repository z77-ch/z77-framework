<?php
/**
 * Kreditorenjournal — the toolbar (hc2): the three tabs (`.be-viewtabs`), then the tool «PDF
 * hochladen» — the kernel upload in its `cell` shape (UPLOAD-001: a click opens the file dialog,
 * several PDFs at once; files dragged ONTO the button are taken — it shows a dashed frame while
 * they hang over it — and so are files dropped anywhere on the work area; the queue runs in a
 * window, UPLOAD-DIALOG-001, owner 2026-11-17; owner 2026-11-16: no intro band). It stood in the action cell until
 * «Journal verbuchen …» took that place (stage 1c, plan §8). «Hilfe» / «KI-Assistent» is written by
 * the shell at the right end (HELP-003). «Gesperrt» names its count while invoices wait for the
 * owner's decision on a suspected duplicate; «Nicht eingelesen» (stage 2; internal key `intake`)
 * the files of the intake folder that could not be read (owner 2026-11-15: «Eingang» read like the
 * inbox of all invoices).
 *
 * Beside the upload the switch «KI-Erkennung» (owner 2026-11-16: «ja schalter in der oberfläche
 * bauen, neben PDF hochladen button») — the owner's on/off of the AI step (`AiSwitch`), rendered
 * only where the AI is released for the installation (`$aiSwitch` null = not released). A
 * `.be-switch` (the one on/off look, backend-screen.md §3): with the script the core
 * `data-fetch-toggle` POSTs the new state; without it the form posts the checkbox through the
 * `<noscript>` button «Übernehmen». On a phone the label shortens to «KI» (`.be-switch__label-more`);
 * the input keeps the full name for a screen reader.
 *
 * Part of the fragment: added by the trait.
 *
 * @var string $view
 * @var array{blocked: int, failed?: int} $totals
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool|null $aiSwitch the switch «KI-Erkennung»: on / off; null = the AI is not released (no switch)
 * @var bool $bankAvailable a bank account reads statements → the tool «Zahlungen suchen …» (ADR-061
 *                          stage C1: the window with the bank movements that fit the invoices)
 */
use Z77\Module\Creditor\Ui\CreditorJournalListing;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$blocked    = (int) ($totals['blocked'] ?? 0);
$failed     = (int) ($totals['failed'] ?? 0);
$tabs       = [
    CreditorJournalListing::VIEW_OPEN    => 'Im Journal',
    CreditorJournalListing::VIEW_BLOCKED => 'Gesperrt' . ($blocked > 0 ? ' (' . $blocked . ')' : ''),
    CreditorJournalListing::VIEW_POSTED  => 'Verbuchte Journale',
    CreditorJournalListing::VIEW_INTAKE  => 'Nicht eingelesen' . ($failed > 0 ? ' (' . $failed . ')' : ''),
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
<?php if (!empty($bankAvailable)): $bankUrl = $actionBase . '/confirm-bank'; ?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($bankUrl) ?>" data-window-open="<?= e($bankUrl) ?>"<?= isset($definition) ? ' data-origin="region:' . e($definition->region()) . '"' : '' ?> data-bank-search title="Auf dem Kontoauszug die Zahlungen zu den Rechnungen suchen">Zahlungen suchen …</a>
<?php endif; ?>
<?php if (is_bool($aiSwitch ?? null)): ?>
<form method="post" action="<?= e($actionBase . '/ai-switch') ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
    <label class="be-switch be-switch--sm" title="KI-Erkennung: Ist sie an, liest die KI Rechnungen, die das System allein nicht lesen kann (z.B. einen Scan). Das kostet pro Rechnung ein paar Rappen. Ist sie aus, erfasst du diese Rechnungen von Hand.">
        <input type="checkbox" class="be-switch__input" name="value" value="1" aria-label="KI-Erkennung"
               data-fetch-toggle="<?= e($actionBase . '/ai-switch') ?>"<?= $aiSwitch ? ' checked' : '' ?>>
        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
        <span class="be-switch__label">KI<span class="be-switch__label-more">-Erkennung</span></span>
    </label>
    <noscript><button type="submit" class="be-btn be-btn--ghost be-btn--sm">Übernehmen</button></noscript>
</form>
<?php endif; ?>
