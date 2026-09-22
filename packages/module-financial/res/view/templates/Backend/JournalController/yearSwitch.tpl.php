<?php
/**
 * Journal list — hc2 (middle slot): the fiscal-year switcher, one link per
 * year (the `.be-lang-switch` anatomy of the metadata list — a row of links,
 * no JavaScript).
 * Part of the fragment: the trait's `listAction()` adds it to the shell slot
 * with `addPartials()` — the fragment owns its header slots, so they come
 * along wherever it is mounted (ADR-018, Rule 8; financial.md).
 *
 * @var list<\Z77\Module\Financial\Entities\FiscalYear> $years  newest first
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
if (count($years ?? []) < 2) { return; }
?>
<div class="be-lang-switch" role="group" aria-label="Geschäftsjahr">
    <span class="be-lang-switch__label">Geschäftsjahr:
        <span class="be-lang-switch__current"><?= e($year?->getCode() ?? '–') ?></span>
    </span>
    <div class="be-lang-switch__options">
        <?php foreach ($years as $candidate): ?>
        <a class="be-lang-switch__option<?= $candidate->getCode() === $year?->getCode() ? ' be-lang-switch__option--active' : '' ?>"
           href="<?= e($actionBase . '/list?year=' . rawurlencode($candidate->getCode())) ?>"
           <?= $candidate->getCode() === $year?->getCode() ? 'aria-current="true"' : '' ?>><?= e($candidate->getCode()) ?></a>
        <?php endforeach; ?>
    </div>
</div>
