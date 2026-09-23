<?php
/**
 * The journal of one fiscal year (plan §5.2), newest first: number, date,
 * text, kind, amount (Σ debit), origin. The number links to the entry
 * page. Bounded by `journalListLimit`; a second section lists the numbers
 * that are GAPS — deleted manual entries, each with who and when, from the
 * change log (ADR-042 decision 9).
 *
 * Styling: the shared backend list v2 classes only (`.be-list__frame`,
 * `.be-list__table` with `--be-list-cols`, `.be-list__head`, `.be-list__row`,
 * `.be-list__cell--num` for the amount, badges) — no CSS and no JavaScript
 * of its own.
 *
 * @var list<\Z77\Module\Financial\Entities\FiscalYear> $years
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var list<\Z77\Module\Financial\Entities\JournalEntry> $entries  newest first, lines loaded
 * @var int $total
 * @var list<\Z77\Module\Financial\Entities\EntryChange> $deletions
 * @var int $limit
 * @var array<string,string> $kindLabels
 * @var callable $fmt  Money → «1'234.50»
 * @var string|null $configNotice  the red band while no VAT account can be resolved (leftover config key, mandator unavailable) — null normally
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$shown      = count($entries);
?>
<div class="be-list">
    <?php if (!empty($configNotice)): ?>
    <div class="be-modal__alert be-modal__alert--error"><?= e($configNotice) ?></div>
    <?php endif; ?>
    <?php if ($year === null): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Journal</h2>
            <span class="be-list__section-badge">0</span>
        </div>
        <p class="be-list__empty">Noch kein Geschäftsjahr eröffnet — ohne Geschäftsjahr kann nichts gebucht werden.
           <a href="/backend/finance/fiscal-year/list">Geschäftsjahre</a></p>
    </div>
    <?php else: ?>
    <div class="be-list__section" data-fiscal-year-id="<?= e((string) $year->getId()) ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Journal <code><?= e($year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?></small>
            </h2>
            <span class="be-list__section-badge" title="Buchungen"><?= $total ?></span>
        </div>
        <?php if ($entries === []): ?>
        <p class="be-list__empty">Noch keine Buchung in diesem Geschäftsjahr.</p>
        <?php else: ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="--be-list-cols: 4rem 6rem minmax(12rem, 2fr) 6rem 8rem minmax(6rem, 1fr); --be-list-cols-sm: 4rem 6rem minmax(10rem, 2fr) 8rem; --be-list-cols-xs: 4rem minmax(8rem, 2fr) 7rem">
                <div class="be-list__head">
                    <span class="be-list__col be-list__col--num">Nr.</span>
                    <span class="be-list__col" data-priority="2">Datum</span>
                    <span class="be-list__col">Text</span>
                    <span class="be-list__col" data-priority="3">Art</span>
                    <span class="be-list__col be-list__col--num">Betrag</span>
                    <span class="be-list__col" data-priority="3">Herkunft</span>
                </div>
                <?php foreach ($entries as $entry): ?>
                <div class="be-list__item" data-entry-id="<?= e((string) $entry->getId()) ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell be-list__cell--num be-list__cell--mono"><a href="<?= e($actionBase) ?>/detail?id=<?= e((string) $entry->getId()) ?>"><?= $entry->getNumber() ?></a></span>
                        <span class="be-list__cell" data-priority="2"><?= e($entry->getDate()->format('d.m.Y')) ?></span>
                        <span class="be-list__cell"><a href="<?= e($actionBase) ?>/detail?id=<?= e((string) $entry->getId()) ?>"><?= e($entry->getText()) ?></a><?= $entry->isReversal() ? ' <span class="badge badge--warning">Storno von ' . $entry->getReversalOf()->getNumber() . '</span>' : '' ?></span>
                        <span class="be-list__cell" data-priority="3"><span class="badge <?= $entry->isManual() ? 'badge--info' : 'badge--muted' ?>"><?= e($kindLabels[$entry->getKind()] ?? $entry->getKind()) ?></span></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($entry->total())) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3"><?= $entry->getSourceType() === null ? '–' : e($entry->getSourceType() . ' ' . $entry->getSourceRef()) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($total > $shown): ?>
        <p class="be-form__hint"><?= $shown ?> von <?= $total ?> Buchungen angezeigt (die neuesten).</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($deletions !== []): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Gelöschte Buchungen <small class="be-list__cell--muted">· Lücken in der Nummerierung, aus dem Änderungsprotokoll</small></h2>
            <span class="be-list__section-badge"><?= count($deletions) ?></span>
        </div>
        <div class="be-list__table" style="--be-list-cols: 4rem minmax(12rem, 2fr) 10rem 12rem">
            <div class="be-list__head">
                <span class="be-list__col be-list__col--num">Nr.</span>
                <span class="be-list__col">Text (vor dem Löschen)</span>
                <span class="be-list__col">Gelöscht von</span>
                <span class="be-list__col">Am</span>
            </div>
            <?php foreach ($deletions as $change): ?>
            <div class="be-list__item">
                <div class="be-list__row be-list__row--inactive">
                    <span class="be-list__cell be-list__cell--num be-list__cell--mono"><?= $change->getEntryNumber() ?></span>
                    <span class="be-list__cell"><?= e((string) ($change->before()['text'] ?? '')) ?></span>
                    <span class="be-list__cell be-list__cell--actions"><?= e($change->getChangedBy()) ?></span>
                    <span class="be-list__cell be-list__cell--actions"><?= e($change->getChangedAt()->format('d.m.Y H:i')) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
