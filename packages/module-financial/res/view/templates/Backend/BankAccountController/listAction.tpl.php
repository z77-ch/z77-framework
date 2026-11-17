<?php
/**
 * Stammdaten › Finanzen › «Bankkonten» (ADR-060) — a STANDARD LIST over
 * {@see \Z77\Module\Financial\Ui\BankAccountListing}, rows from `_row` (the switch answers with
 * it in place, FETCH-ROW-001), a FETCH REGION reloaded by a save in the window. Labels only — the
 * explanations are in the help (backend-screen.md §5). No CSS and no JavaScript of its own.
 *
 * @var list<\Z77\Module\Financial\Entities\BankAccount> $rows
 * @var array<int, string> $covered  bank account id → «eingelesen bis» (d.m.Y)
 * @var array<string, string> $ledger  account number → name
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::ACCOUNT_BASE;
$listUrl    = $actionBase . '/list';
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Bankkonten</h2>
            <span class="be-list__section-badge" title="Bankkonten"><?= count($rows) ?></span>
        </div>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>" data-entity-list="bankAccount">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
                <?php foreach ($rows as $row): ?>
                <?= $this->partial('Backend/BankAccountController/_row', ['row' => $row, 'covered' => $covered[(int) $row->getId()] ?? null, 'ledgerName' => $ledger[$row->getLedgerAccount()] ?? '', 'actionBase' => $actionBase], 'Z77\Module\Financial') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($rows === []): ?>
        <p class="be-list__empty">Noch kein Bankkonto.</p>
        <?php endif; ?>
    </div>
</div>
