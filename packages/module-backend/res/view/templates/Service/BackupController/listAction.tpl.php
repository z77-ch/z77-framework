<?php
/**
 * Backup list — three type sections (data / db / full), each with its history
 * (directory scan, newest first). Rows carry the ⋮ hub (download / delete).
 *
 * PILOT for `.be-list` v2 (2026-08-08). The five fields this screen shows — file, created,
 * size, trigger, file count — used to be glued into ONE text slot with `·` separators and
 * truncated with no column header to reconstruct them from. They are five real columns now.
 * `--be-list-cols-sm` + `data-priority="2"` drop trigger and count when the pane gets narrow,
 * rather than silently cutting the string. Everything below is CSS: no JS, no controller
 * change. Sorting is deliberately NOT wired here — the component supports it, but the entries
 * come from a directory scan and would need a controller change first.
 *
 * The "run now" triggers are NOT here: all three moved into the shell header band
 * as one `.be-shell-add` picker (`list.hc1.tpl.php`), per the css-backend rule that a
 * view with SEVERAL add kinds uses a picker instead of stacking buttons. The db entry
 * is disabled there when no database is configured; this template only reflects that
 * state in the section's empty text.
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
    <?php foreach ($sections as $section):
        $type = $section['type'];
        [$title, $hint] = $labels[$type] ?? [$type, ''];
        $dbBlocked = $type === 'db' && !$dbConfigured;
    ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
            <span class="be-list__section-badge"><?= count($section['entries']) ?></span>
        </div>
        <p class="be-list__section-hint"><?= e($hint) ?></p>

        <?php if (empty($section['entries'])): ?>
        <p class="be-list__empty"><?= $dbBlocked
            ? 'Keine Datenbank konfiguriert — siehe config/backup.inc.php.'
            : 'Noch keine Backups vorhanden.' ?></p>
        <?php else: ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--menu be-list__table--drop"
                 style="--be-list-cols:    minmax(12rem, 1fr) 9rem 6rem 6rem 6rem;
                        --be-list-cols-sm: minmax(10rem, 1fr) 9rem 6rem">
                <div class="be-list__head">
                    <span class="be-list__col"></span>
                    <span class="be-list__col">Datei</span>
                    <span class="be-list__col">Erstellt</span>
                    <span class="be-list__col be-list__col--num">Grösse</span>
                    <span class="be-list__col" data-priority="2">Auslöser</span>
                    <span class="be-list__col be-list__col--num" data-priority="2">Dateien</span>
                </div>
                <?php foreach ($section['entries'] as $entry):
                    $files = $entry->getMeta()['files'] ?? null;
                ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <button type="button" class="be-tree__menu" title="Aktionen"
                                data-fetch-get="/backend/service/backup/actions?type=<?= e($type) ?>&file=<?= e(rawurlencode($entry->getFileName())) ?>">⋮</button>
                        <span class="be-list__cell be-list__cell--mono" title="<?= e($entry->getFileName()) ?>"><?= e($entry->getFileName()) ?></span>
                        <span class="be-list__cell be-list__cell--muted"><?= e($entry->getCreatedAt()->format('d.m.Y H:i')) ?></span>
                        <span class="be-list__cell be-list__cell--muted be-list__cell--num"><?= e($fmtSize($entry->getSizeBytes())) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="2"><?=
                            $entry->getTrigger() === '' ? '—' : e($entry->getTrigger() === 'cron' ? 'Cron' : 'Manuell') ?></span>
                        <span class="be-list__cell be-list__cell--muted be-list__cell--num" data-priority="2"><?=
                            $files === null ? '—' : e((string) (int) $files) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
