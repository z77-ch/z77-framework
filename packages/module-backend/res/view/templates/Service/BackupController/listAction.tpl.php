<?php
/**
 * Backup list — three type sections (data / db / full), each with its history
 * (directory scan, newest first). Rows carry the ⋮ hub (download / delete).
 *
 * PILOT for `.be-list` v2 (2026-08-08). The five fields this screen shows — file, created,
 * size, trigger, file count — used to be glued into ONE text slot with `·` separators and
 * truncated with no column header to reconstruct them from. They are five real columns now.
 * They drop in two stages rather than being silently cut: at 40rem trigger and count go
 * (`--be-list-cols-sm`, `data-priority="3"`), at 28rem size follows (`--be-list-cols-xs`,
 * `data-priority="2"`), leaving name and date — enough to find a backup on a phone.
 * Everything below is CSS: no JS, no controller change. Sorting is deliberately NOT wired here — the component supports it, but the entries
 * come from a directory scan and would need a controller change first.
 *
 * One section = `_section.tpl.php` — the delete answers in place with the same partial.
 *
 * The "run now" triggers are NOT here: all three moved into the shell header band
 * as one `.be-shell-add` picker (`list.act.tpl.php`, the action cell), per the css-backend rule that a
 * view with SEVERAL add kinds uses a picker instead of stacking buttons. The db entry
 * is disabled there when no database is configured; this template only reflects that
 * state in the section's empty text.
 *
 * @var list<array{type: string, entries: list<\Z77\Shared\Backup\BackupEntry>}> $sections
 * @var bool $dbConfigured
 */
?>
<div class="be-list">
    <?php foreach ($sections as $section): ?>
    <?= $this->partial('Service/BackupController/_section', [
        'type'         => $section['type'],
        'entries'      => $section['entries'],
        'dbConfigured' => $dbConfigured,
    ], 'Z77\\Module\\Backend') ?>
    <?php endforeach; ?>
</div>
