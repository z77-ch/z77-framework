<?php
/**
 * Report pages — hc2 (middle band slot): the fiscal-year switcher, one link
 * per year (the `.be-lang-switch` anatomy the journal's switcher uses). A
 * link carries the YEAR only — from and to fall back to the new year's
 * bounds — plus what the page keeps (the account of the account statement).
 *
 * @var list<\Z77\Module\Financial\Entities\FiscalYear> $years  newest first
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var string $tab
 * @var callable $link
 * @var array<string,string> $keep
 */
if (count($years ?? []) < 2) { return; }
$current = $range->year->getCode();
?>
<div class="be-lang-switch" role="group" aria-label="Geschäftsjahr">
    <span class="be-lang-switch__label">Geschäftsjahr:
        <span class="be-lang-switch__current"><?= e($current) ?></span>
    </span>
    <div class="be-lang-switch__options">
        <?php foreach ($years as $candidate): ?>
        <a class="be-lang-switch__option<?= $candidate->getCode() === $current ? ' be-lang-switch__option--active' : '' ?>"
           href="<?= e($link($tab, ['year' => $candidate->getCode(), 'from' => null, 'to' => null] + ($keep ?? []))) ?>"
           <?= $candidate->getCode() === $current ? 'aria-current="true"' : '' ?>><?= e($candidate->getCode()) ?></a>
        <?php endforeach; ?>
    </div>
</div>
