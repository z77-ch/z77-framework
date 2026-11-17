<?php
/**
 * Finanzen › «Bankkonto» (ADR-060 decisions 16–18) — the ONE list «Bewegungen» of the selected
 * bank account. The upload lives in the ACTION CELL (`act.tpl.php`); files dropped anywhere on the
 * work column are taken, its progress runs in a window (UPLOAD-DIALOG-001, owner 2026-11-17). This
 * template holds the list (`_list`, replaced in place after an upload: `[data-bank-movements]`).
 *
 * Labels and answers only — the explanations are in the help (backend-screen.md §5).
 *
 * @var list<\Z77\Module\Financial\Entities\BankAccount> $accounts  the accounts that take statements
 * @var string $accountsBase
 */
$context = get_defined_vars();
unset($context['this']);
?>
<div class="be-list">
    <?php if ($accounts === []): ?>
    <div class="be-list__section">
        <p class="be-list__empty">Bei keinem Bankkonto ist «Kontoauszug einlesen» eingeschaltet. <a href="<?= e($accountsBase) ?>/list">Bankkonten</a></p>
    </div>
    <?php else: ?>
    <div data-bank-movements>
        <?= $this->partial('Backend/BankMovementController/_list', $context, 'Z77\\Module\\Financial') ?>
    </div>
    <?php endif; ?>
</div>
