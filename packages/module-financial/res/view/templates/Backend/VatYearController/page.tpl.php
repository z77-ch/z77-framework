<?php
/**
 * «MWST-Jahresübersicht {Jahr}» — the page every tab renders through (the layout's pin,
 * {@see \Z77\Module\Financial\Ui\VatYearLayout::config()}): a refusal instead of the overview when
 * there is none (the settings gate, no fiscal year, a tax year without a VAT period); else the card
 * «Offene Punkte» (only with a finding — information, nothing blocks: a remainder «Rest» red, the
 * rest «Warnung»), the head, then the active tab's partial. A report: no action cell, no form.
 * The sub-partials (head, the tab) get the page's whole context (`$page`, read before any local).
 *
 * @var string $tab
 * @var ?\Z77\Module\Financial\VatReturn\VatYearOverview $overview
 * @var ?\Z77\Module\Financial\VatReturn\VatReturnRefusal $refusal
 * @var ?array{url: string, label: string} $fix
 * @var list<string> $notices
 * @var list<array{finding: \Z77\Module\Financial\VatReturn\VatReturnFinding, red: bool, link: ?array{url: string, label: string}}> $findingRows  with $overview
 */
$page     = array_filter(get_defined_vars(), static fn(string $k): bool => !str_starts_with($k, 'z77Tpl') && $k !== 'this', ARRAY_FILTER_USE_KEY);
$ns       = 'Z77\\Module\\Financial';
$notices  = $notices ?? [];
$overview = $overview ?? null;
?>
<div class="be-list" data-vat-year-view="<?= e($tab) ?>">
    <?php foreach ($notices as $notice): ?>
    <p class="be-list__section-hint" role="status"><?= e($notice) ?></p>
    <?php endforeach; ?>
    <?php if ($overview === null): ?>
    <div class="be-list__section" data-vat-year-refused="<?= e((string) $refusal?->reason) ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">MWST-Jahresübersicht</h2>
        </div>
        <p class="be-list__empty"><?= e((string) $refusal?->message) ?><?php if (($fix ?? null) !== null): ?> <a href="<?= e($fix['url']) ?>"><?= e($fix['label']) ?></a><?php endif; ?></p>
    </div>
    <?php else: ?>
    <?php if ($findingRows !== []): ?>
    <div class="be-list__section" data-vat-year-findings>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Offene Punkte</h2>
            <span class="be-list__section-badge"><?= count($findingRows) ?></span>
        </div>
        <div class="be-list__frame">
            <div class="be-list__table" style="--be-list-cols: 5.5rem minmax(0, 1fr) auto">
                <?php foreach ($findingRows as $row): ?>
                <?php $f = $row['finding']; ?>
                <div class="be-list__item">
                    <div class="be-list__row" data-vat-year-finding="<?= e($f->code) ?>">
                        <span class="be-list__cell"><span class="badge <?= $row['red'] ? 'badge--danger' : 'badge--warning' ?>"><?= $row['red'] ? 'Rest' : 'Warnung' ?></span></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($f->message) ?></span>
                        <span class="be-list__cell"><?php if ($row['link'] !== null): ?><a href="<?= e($row['link']['url']) ?>"><?= e($row['link']['label']) ?></a><?php endif; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="be-list__section" data-vat-year-head>
        <?= $this->partial('Backend/VatYearController/head', $page, $ns) ?>
    </div>

    <?= $this->partial('Backend/VatYearController/' . $tab, $page, $ns) ?>
    <?php endif; ?>
</div>
