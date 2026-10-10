<?php
/**
 * One backup type section (data / db / full) with its history — rendered by the list and by
 * BackupController as the in-place answer of a run and of a delete that empties the section
 * (`replace-html` of `[data-backup-section]`), so both render identically; core.js wires the ⋮ (ADR-047 addendum
 * 2026-10-10: a one-row change answers with what changed, not `reload`).
 *
 * A row carries `data-entity="backup:<type>/<file>"` (FetchResponse::removeRow); the badge
 * carries `data-backup-count` so a delete can correct the count in place.
 *
 * @var string $type     'data' | 'db' | 'full'
 * @var list<\Z77\Shared\Backup\BackupEntry> $entries
 * @var bool   $dbConfigured
 */

$labels = [
    'data' => ['Daten',        'Sichert das komplette data/-Verzeichnis (Inhalte, Navigation, Benutzer).'],
    'db'   => ['Datenbank',    'SQL-Dump der konfigurierten Datenbank (config/client/database.inc.php).'],
    'full' => ['Gesamtprojekt', 'Sichert das Projekt ohne regenerierbare Verzeichnisse (vendor/, node_modules/, Cache, Backups) — mit SQL-Dump der Datenbank, wenn eine konfiguriert ist.'],
];

$fmtSize = function (int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, '.', "'") . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
};

[$title, $hint] = $labels[$type] ?? [$type, ''];
$dbBlocked = $type === 'db' && !$dbConfigured;
?>
<div class="be-list__section" data-backup-section="<?= e($type) ?>">
    <div class="be-list__section-header">
        <h2 class="be-list__section-title"><?= e($title) ?></h2>
        <span class="be-list__section-badge" data-backup-count="<?= e($type) ?>"><?= count($entries) ?></span>
    </div>
    <p class="be-list__section-hint"><?= e($hint) ?></p>

    <?php if (empty($entries)): ?>
    <p class="be-list__empty"><?= $dbBlocked
        ? 'Keine Datenbank konfiguriert — siehe config/client/database.inc.php.'
        : 'Noch keine Backups vorhanden.' ?></p>
    <?php else: ?>
    <div class="be-list__frame">
        <div class="be-list__table be-list__table--menu be-list__table--drop"
             style="--be-list-cols:    minmax(12rem, 1fr) 9rem 6rem 6rem 6rem;
                    --be-list-cols-sm: minmax(10rem, 1fr) 9rem 6rem;
                    --be-list-cols-xs: minmax(6rem, 1fr) 9rem">
            <div class="be-list__head">
                <span class="be-list__col"></span>
                <span class="be-list__col">Datei</span>
                <span class="be-list__col">Erstellt</span>
                <span class="be-list__col be-list__col--num" data-priority="2">Grösse</span>
                <span class="be-list__col" data-priority="3">Auslöser</span>
                <span class="be-list__col be-list__col--num" data-priority="3">Dateien</span>
            </div>
            <?php foreach ($entries as $entry):
                $files = $entry->getMeta()['files'] ?? null;
            ?>
            <div class="be-list__item" data-entity="backup:<?= e($type . '/' . $entry->getFileName()) ?>">
                <div class="be-list__row">
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/service/backup/actions?type=<?= e($type) ?>&file=<?= e(rawurlencode($entry->getFileName())) ?>">⋮</button>
                    <span class="be-list__cell be-list__cell--mono" title="<?= e($entry->getFileName()) ?>"><?= e($entry->getFileName()) ?></span>
                    <span class="be-list__cell be-list__cell--muted"><?= e($entry->getCreatedAt()->format('d.m.Y H:i')) ?></span>
                    <span class="be-list__cell be-list__cell--muted be-list__cell--num" data-priority="2"><?= e($fmtSize($entry->getSizeBytes())) ?></span>
                    <span class="be-list__cell be-list__cell--muted" data-priority="3"><?=
                        $entry->getTrigger() === '' ? '—' : e($entry->getTrigger() === 'cron' ? 'Cron' : 'Manuell') ?></span>
                    <span class="be-list__cell be-list__cell--muted be-list__cell--num" data-priority="3"><?=
                        $files === null ? '—' : e((string) (int) $files) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
