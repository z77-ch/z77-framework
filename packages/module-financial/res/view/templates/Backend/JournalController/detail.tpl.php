<?php
/**
 * One journal entry (plan §5.2): the header, the lines with their tax data,
 * the reversal link in both directions, and — for a manual entry — the
 * change log with before / after (ADR-042 decision 7). A manual entry in a
 * period that is not closed offers «Bearbeiten» (a page) and «Löschen …»
 * (a confirmation modal); a generated entry offers nothing — the module
 * that posted it reverses it.
 *
 * Styling: the shared backend list classes only — no CSS and no JavaScript
 * of its own.
 *
 * @var \Z77\Module\Financial\Entities\JournalEntry $entry  lines loaded
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $reversedBy
 * @var list<\Z77\Module\Financial\Entities\EntryChange> $changes  oldest first
 * @var bool $editable
 * @var string $notEditableWhy  the refusal shown when not editable (shared with the edit page and the delete modal)
 * @var string $periodState  German label
 * @var array<string,string> $kindLabels
 * @var array<string,string> $changeLabels
 * @var callable $fmt
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$year       = $entry->getFiscalYear();
$label      = $year->getCode() . '/' . $entry->getNumber();
$lineCols   = '--be-list-cols: 4rem minmax(10rem, 2fr) minmax(8rem, 1fr) 7rem 7rem minmax(8rem, 1fr)';
/** A snapshot's lines as a small read-only table (the change log's before / after). */
$snapshotLines = function (array $snapshot) use ($fmt, $lineCols): string {
    $html = '<div class="be-list__table" style="' . $lineCols . '">';
    foreach ($snapshot['lines'] ?? [] as $line) {
        $html .= '<div class="be-list__item"><div class="be-list__row">'
            . '<span class="be-list__cell be-list__cell--mono">' . e((string) ($line['account'] ?? '')) . '</span>'
            . '<span class="be-list__cell">' . e((string) ($line['account_name'] ?? '')) . '</span>'
            . '<span class="be-list__cell be-list__cell--muted">' . e((string) ($line['text'] ?? '')) . '</span>'
            . '<span class="be-list__cell be-list__cell--num">' . (($line['debit'] ?? '0.00') !== '0.00' ? e((string) $line['debit']) : '') . '</span>'
            . '<span class="be-list__cell be-list__cell--num">' . (($line['credit'] ?? '0.00') !== '0.00' ? e((string) $line['credit']) : '') . '</span>'
            . '<span class="be-list__cell be-list__cell--muted">' . (($line['tax_code'] ?? null) !== null ? e($line['tax_code'] . ' · ' . $line['tax_base'] . ' / ' . $line['tax_amount']) : '') . '</span>'
            . '</div></div>';
    }
    return $html . '</div>';
};
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Buchung <code><?= e($label) ?></code>
                <small class="be-list__cell--muted">· <?= e($entry->getDate()->format('d.m.Y')) ?> · <?= e($fmt($entry->total())) ?></small>
            </h2>
            <span class="badge <?= $entry->isManual() ? 'badge--info' : 'badge--muted' ?>"><?= e($kindLabels[$entry->getKind()] ?? $entry->getKind()) ?></span>
        </div>

        <div class="be-list__table" style="--be-list-cols: 10rem minmax(12rem, 1fr)">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Text</span><span class="be-list__cell be-list__cell--wrap"><?= e($entry->getText()) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Datum · Periode</span><span class="be-list__cell"><?= e($entry->getDate()->format('d.m.Y')) ?> · <?= e($periodState) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Herkunft</span><span class="be-list__cell"><?= $entry->getSourceType() === null ? 'manuell erfasst' : e($entry->getSourceType() . ' ' . $entry->getSourceRef()) ?></span></div></div>
            <?php if ($entry->getIdempotencyKey() !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Idempotenz-Schlüssel</span><span class="be-list__cell be-list__cell--mono"><?= e($entry->getIdempotencyKey()) ?></span></div></div>
            <?php endif; ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Erfasst</span><span class="be-list__cell"><?= e($entry->getCreatedAt()->format('d.m.Y H:i')) ?> von <?= e($entry->getCreatedBy()) ?></span></div></div>
            <?php if ($entry->getChangedAt() !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Zuletzt geändert</span><span class="be-list__cell"><?= e($entry->getChangedAt()->format('d.m.Y H:i')) ?> von <?= e((string) $entry->getChangedBy()) ?></span></div></div>
            <?php endif; ?>
            <?php if ($entry->isReversal()): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Storno von</span><span class="be-list__cell"><a href="<?= e($actionBase) ?>/detail?id=<?= e((string) $entry->getReversalOf()->getId()) ?>">Buchung <?= e($entry->getReversalOf()->getFiscalYear()->getCode() . '/' . $entry->getReversalOf()->getNumber()) ?></a> — <?= e($entry->getReversalOf()->getText()) ?></span></div></div>
            <?php endif; ?>
            <?php if ($reversedBy !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Storniert durch</span><span class="be-list__cell"><a href="<?= e($actionBase) ?>/detail?id=<?= e((string) $reversedBy->getId()) ?>">Buchung <?= e($reversedBy->getFiscalYear()->getCode() . '/' . $reversedBy->getNumber()) ?></a> vom <?= e($reversedBy->getDate()->format('d.m.Y')) ?> — <?= e($reversedBy->getText()) ?></span></div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zeilen</h2>
            <span class="be-list__section-badge"><?= count($entry->getLines()) ?></span>
        </div>
        <div class="be-list__frame">
            <div class="be-list__table" style="<?= $lineCols ?>">
                <div class="be-list__head">
                    <span class="be-list__col">Konto</span>
                    <span class="be-list__col"></span>
                    <span class="be-list__col">Text</span>
                    <span class="be-list__col be-list__col--num">Soll</span>
                    <span class="be-list__col be-list__col--num">Haben</span>
                    <span class="be-list__col">MWST (Code · Basis / Steuer)</span>
                </div>
                <?php foreach ($entry->getLines() as $line): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell be-list__cell--mono"><?= e($line->getAccount()->getNumber()) ?></span>
                        <span class="be-list__cell"><?= e($line->getAccount()->getName()) ?></span>
                        <span class="be-list__cell be-list__cell--muted"><?= e((string) $line->getText()) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->getDebit()->isPositive() ? e($fmt($line->getDebit())) : '' ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->getCredit()->isPositive() ? e($fmt($line->getCredit())) : '' ?></span>
                        <span class="be-list__cell be-list__cell--muted"><?= $line->hasTax() ? e($line->getTaxCode() . ' ' . \Z77\Module\Financial\Ui\ManualEntryForm::percent((int) $line->getTaxRate()) . ' · ' . $fmt($line->getTaxBase()) . ' / ' . $fmt($line->getTaxAmount())) : '' ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><strong>Total</strong></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($entry->total())) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($entry->total())) ?></strong></span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
            </div>
        </div>
        <p class="be-form__hint">
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/list?year=<?= e(rawurlencode($year->getCode())) ?>">Zurück zum Journal</a>
            <?php if ($editable): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/edit?id=<?= e((string) $entry->getId()) ?>">Bearbeiten</a>
            <button type="button" class="be-btn be-btn--danger be-btn--sm" data-fetch-get="<?= e($actionBase) ?>/confirm-delete?id=<?= e((string) $entry->getId()) ?>">Löschen …</button>
            <?php else: ?>
            <span class="be-list__cell--muted"><?= e($notEditableWhy) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($entry->isManual()): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Änderungsprotokoll</h2>
            <span class="be-list__section-badge"><?= count($changes) ?></span>
        </div>
        <?php if ($changes === []): ?>
        <p class="be-list__empty">Seit dem Erfassen nicht geändert.</p>
        <?php else: ?>
        <div class="be-list__table be-list__table--disclose" style="--be-list-cols: 8rem 10rem minmax(10rem, 1fr)">
            <?php foreach ($changes as $i => $change): ?>
            <div class="be-list__item">
                <input class="be-list__disclosure-input" type="checkbox" id="change-<?= e((string) $change->getId()) ?>">
                <div class="be-list__row">
                    <label class="be-list__disclosure" for="change-<?= e((string) $change->getId()) ?>" title="Vorher / nachher anzeigen">▸</label>
                    <span class="be-list__cell"><span class="badge badge--warning"><?= e($changeLabels[$change->getAction()] ?? $change->getAction()) ?></span></span>
                    <span class="be-list__cell"><?= e($change->getChangedAt()->format('d.m.Y H:i')) ?></span>
                    <span class="be-list__cell">von <?= e($change->getChangedBy()) ?></span>
                </div>
                <div class="be-list__detail">
                    <div class="be-form__section">Vorher — <?= e((string) ($change->before()['date'] ?? '')) ?> · <?= e((string) ($change->before()['text'] ?? '')) ?></div>
                    <?= raw($snapshotLines($change->before())) ?>
                    <?php if ($change->after() !== null): ?>
                    <div class="be-form__section">Nachher — <?= e((string) ($change->after()['date'] ?? '')) ?> · <?= e((string) ($change->after()['text'] ?? '')) ?></div>
                    <?= raw($snapshotLines($change->after())) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
