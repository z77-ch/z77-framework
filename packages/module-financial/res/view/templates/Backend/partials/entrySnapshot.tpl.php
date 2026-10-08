<?php
/**
 * The lines of a journal-entry SNAPSHOT (`JournalEntry::snapshot()`, stored by
 * the change log as before / after) as a small read-only table: account,
 * name, line text, debit, credit, tax (code · base / amount). Shared by the
 * entry's own change log (`JournalController/detail`) and the change log
 * screen (`ChangeLogController/detail`) — one rendering of a snapshot
 * (Rule 8). The values are the snapshot's decimal strings, shown as stored.
 *
 * @var array<string, mixed> $snapshot
 */
$lines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
?>
<div class="be-list__table" style="--be-list-cols: 4rem minmax(10rem, 2fr) minmax(8rem, 1fr) 7rem 7rem minmax(8rem, 1fr)">
    <?php foreach ($lines as $line): ?>
    <div class="be-list__item"><div class="be-list__row">
        <span class="be-list__cell be-list__cell--mono"><?= e((string) ($line['account'] ?? '')) ?></span>
        <span class="be-list__cell"><?= e((string) ($line['account_name'] ?? '')) ?></span>
        <span class="be-list__cell be-list__cell--muted"><?= e((string) ($line['text'] ?? '')) ?></span>
        <span class="be-list__cell be-list__cell--num"><?= ($line['debit'] ?? '0.00') !== '0.00' ? e((string) $line['debit']) : '' ?></span>
        <span class="be-list__cell be-list__cell--num"><?= ($line['credit'] ?? '0.00') !== '0.00' ? e((string) $line['credit']) : '' ?></span>
        <span class="be-list__cell be-list__cell--muted"><?= ($line['tax_code'] ?? null) !== null ? e($line['tax_code'] . ' · ' . ($line['tax_base'] ?? '') . ' / ' . ($line['tax_amount'] ?? '')) : '' ?></span>
    </div></div>
    <?php endforeach; ?>
</div>
