<?php
/**
 * Finanzen › MWST-Abrechnungen — the returns of the fiscal year chosen at the top of the rail: the
 * settlement periods that END in it, oldest first, one row per period with its valid return (owner
 * decision 9 of 2026-11-25). NOT a standard list on purpose (listing.md): at most twelve rows per
 * year, nothing to search or page — a fixed v2 table.
 *
 * A row, the icons at the START (ROW-ICONS-LEAD-001): the state icon (opens the return — «offen»
 * an eye, «gebucht» a lock), the ⋮ (a context menu «Aufheben …» — only on the NEWEST posted row
 * and only for who may lift; the column exists only when such a row does), the PDF (a posted row:
 * its ARCHIVED voucher; an open one: the computed PDF); then Periode, Zeitraum, Methode, Zustand,
 * Zahllast, «Gebucht von / am». A period with a lifted revision carries the small hint «Fassung n
 * aufgehoben» (its read-only page). A period the service refuses (reverse charge, an unmapped code,
 * no form edition) stays a row: no PDF, no amount, its reason on the line under it with the link to
 * the place that clears it.
 *
 * Under the table: the last three rows of the protocol (who posted or lifted what, when, why) and
 * the link to the whole protocol (Finanzen › Änderungsprotokoll, tab «Abschlüsse und MWST»).
 *
 * When the mandator's VAT settings allow no return, the page is that one refusal with its link.
 * Styling: the shared list v2 classes only (`.be-list__table--state`, `--drop`), badges.
 *
 * @var ?\Z77\Module\Financial\VatReturn\VatReturnRefusal $refusal
 * @var array{url: string, label: string}|null $fix
 * @var ?\Z77\Module\Financial\Entities\FiscalYear $year
 * @var list<array{summary: \Z77\Module\Financial\VatReturn\VatReturnPeriodSummary, label: string, state: string, url: string, pdfUrl: ?string, pdfLabel: string, menuUrl: ?string, liftedUrl: ?string, fix: ?array}> $rows
 * @var bool $hasMenu
 * @var list<\Z77\Module\Financial\Entities\VatReturnLog> $log  newest first
 * @var string $logUrl
 * @var string $rhythm
 * @var list<string> $notices
 * @var callable $fmt
 */
use Z77\Module\Financial\Ui\VatReturnLayout;

