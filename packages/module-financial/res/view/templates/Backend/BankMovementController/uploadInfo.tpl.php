<?php
/**
 * «camt.053 Upload» — the WINDOW a click on the action cell opens BEFORE the file dialog (owner
 * 2026-11-18): one row per ACTIVE bank account with «Kontoauszug einlesen» on — Bankkonto, IBAN,
 * «eingelesen bis» and «Laden ab», the day the next download must start on (= the last stored
 * day: a file has to OVERLAP it, `BankStatementService`). «Dateien wählen …» opens the file
 * dialog of the cell's upload component (`data-upload-pick`, `upload.js`) and closes this window;
 * the run shows in the component's own window. Dropping files onto the page skips this window.
 *
 * Labels and dates only — why a file must overlap stands in the help of «Bankkonto»
 * (backend-screen.md §5). No form, nothing is posted from here.
 *
 * @var list<array{account: \Z77\Module\Financial\Entities\BankAccount, latest: ?\Z77\Module\Financial\Entities\BankStatement}> $rows
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy  the cell's policy — its file field is the one picked
 * @var bool   $window
 * @var string $actionBase
 */
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Payment\Iban;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::MOVEMENT_BASE;
$window     = !empty($window);
$title      = 'camt.053 Upload';
$winAttr    = $window ? ' data-window="bank-upload-info" data-window-entity="bank-movement:upload" data-window-title="' . e($title) . '" data-window-width="46rem"' : '';
$pick       = '<button type="button" class="be-btn be-btn--primary" data-upload-pick="' . e($uploadPolicy->withShape('cell')->inputId()) . '">Dateien wählen …</button>';
?>
<div class="be-list"<?= $winAttr ?>>
    <div class="be-list__section">
        <?= $this->partial('partials/modalActions', [
            'submit'     => '',
            'extra'      => $window ? $pick : '',
            'cancelHref' => $window ? '' : $actionBase . '/list',
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <div class="be-list__table" style="--be-list-cols: minmax(9rem, 1fr) 15rem 13rem 7rem" data-upload-accounts>
            <div class="be-list__head">
                <span class="be-list__col">Bankkonto</span>
                <span class="be-list__col">IBAN</span>
                <span class="be-list__col">Eingelesen bis</span>
                <span class="be-list__col">Laden ab</span>
            </div>
            <?php foreach ($rows as $row): ?>
            <?php $latest = $row['latest']; ?>
            <div class="be-list__item" data-upload-account="<?= e($row['account']->getCode()) ?>"><div class="be-list__row">
                <strong class="be-list__cell"><?= e($row['account']->getLabel()) ?></strong>
                <span class="be-list__cell be-list__cell--mono"><?= e(Iban::format($row['account']->getIban())) ?></span>
                <?php if ($latest !== null): ?>
                <span class="be-list__cell"><?= e($latest->getToDate()->format('d.m.Y')) ?> <small class="be-list__cell--muted">· Saldo <?= e(AmountFormat::of($latest->getClosingBalance())) ?></small></span>
                <strong class="be-list__cell be-list__cell--mono" data-upload-from><?= e($latest->getToDate()->format('d.m.Y')) ?></strong>
                <?php else: ?>
                <span class="be-list__cell be-list__cell--muted">noch nichts</span>
                <span class="be-list__cell be-list__cell--muted" data-upload-from>frei</span>
                <?php endif; ?>
            </div></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
