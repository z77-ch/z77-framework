<?php
/**
 * One block of the balance sheet or the income statement: groups and
 * accounts in chart order, indented by their depth in the parent chain,
 * each with its amount on the block's natural side (positive there). A
 * group row is bold and carries its subtotal; an account links to its
 * account statement over the report's range. Then the block's total, and
 * $extra rows the caller appends (the current result in equity). Accounts
 * the chart tree does not reach (`$section->unplaced`) stand flat at the end,
 * and a note above the block names them — never a silent gap.
 *
 * @var \Z77\Module\Financial\Reports\StatementSection $section
 * @var list<array{label: string, amount: \Z77\Shared\Money\Money}> $extra
 * @var string $totalLabel
 * @var \Z77\Shared\Money\Money $total
 * @var callable $link
 * @var callable $fmt
 */
$extra = $extra ?? [];
?>
<?php if ($section->unplaced !== []): ?>
<div class="be-modal__alert be-modal__alert--error">
    Nicht im Kontenbaum erreichbar (Gruppen-Zyklus oder verwaiste Gruppe) — unten flach angefügt, ohne Gruppe:
    <?= e(implode(', ', $section->unplaced)) ?>. Bitte den Kontenplan prüfen; die Summen sind vollständig.
</div>
<?php endif; ?>
<div class="be-list__table" style="--be-list-cols: 6rem minmax(12rem, 1fr) 9rem">
    <div class="be-list__head">
        <span class="be-list__col">Konto</span>
        <span class="be-list__col"><?= e($section->title) ?></span>
        <span class="be-list__col be-list__col--num">Betrag</span>
    </div>
    <?php if ($section->lines === [] && $extra === []): ?>
    <div class="be-list__item"><div class="be-list__row">
        <span class="be-list__cell"></span>
        <span class="be-list__cell be-list__cell--muted">keine Buchung</span>
        <span class="be-list__cell"></span>
    </div></div>
    <?php endif; ?>
    <?php foreach ($section->lines as $line): ?>
    <div class="be-list__item" data-account="<?= e($line->number) ?>">
        <div class="be-list__row">
            <?php if ($line->isGroup): ?>
            <span class="be-list__cell be-list__cell--mono"><strong><?= e($line->number) ?></strong></span>
            <span class="be-list__cell" style="padding-left: calc(<?= $line->depth ?> * 1.25rem)"><strong><?= e($line->name) ?></strong></span>
            <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($line->amount)) ?></strong></span>
            <?php else: ?>
            <span class="be-list__cell be-list__cell--mono"><a href="<?= e($link('account-statement', ['account' => $line->number])) ?>"><?= e($line->number) ?></a></span>
            <span class="be-list__cell" style="padding-left: calc(<?= $line->depth ?> * 1.25rem)"><?= e($line->name) ?></span>
            <span class="be-list__cell be-list__cell--num"><?= e($fmt($line->amount)) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php foreach ($extra as $row): ?>
    <div class="be-list__item">
        <div class="be-list__row">
            <span class="be-list__cell"></span>
            <span class="be-list__cell"><em><?= e($row['label']) ?></em></span>
            <span class="be-list__cell be-list__cell--num"><em><?= e($fmt($row['amount'])) ?></em></span>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="be-list__item">
        <div class="be-list__row">
            <span class="be-list__cell"></span>
            <span class="be-list__cell"><strong><?= e($totalLabel) ?></strong></span>
            <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($total)) ?></strong></span>
        </div>
    </div>
</div>
