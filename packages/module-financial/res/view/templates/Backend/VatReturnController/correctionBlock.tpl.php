<?php
/**
 * The block «Korrekturen früherer Perioden — separat im ePortal melden» of one VAT return (board
 * S3-2, owner S3-1, S3-6, B-2, C-C) — under the form of tab «Formular»: per corrected period its
 * head (the period, its span, when it was posted, the correction entries — each opens its journal
 * entry as a window) and a table per field with a correction plus 399 and 500. Two groups, Umsatz
 * and Steuer, each «ursprünglich» (as originally posted), «frühere Korrekturen» (only when an earlier
 * return corrected the same period — C-C), «Korrektur» (signed) and «neu»; under every tax amount
 * the exact value small (B-2 — «neu» is rounded ONCE from the exact sum, so it may differ by a
 * Rappen from the shown columns; the help says why).
 *
 * An OPEN period: the block computed from the journal (`VatReturnService::correctionBlocks()`); a
 * POSTED or lifted one: the stored rows (`declaredCorrectionBlocks()`), never recomputed. No block —
 * nothing rendered. Not a standard list on purpose — a fixed sheet like the form.
 *
 * @var list<\Z77\Module\Financial\VatReturn\VatReturnCorrectionBlock> $blocks
 * @var string $journalBase  the journal's URL root (the entry window)
 * @var callable $fmt
 */
use Z77\Module\Financial\Ui\VatReturnLayout;

$blocks = $blocks ?? [];
if ($blocks === []) {
    return;
}
$exact = static fn(\Z77\Module\Financial\VatReturn\ExactAmount $a): string => '<br><small class="be-list__cell--muted" data-vat-exact>' . e(VatReturnLayout::exact($a)) . '</small>';
?>
<div class="be-list__section" data-vat-corrections>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title"><?= e(VatReturnLayout::CORRECTIONS_TITLE) ?></h2>
        <span class="be-list__section-badge"><?= count($blocks) === 1 ? '1 Periode' : count($blocks) . ' Perioden' ?></span>
    </div>
    <?php foreach ($blocks as $block): ?>
    <?php
    $earlier = $block->hasEarlier();
    $n       = $earlier ? 4 : 3;
    $cols    = '3rem minmax(9rem, 1fr)' . str_repeat(' 7rem', 2 * $n);
    $colsSm  = '3rem minmax(0, 1fr)' . str_repeat(' 6.5rem', 4);
    $colsXs  = '2.6rem minmax(0, 1fr) 5.8rem 5.8rem';
    $posted  = $block->correctedReturn?->getPostedAt();
    $refs    = array_map(static fn(array $e): string => '<a href="' . e($journalBase . '/detail?' . http_build_query(['ref' => $e['ref']])) . '" data-window-open="' . e($journalBase . '/detail?' . http_build_query(['ref' => $e['ref']])) . '" data-vat-correction-entry="' . e($e['ref']) . '">' . e($e['ref']) . '</a> vom ' . e($e['date']->format('d.m.Y')), $block->entries);
    // One cell per column of a group: «ursprünglich» and «frühere» drop first on a narrow pane, then the turnover.
    $columns = array_filter(['original' => 'ursprünglich', 'earlier' => $earlier ? 'frühere Korrekturen' : null, 'correction' => 'Korrektur', 'new' => 'neu']);
    $priority = static fn(string $group, string $key): string => in_array($key, ['original', 'earlier'], true) ? ' data-priority="3"' : ($group === 'turnover' ? ' data-priority="2"' : '');
    ?>
    <div data-vat-correction-period="<?= e($block->period->from->format('Y-m-d')) ?>"<?= $earlier ? ' data-vat-correction-earlier' : '' ?>>
        <p class="be-list__section-hint">
            <strong><?= e($block->period->label()) ?></strong>
            · <?= e($block->period->from->format('d.m.') . '–' . $block->period->to->format('d.m.Y')) ?>
            <?= $posted === null ? '' : '· gebucht ' . e($posted->format('d.m.Y')) ?>
            · <?= count($refs) === 1 ? 'Korrekturbuchung' : 'Korrekturbuchungen' ?> <?= raw(implode(', ', $refs)) ?>
        </p>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="--be-list-cols: <?= $cols ?>; --be-list-cols-sm: <?= $colsSm ?>; --be-list-cols-xs: <?= $colsXs ?>">
                <div class="be-list__head">
                    <span class="be-list__col"></span>
                    <span class="be-list__col"></span>
                    <?php foreach (['turnover' => 'Umsatz', 'tax' => 'Steuer'] as $group => $title): ?>
                    <?php $first = true; foreach ($columns as $key => $label): ?>
                    <span class="be-list__col be-list__col--num"<?= raw($priority($group, $key)) ?>><?= $first ? e($title) : '' ?></span>
                    <?php $first = false; endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <div class="be-list__head">
                    <span class="be-list__col">Ziffer</span>
                    <span class="be-list__col">Bezeichnung</span>
                    <?php foreach (['turnover', 'tax'] as $group): ?>
                    <?php foreach ($columns as $key => $label): ?>
                    <span class="be-list__col be-list__col--num"<?= raw($priority($group, $key)) ?>><?= e($label) ?></span>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <?php foreach (VatReturnLayout::correctionRows($block) as $row): ?>
                <div class="be-list__item">
                    <div class="be-list__row" data-vat-correction-field="<?= e($row['field']) ?>">
                        <span class="be-list__cell be-list__cell--mono"><?= e($row['field']) ?></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($row['label']) ?></span>
                        <?php foreach ($columns as $key => $label): ?>
                        <span class="be-list__cell be-list__cell--num"<?= raw($priority('turnover', $key)) ?>><?= $row['turnover'] === null ? '' : e($key === 'correction' || $key === 'earlier' ? VatReturnLayout::signed($row['turnover'][$key]) : $fmt($row['turnover'][$key])) ?></span>
                        <?php endforeach; ?>
                        <?php foreach ($columns as $key => $label): ?>
                        <?php $value = $row['tax'][$key] ?? null; ?>
                        <span class="be-list__cell be-list__cell--num"<?= raw($priority('tax', $key)) ?><?= $value === null ? '' : ' data-vat-tax="' . e($key) . '"' ?>><?= $value === null ? '' : e($key === 'correction' || $key === 'earlier' ? VatReturnLayout::signed($value->rounded()) : $fmt($value->rounded())) . raw($exact($value)) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
