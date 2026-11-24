<?php
/**
 * Finanzen › MWST-Abrechnungen — the returns of the fiscal year chosen at the top of the rail: the
 * settlement periods that END in it (screen decision 3), oldest first. NOT a standard list on
 * purpose (listing.md): at most twelve rows per year, nothing to search or page — a fixed v2 table.
 *
 * A row: the state icon first (opens the return, «Formular»), the PDF icon, Periode, Zeitraum,
 * Methode, Zustand, Zahllast — row icons at the START (ROW-ICONS-LEAD-001). A period the service
 * refuses (reverse charge, an unmapped code, no form edition) stays a row: no PDF, no amount, its
 * reason on the line under it with the link to the place that clears it. The columns «Eingereicht»
 * and «Gebucht von / am» and the protocol under the list come with stage S2 (nothing is posted yet).
 *
 * When the mandator's VAT settings allow no return, the page is that one refusal with its link.
 * Styling: the shared list v2 classes only (`.be-list__table--state`, `--drop`), badges.
 *
 * @var ?\Z77\Module\Financial\VatReturn\VatReturnRefusal $refusal
 * @var array{url: string, label: string}|null $fix
 * @var ?\Z77\Module\Financial\Entities\FiscalYear $year
 * @var list<array{summary: \Z77\Module\Financial\VatReturn\VatReturnPeriodSummary, label: string, url: string, pdfUrl: ?string, fix: ?array}> $rows
 * @var string $rhythm
 * @var list<string> $notices
 * @var callable $fmt
 */
$notices = $notices ?? [];
$cols    = '2rem minmax(7rem, 1fr) 11.5rem minmax(8rem, 1.2fr) 6rem 7.5rem';
$colsSm  = '2rem minmax(7rem, 1fr) 6rem 7.5rem';
?>
<div class="be-list" data-vat-return-list>
    <?php if ($refusal !== null): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">MWST-Abrechnungen</h2>
        </div>
        <div class="be-modal__alert be-modal__alert--error" role="status" data-vat-return-refusal="<?= e($refusal->reason) ?>">
            <?= e($refusal->message) ?>
            <?php if ($fix !== null): ?> <a href="<?= e($fix['url']) ?>"><?= e($fix['label']) ?></a><?php endif; ?>
        </div>
    </div>
    <?php elseif ($year === null): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">MWST-Abrechnungen</h2>
        </div>
        <p class="be-list__empty">Noch kein Geschäftsjahr eröffnet — die Liste zeigt die Abrechnungen eines Geschäftsjahres.</p>
    </div>
    <?php else: ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                MWST-Abrechnungen
                <small>· Geschäftsjahr <?= e($year->getCode()) ?><?= $rhythm !== '' ? ' · Rhythmus ' . e($rhythm) : '' ?></small>
            </h2>
            <span class="be-list__section-badge" title="Abrechnungsperioden, die im Geschäftsjahr enden"><?= count($rows) ?></span>
        </div>
        <?php foreach ($notices as $notice): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($notice) ?></div>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
        <p class="be-list__empty">Keine Abrechnungsperiode endet im Geschäftsjahr <?= e($year->getCode()) ?>.</p>
        <?php else: ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--state be-list__table--drop" style="--be-list-cols: <?= $cols ?>; --be-list-cols-sm: <?= $colsSm ?>; --be-list-cols-xs: <?= $colsSm ?>">
                <div class="be-list__head">
                    <span class="be-list__col" aria-label="Zustand"></span>
                    <span class="be-list__col" aria-label="PDF"></span>
                    <span class="be-list__col">Periode</span>
                    <span class="be-list__col" data-priority="3">Zeitraum</span>
                    <span class="be-list__col" data-priority="3">Methode</span>
                    <span class="be-list__col">Zustand</span>
                    <span class="be-list__col be-list__col--num">Zahllast</span>
                </div>
                <?php foreach ($rows as $row): ?>
                <?php $s = $row['summary']; $refused = $s->refusal !== null; ?>
                <div class="be-list__item" data-vat-period="<?= e($s->period->from->format('Y-m-d')) ?>"<?= $refused ? ' data-vat-refused="' . e($s->refusal->reason) . '"' : '' ?>>
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state<?= $refused ? ' be-list__state--closed' : ' be-list__state--generated' ?>" href="<?= e($row['url']) ?>" aria-label="<?= e($row['label']) ?> öffnen — <?= e($s->stateLabel) ?>" title="<?= e(ucfirst($s->stateLabel)) ?> — Abrechnung öffnen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-eye"/></svg></a>
                        <?php if ($row['pdfUrl'] !== null): ?>
                        <span class="be-list__cell"><a class="be-icon-btn" href="<?= e($row['pdfUrl']) ?>" target="_blank" rel="noopener" title="PDF <?= e($row['label']) ?>" aria-label="PDF <?= e($row['label']) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg></a></span>
                        <?php else: ?>
                        <span class="be-list__cell"></span>
                        <?php endif; ?>
                        <span class="be-list__cell"><a href="<?= e($row['url']) ?>"><strong><?= e($row['label']) ?></strong></a><?= $s->elapsed ? '' : ' <small class="be-list__cell--muted">· läuft</small>' ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= e($s->period->from->format('d.m.')) ?>–<?= e($s->period->to->format('d.m.Y')) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3" title="<?= e($s->methodLabel) ?>"><?= e($s->methodLabel) ?></span>
                        <span class="be-list__cell"><span class="badge badge--primary"><?= e($s->stateLabel) ?></span></span>
                        <span class="be-list__cell be-list__cell--num" data-vat-payable><?= $s->payable === null ? '—' : e($fmt($s->payable)) ?></span>
                    </div>
                    <?php if ($refused): ?>
                    <div class="be-list__row">
                        <span class="be-list__cell be-list__cell--rest be-list__cell--wrap be-list__cell--muted" data-vat-refusal><?= e($s->refusal->message) ?><?php if ($row['fix'] !== null): ?> <a href="<?= e($row['fix']['url']) ?>"><?= e($row['fix']['label']) ?></a><?php endif; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
