<?php
/**
 * Action cell (hc1, `{action}.act`) of `service/backup/list` — ADR-033 rev. 2026-10-08: the most
 * frequent action of the entry as a SPLIT picker «↓ Daten sichern | ▾» — the main part NAMES
 * what it does (owner 2026-10-08: «ich weiss nicht, was Sichern sichert»). Backup has three kinds
 * (Daten / Datenbank / Gesamtprojekt) and one of them is the everyday one: the main part runs
 * «Daten» directly, the narrow chevron part opens the menu with all three, «Daten» first
 * (css-backend.md → split picker). Rendered inset in the island accent by the shell; on a phone
 * the cell is ONE square showing the chevron alone — the main part is hidden there, the menu
 * carries the default as its first item.
 *
 * Every part POSTs `type` to the same `/backend/service/backup/run` endpoint — same contract,
 * one place. The database item stays VISIBLE but disabled when no database is configured; its
 * `title` names the reason.
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
<div class="be-shell-add be-shell-add--split" data-panel-root>
    <form class="be-shell-add__main" data-fetch-post="/backend/service/backup/run">
        <input type="hidden" name="type" value="data">
        <button type="submit" class="be-btn be-btn--primary" title="Daten sichern">
            <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-download"/></svg>
            <span class="be-btn__label">Daten sichern</span>
        </button>
    </form>
    <button type="button" class="be-btn be-btn--primary be-shell-add__toggle" data-panel-trigger
            aria-haspopup="true" aria-expanded="false" aria-label="Sicherung wählen" title="Sicherung wählen">
        <svg class="be-icon be-shell-add__chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </button>
    <div class="be-shell-add__panel" hidden data-panel role="menu" aria-label="Sicherung starten">
        <?php foreach ($kinds as $type => [$label, $icon]):
            $blocked = $type === 'db' && !$dbConfigured;
        ?>
        <form data-fetch-post="/backend/service/backup/run">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <button type="submit" class="be-shell-add__item" role="menuitem"
                    <?= $blocked ? 'disabled title="Keine Datenbank konfiguriert (config/client/database.inc.php)"' : '' ?>>
                <svg class="be-icon" width="13" height="13" aria-hidden="true"><use href="#<?= e($icon) ?>"/></svg>
                <?= e($label) ?>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</div>
