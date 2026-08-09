<?php
/**
 * Backup list — hc1 (dark left slot): the primary action as a PICKER, because backup has
 * three kinds (Daten / Datenbank / Gesamtprojekt) and css-backend.md requires a
 * `.be-shell-add` picker rather than several buttons stacked in the band.
 *
 * Each item POSTs `type` to the same `/backend/service/backup/run` endpoint the three
 * per-section buttons used before — same contract, one place. The database item stays
 * VISIBLE but disabled when no database is configured; its `title` names the reason.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 *
 * @var bool $dbConfigured
 */

$kinds = [
    'data' => ['Daten',         'icon-database'],
    'db'   => ['Datenbank',     'icon-hard-drive'],
    'full' => ['Gesamtprojekt', 'icon-grid'],
];
?>
<div class="be-shell-add" data-panel-root>
    <button type="button" class="be-btn be-btn--primary" data-panel-trigger aria-haspopup="true" aria-expanded="false">
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-download"/></svg>
        Sichern
        <svg class="be-icon" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </button>
    <div class="be-shell-add__panel" hidden data-panel role="menu" aria-label="Sicherung starten">
        <?php foreach ($kinds as $type => [$label, $icon]):
            $blocked = $type === 'db' && !$dbConfigured;
        ?>
        <form data-fetch-post="/backend/service/backup/run">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <button type="submit" class="be-shell-add__item" role="menuitem"
                    <?= $blocked ? 'disabled title="Keine Datenbank konfiguriert (config/backup.inc.php)"' : '' ?>>
                <svg class="be-icon" width="13" height="13" aria-hidden="true"><use href="#<?= e($icon) ?>"/></svg>
                <?= e($label) ?>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</div>
