<?php
/**
 * Help for the backup screen (ADR-048, HELP-003) — attached by `BackupController::listAction()`
 * with `toolbar: true`. The three kinds, the split action cell, the row ⋮, retention, restore.
 * Static text, no context. Facts: backup.md (retention, 500 MB download limit, restore by CLI).
 */
?>
<h3>Backup</h3>
<p>Die Installation sichern: Backup erstellen, Backup herunterladen, alte Backups löschen, wie oft automatisch gesichert wird und wie ein Backup zurückgespielt wird.</p>
<p>Drei Arten, je ein Abschnitt mit den vorhandenen Archiven (neueste zuerst):</p>
<ul>
    <li><strong>Daten</strong> — das Verzeichnis <code>data/</code>: Inhalte, Navigation, Benutzer, Einstellungen.</li>
    <li><strong>Datenbank</strong> — ein SQL-Abzug der Datenbank. Ohne konfigurierte Datenbank nicht wählbar.</li>
    <li><strong>Gesamtprojekt</strong> — das ganze Projekt ohne Verzeichnisse, die sich neu erzeugen lassen (vendor/, Cache, Backups), mit dem Datenbank-Abzug, wenn eine Datenbank konfiguriert ist.</li>
</ul>

<h4>Backup erstellen</h4>
<p><strong>Daten sichern</strong> oben links startet sofort ein Daten-Backup; der Pfeil ▾ daneben bietet <strong>Daten</strong>, <strong>Datenbank</strong> und <strong>Gesamtprojekt</strong>. Ein Gesamtprojekt kann eine Weile dauern. Die Spalte <strong>Auslöser</strong> zeigt, ob ein Archiv von Hand («Manuell») oder vom Zeitplan («Cron») stammt.</p>
<p>Automatisch sichern: unter Service › Jobs bei <strong>Backup — Daten</strong>, <strong>Backup — Datenbank</strong> oder <strong>Backup — Gesamtprojekt</strong> einen Zeitplan setzen. Nach jedem Backup räumt die Installation ältere Archive nach ihrer Aufbewahrungsregel weg. Die Übersicht meldet, wenn das letzte Backup zu alt ist.</p>

<h4>Herunterladen und löschen</h4>
<p>⋮ einer Zeile: <strong>Herunterladen</strong> lädt das Archiv; ist es grösser als 500 MB, steht dort stattdessen der Pfad zum Holen per SFTP (der Browser-Download würde abbrechen). <strong>Löschen</strong> entfernt das Archiv nach einer Rückfrage endgültig — es gibt keinen Papierkorb.</p>

<h4>Zurückspielen</h4>
<p>Im Backend gibt es bewusst keinen Knopf dafür: Zurückspielen überschreibt den aktuellen Stand. Das macht der Betreiber auf dem Server — einen Datenbank-Abzug mit dem Befehl <code>z77-restore</code> (er sichert vorher den aktuellen Stand), ein Daten-Archiv von Hand.</p>
<p>Nur Super-User sehen diese Seite: die Archive enthalten alle Benutzerkonten.</p>
