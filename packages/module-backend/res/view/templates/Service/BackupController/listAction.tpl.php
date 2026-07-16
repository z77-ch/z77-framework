<?php
/**
 * Backup list — three type sections (data / db / full), each with its history
 * (directory scan, newest first) and a "run now" trigger. Rows carry the ⋮ hub
 * (download / delete). The db section shows a muted note instead of a trigger
 * when no database is configured.
 *
 * @var list<array{type: string, entries: list<\Z77\Shared\Backup\BackupEntry>}> $sections
 * @var bool $dbConfigured
 */

$labels = [
    'data' => ['Daten',        'Sichert das komplette data/-Verzeichnis (Inhalte, Navigation, Benutzer).'],
    'db'   => ['Datenbank',    'SQL-Dump der konfigurierten Datenbank (config/backup.inc.php).'],
    'full' => ['Gesamtprojekt', 'Sichert das Projekt ohne regenerierbare Verzeichnisse (vendor/, node_modules/, Cache, Backups).'],
];

$fmtSize = function (int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, '.', "'") . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
};
?>
<div class="be-list">
    <?php foreach ($sections as $i => $section):
        $type = $section['type'];
        [$title, $hint] = $labels[$type] ?? [$type, ''];
        $dbBlocked = $type === 'db' && !$dbConfigured;
    ?>
    <div class="be-list__section"<?= $i > 0 ? ' style="margin-top:1.5rem"' : '' ?>>
        <div class="be-list__section__head" style="margin-bottom:.5rem;display:flex;align-items:flex-start;justify-content:space-between;gap:1rem">
            <div>
                <h2 style="font-size:.95rem;margin:0"><?= e($title) ?></h2>
                <p style="font-size:.75rem;color:var(--be-muted,#94a3b8);margin:.15rem 0 0"><?= e($hint) ?></p>
            </div>
            <?php if ($dbBlocked): ?>
            <span style="font-size:.75rem;color:var(--be-muted,#94a3b8);white-space:nowrap">Keine Datenbank konfiguriert</span>
            <?php else: ?>
            <form data-fetch-post="/backend/service/backup/run" style="margin:0">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <button type="submit" class="be-btn be-btn--primary">
                    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-download"/></svg>
                    Jetzt sichern
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if (empty($section['entries'])): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Noch keine Backups vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($section['entries'] as $entry): ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/service/backup/actions?type=<?= e($type) ?>&file=<?= e(rawurlencode($entry->getFileName())) ?>">⋮</button>
                    <span class="be-tree__name"><code><?= e($entry->getFileName()) ?></code></span>
                    <span class="be-tree__url">
                        <?= e($entry->getCreatedAt()->format('d.m.Y H:i')) ?>
                        &nbsp;·&nbsp; <?= e($fmtSize($entry->getSizeBytes())) ?>
                        <?php if ($entry->getTrigger() !== ''): ?>
                        &nbsp;·&nbsp; <?= e($entry->getTrigger() === 'cron' ? 'Cron' : 'Manuell') ?>
                        <?php endif; ?>
                        <?php if (($entry->getMeta()['files'] ?? null) !== null): ?>
                        &nbsp;·&nbsp; <?= e((string)(int)$entry->getMeta()['files']) ?> Dateien
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
