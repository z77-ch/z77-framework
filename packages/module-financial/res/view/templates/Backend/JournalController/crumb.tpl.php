<?php
/**
 * Journal — the crumb line (hc3): Finanzen › Journal › fiscal year › month of
 * the date being captured (owner 2026-09-28, FIN-JOURNAL-CAPTURE-001). Position
 * only (ADR-033) — the year is not a switch: it follows the date.
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm|\Z77\Module\Financial\Ui\ManualEntryForm $form
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$date   = \Z77\Module\Financial\Ui\ManualEntryForm::parseDate($form->date());
?>
<nav class="be-crumb" aria-label="Pfad">
    <span>Finanzen</span>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <a href="<?= e($actionBase . '/list') ?>">Journal</a>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <?php if ($date === null): ?>
    <span class="be-crumb__here"><?= e($year->getCode()) ?></span>
    <?php else: ?>
    <span><?= e($year->getCode()) ?></span>
    <span class="be-crumb__sep" aria-hidden="true">›</span>
    <span class="be-crumb__here"><?= e($months[(int) $date->format('n') - 1]) ?></span>
    <?php endif; ?>
</nav>