$notices = $notices ?? [];
$hasMenu = $hasMenu ?? false;
$log     = $log ?? [];
$lead    = $hasMenu ? '2rem 2rem 2rem' : '2rem 2rem';
$cols    = $lead . ' minmax(7rem, 1fr) 11.5rem minmax(8rem, 1.2fr) 6rem 7.5rem minmax(9rem, 1fr)';
$colsSm  = $lead . ' minmax(7rem, 1fr) 6rem 7.5rem';
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
                    <?php if ($hasMenu): ?><span class="be-list__col" aria-label="Aktionen"></span><?php endif; ?>
                    <span class="be-list__col" aria-label="PDF"></span>
                    <span class="be-list__col">Periode</span>
                    <span class="be-list__col" data-priority="3">Zeitraum</span>
                    <span class="be-list__col" data-priority="3">Methode</span>
                    <span class="be-list__col">Zustand</span>
                    <span class="be-list__col be-list__col--num">Zahllast</span>
                    <span class="be-list__col" data-priority="3">Gebucht von / am</span>
                </div>
                <?php foreach ($rows as $row): ?>
                <?php $s = $row['summary']; $refused = $s->refusal !== null; $posted = $s->posted; $state = $row['state']; ?>
                <div class="be-list__item" data-vat-period="<?= e($s->period->from->format('Y-m-d')) ?>" data-vat-state="<?= e($state) ?>"<?= $refused ? ' data-vat-refused="' . e($s->refusal->reason) . '"' : '' ?>>
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state<?= $refused ? ' be-list__state--closed' : ($state === 'posted' ? ' be-list__state--vat-settled' : ' be-list__state--generated') ?>" href="<?= e($row['url']) ?>" aria-label="<?= e($row['label']) ?> öffnen — <?= e(VatReturnLayout::STATE_LABELS[$state]) ?>" title="<?= e(ucfirst(VatReturnLayout::STATE_LABELS[$state])) ?> — Abrechnung öffnen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= e(VatReturnLayout::STATE_ICONS[$state]) ?>"/></svg></a>
                        <?php if ($hasMenu): ?>
                        <?php if ($row['menuUrl'] !== null): ?>
                        <span class="be-list__cell"><button type="button" class="be-tree__menu" title="Aktionen" aria-label="Aktionen — <?= e($row['label']) ?>" aria-haspopup="menu" aria-expanded="false" data-menu-open="<?= e($row['menuUrl']) ?>" data-vat-return-menu>⋮</button></span>
                        <?php else: ?>
                        <span class="be-list__cell"></span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($row['pdfUrl'] !== null): ?>
                        <span class="be-list__cell"><a class="be-icon-btn" href="<?= e($row['pdfUrl']) ?>" target="_blank" rel="noopener" data-no-pending title="<?= e($row['pdfLabel'] . ' ' . $row['label']) ?>" aria-label="<?= e($row['pdfLabel'] . ' ' . $row['label']) ?>" data-vat-pdf="<?= $posted !== null ? 'voucher' : 'computed' ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg></a></span>
                        <?php else: ?>
                        <span class="be-list__cell"></span>
                        <?php endif; ?>
                        <span class="be-list__cell"><a href="<?= e($row['url']) ?>"><strong><?= e($row['label']) ?></strong></a><?= $s->elapsed ? '' : ' <small class="be-list__cell--muted">· läuft</small>' ?><?php if ($row['liftedUrl'] !== null): ?> <small><a class="be-list__cell--muted" href="<?= e($row['liftedUrl']) ?>" data-vat-lifted-hint>Fassung <?= (int) $s->lifted->getRevision() ?> aufgehoben</a></small><?php endif; ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= e($s->period->from->format('d.m.')) ?>–<?= e($s->period->to->format('d.m.Y')) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3" title="<?= e($s->methodLabel) ?>"><?= e($s->methodLabel) ?></span>
                        <span class="be-list__cell"><span class="badge <?= $state === 'posted' ? 'badge--success' : 'badge--primary' ?>"><?= e(VatReturnLayout::STATE_LABELS[$state]) ?></span></span>
                        <span class="be-list__cell be-list__cell--num" data-vat-payable><?= $s->payable === null ? '—' : e($fmt($s->payable)) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3" data-vat-posted-by><?= $posted === null ? '' : e($posted->getPostedBy() . ' · ' . $posted->getPostedAt()->format('d.m.Y H:i')) ?></span>
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

    <div class="be-list__section" data-vat-return-log>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Protokoll</h2>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($logUrl) ?>" data-vat-return-log-link>Ganzes Protokoll</a>
        </div>
        <?php if ($log === []): ?>
        <p class="be-list__empty">Noch keine MWST-Abrechnung gebucht.</p>
        <?php else: ?>
        <div class="be-list__table" style="--be-list-cols: 9rem 9rem minmax(9rem, 1fr) 8rem minmax(0, 2fr)">
            <?php foreach ($log as $entry): ?>
            <div class="be-list__item"><div class="be-list__row" data-vat-log="<?= e($entry->getAction()->value) ?>">
                <span class="be-list__cell be-list__cell--mono"><?= e($entry->getActedAt()->format('d.m.Y H:i')) ?></span>
                <span class="be-list__cell"><?= e(VatReturnLayout::LOG_ACTIONS[$entry->getAction()->value] ?? $entry->getAction()->value) ?></span>
                <span class="be-list__cell"><?= e($entry->getPeriodFrom()->format('d.m.') . '–' . $entry->getPeriodTo()->format('d.m.Y')) ?><?= $entry->getRevision() > 1 ? ' · Fassung ' . (int) $entry->getRevision() : '' ?></span>
                <span class="be-list__cell be-list__cell--muted"><?= e($entry->getActor()) ?></span>
                <span class="be-list__cell be-list__cell--wrap be-list__cell--muted"><?= e((string) $entry->getReason()) ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
