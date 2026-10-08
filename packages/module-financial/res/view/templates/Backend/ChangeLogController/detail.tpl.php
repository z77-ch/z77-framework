<?php
/**
 * One row of the change log (Finanzen › Änderungsprotokoll, owner
 * 2026-10-08): what kind (geändert / gelöscht), when, who, and the journal
 * entry BEFORE and — for an edit — AFTER the change, each with its date, its
 * text and its lines (the shared `Backend/partials/entrySnapshot`, the same
 * rendering as the entry's own change log in the journal detail). A link to
 * the entry while it still exists. READ-ONLY.
 *
 * Fetched from the list's state icon it is a WINDOW (ADR-047): the root
 * declares mask and entity; the entry link loads into the same window.
 *
 * @var \Z77\Module\Financial\Entities\EntryChange $change
 * @var bool $entryExists  the entry is still in the journal (an edit, not a delete)
 * @var array<string, string> $labels
 * @var bool $window
 * @var string $windowWidth
 * @var string $actionBase
 * @var string $journalBase
 */
$window  = !empty($window);
$before  = $change->before();
$after   = $change->after();
$ref     = (string) ($before['fiscal_year'] ?? '') . '/' . $change->getEntryNumber();
$label   = $labels[$change->getAction()] ?? $change->getAction();
$winAttr = $window
    ? ' data-window="journal-entry-change" data-window-entity="journal-entry-change:' . (int) $change->getId() . '" data-window-title="' . e('Änderung an Buchung ' . $ref) . '"'
        . (!empty($windowWidth) ? ' data-window-width="' . e($windowWidth) . '"' : '')
    : '';
$day = static function (array $snapshot): string {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($snapshot['date'] ?? ''));

    return $date === false ? '' : $date->format('d.m.Y');
};
?>
<div class="be-list"<?= $winAttr ?>>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Buchung <code><?= e($ref) ?></code>
                <small class="be-list__cell--muted">· <?= e($change->getChangedAt()->format('d.m.Y H:i')) ?> von <?= e($change->getChangedBy()) ?></small>
            </h2>
            <span class="badge <?= $after === null ? 'badge--danger' : 'badge--warning' ?>"><?= e($label) ?></span>
        </div>

        <div class="be-form__section">Vorher — <?= e($day($before)) ?> · <?= e((string) ($before['text'] ?? '')) ?></div>
        <?= $this->partial('Backend/partials/entrySnapshot', ['snapshot' => $before], 'Z77\\Module\\Financial') ?>
        <?php if ($after !== null): ?>
        <div class="be-form__section">Nachher — <?= e($day($after)) ?> · <?= e((string) ($after['text'] ?? '')) ?></div>
        <?= $this->partial('Backend/partials/entrySnapshot', ['snapshot' => $after], 'Z77\\Module\\Financial') ?>
        <?php else: ?>
        <p class="be-list__empty">Die Buchung wurde gelöscht — die Nummer <?= e($ref) ?> bleibt eine Lücke im Journal.</p>
        <?php endif; ?>

        <p class="be-form__hint">
            <?php if (!$window): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/list">Zurück zum Änderungsprotokoll</a>
            <?php endif; ?>
            <?php if ($entryExists): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($journalBase) ?>/detail?id=<?= e((string) $change->getEntryId()) ?>"<?= $window ? ' data-window-link' : '' ?>>Buchung anzeigen</a>
            <?php endif; ?>
        </p>
    </div>
</div>
