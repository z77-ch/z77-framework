<?php
/**
 * «Entfernen …» for the files ticked in the tab «Nicht eingelesen» (owner 2026-11-16: «nicht
 * eingelesen sollten auch alle gelöscht werden können») — a WINDOW (the selection bar's GET form
 * opens it; without the script a page): «3 Dateien entfernen?», the files with their reason, the
 * answer in the action row. The POST carries the ids of the files SHOWN as hidden `intake[]`; a
 * row gone since is skipped and named in the answer. Each file is deleted from `failed/` (no
 * trash — it never reached the DMS) and its entry removed — the same as the row's «Entfernen».
 *
 * @var list<int> $ids  the ticked ids as asked
 * @var list<\Z77\Module\Creditor\Entities\CreditorIntake> $rows  the failed rows still there
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Creditor\Ui\CreditorJournalListing;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$count      = count($rows);
$title      = ($count === 1 ? '1 Datei' : $count . ' Dateien') . ' entfernen?';
$winAttr    = $window ? ' data-window="creditor-intake-remove" data-window-entity="creditor-journal:intake-remove" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-intake-remove') ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php foreach ($rows as $row): ?>
        <input type="hidden" name="intake[]" value="<?= (int) $row->getId() ?>">
        <?php endforeach; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $count > 0 ? ($count === 1 ? 'Datei entfernen' : $count . ' Dateien entfernen') : '',
            'kind'       => 'danger',
            'cancelHref' => $window ? '' : $actionBase . '/list?view=' . CreditorJournalListing::VIEW_INTAKE,
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($count === 0): ?>
        <div class="be-modal__alert be-modal__alert--error" data-intake-remove-empty><?= $ids === [] ? 'Keine Datei angekreuzt — in der Liste die Dateien ankreuzen, die weg sollen, dann «Entfernen …».' : 'Die angekreuzten Dateien sind schon weg — die Liste neu laden.' ?></div>
        <?php else: ?>
        <p class="be-form__hint">Die Dateien werden gelöscht und verschwinden aus der Liste. Das lässt sich nicht rückgängig machen.</p>
        <div class="be-list__table" style="--be-list-cols: minmax(10rem, 1.4fr) minmax(10rem, 1fr)">
            <div class="be-list__head">
                <span class="be-list__col">Datei</span>
                <span class="be-list__col">Grund</span>
            </div>
            <?php foreach ($rows as $row): ?>
            <div class="be-list__item" data-remove-intake="<?= (int) $row->getId() ?>"><div class="be-list__row">
                <span class="be-list__cell"><?= e($row->getOriginalName()) ?></span>
                <span class="be-list__cell"><?= e($row->getReason()) ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </form>
</div>
