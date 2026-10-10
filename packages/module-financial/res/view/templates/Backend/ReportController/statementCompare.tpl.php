<?php
/**
 * One block of a statement over one or more periods (owner 2026-10-10: the last three years
 * side by side, the «Vorjahre» checkbox switches to the one period). Renders one block of a
 * {@see \Z77\Module\Financial\Reports\StatementComparison}: Konto | Bezeichnung | one amount
 * column per period. Hierarchy by weight, not indent (FIN-UI-007); a name that does not fit
 * ends in «…» (the list cell's ellipsis). An empty cell = the account had no line that year.
 * A block without a title and rows is a closing total (Total Passiven, the result).
 *
 * @var array<string, mixed> $block   one entry of StatementComparison::$blocks
 * @var list<string> $labels          the period labels, newest first
 * @var callable $link
 * @var callable $fmt
 */
$n     = count($labels);
$cols  = '--be-list-cols: 6rem minmax(12rem, 1fr) repeat(' . $n . ', 9rem)';
$amt   = static fn($m): string => $m === null ? '' : $fmt($m);
$head  = $block['title'] !== '';
?>
<div class="be-list__table" style="<?= e($cols) ?>">
    <?php if ($head): ?>
    <div class="be-list__head">
        <span class="be-list__col">Konto</span>
        <span class="be-list__col"><?= e($block['title']) ?></span>
        <?php foreach ($labels as $label): ?>
        <span class="be-list__col be-list__col--num"><?= $n === 1 ? 'Betrag' : e($label) ?></span>
        <?php endforeach; ?>
    </div>
    <?php if ($block['rows'] === [] && $block['extra'] === []): ?>
    <div class="be-list__item"><div class="be-list__row">
        <span class="be-list__cell"></span>
        <span class="be-list__cell be-list__cell--muted">keine Buchung</span>
        <?php for ($i = 0; $i < $n; $i++): ?><span class="be-list__cell"></span><?php endfor; ?>
    </div></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php foreach ($block['rows'] as $row): ?>
    <div class="be-list__item" data-account="<?= e($row['number']) ?>">
        <div class="be-list__row<?= $row['isGroup'] ? ' be-list__row--group be-list__row--l' . min((int) $row['depth'], 2) : '' ?>">
            <?php if ($row['isGroup']): ?>
            <span class="be-list__cell be-list__cell--mono"><?= e($row['number']) ?></span>
            <?php else: ?>
            <span class="be-list__cell be-list__cell--mono"><a href="<?= e($link('account-statement', ['account' => $row['number']])) ?>"><?= e($row['number']) ?></a></span>
            <?php endif; ?>
            <span class="be-list__cell" title="<?= e($row['name']) ?>"><?= e($row['name']) ?></span>
            <?php foreach ($row['amounts'] as $amount): ?>
            <span class="be-list__cell be-list__cell--num"><?= e($amt($amount)) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php foreach ($block['extra'] as $row): ?>
    <div class="be-list__item">
        <div class="be-list__row be-list__row--group be-list__row--l1">
            <span class="be-list__cell"></span>
            <span class="be-list__cell"><?= e($row['label']) ?></span>
            <?php foreach ($row['amounts'] as $amount): ?>
            <span class="be-list__cell be-list__cell--num"><?= e($amt($amount)) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="be-list__item">
        <div class="be-list__row be-list__row--total">
            <span class="be-list__cell"></span>
            <span class="be-list__cell"><?= e($block['totalLabel']) ?></span>
            <?php foreach ($block['totals'] as $amount): ?>
            <span class="be-list__cell be-list__cell--num"><?= e($amt($amount)) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
