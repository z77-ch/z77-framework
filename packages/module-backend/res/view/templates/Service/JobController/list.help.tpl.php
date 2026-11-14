<?php
/**
 * Help for the jobs screen (ADR-048, HELP-003) — attached by `JobController::listAction()` with
 * `toolbar: true`. The runner, the job rows (switch, ▶, ⋮), the schedule forms, queue and history.
 * Static text, no context. Facts: jobs.md, ADR-031, JobController::SCHEDULE_HELP.
 */
?>
<h3>Jobs</h3>
<p>Arbeiten, die im Hintergrund laufen: Zeitplan setzen oder ändern, Job von Hand starten, automatisches Backup einschalten, fehlgeschlagenen Lauf wiederholen, prüfen ob der Job-Runner läuft.</p>

<h4>Der Job-Runner</h4>
<p>Jobs laufen nur, wenn der Server jede Minute den Runner aufruft (Cron-Eintrag). Die Werkzeugzeile zeigt grün «Runner läuft — letzter Durchlauf …» oder rot «Runner läuft nicht». Rot heisst: nichts wird ausgeführt, auch kein Zeitplan; die Seite zeigt dann die Zeile, die der Betreiber auf dem Server einträgt.</p>

<h4>Jobs</h4>
<p>Eine Zeile pro Job, den ein Modul anbietet, mit Modul und Rolle, mit der er läuft. Dahinter: Zeitplan, nächster Lauf, offene Einträge, letzter Lauf.</p>
<ul>
    <li>Der <strong>Schalter</strong> am Zeilenanfang schaltet den Zeitplan ein oder aus (nur, wenn einer gesetzt ist).</li>
    <li><strong>▶ Jetzt starten</strong> reiht den Job sofort ein; er läuft beim nächsten Durchlauf des Runners. Wartet er schon oder läuft er gerade, wird er nicht doppelt eingereiht.</li>
    <li>⋮ › <strong>Zeitplan setzen …</strong> bzw. <strong>Zeitplan ändern …</strong> öffnet den Zeitplan; leer speichern entfernt ihn.</li>
</ul>
<p>Zeitplan-Formen: <code>every:15m</code> (alle 15 Minuten), <code>every:2h</code>, <code>hourly@:20</code> (jede Stunde um :20), <code>daily@03:15</code>, <code>weekly@mon,03:15</code>, <code>monthly@1,06:00</code> (am 1. um 06:00).</p>
<p>Einige Jobs haben ab Werk einen Zeitplan (z.B. Statistik-Verdichtung, GeoIP-Datenbank). Jobs, die Daten löschen oder E-Mails versenden, und die Backups schaltest du selbst ein.</p>

<h4>Wartet und Verlauf</h4>
<p><strong>Wartet</strong> zeigt eingereihte Läufe; <strong>Entfernen</strong> nimmt einen wartenden wieder heraus (ein laufender nicht). <strong>Verlauf</strong> zeigt die letzten 25 Läufe mit Ergebnis; bei einem fehlgeschlagenen reiht <strong>Nochmals</strong> ihn neu ein. Fehlgeschlagene Läufe der letzten 7 Tage meldet auch die Übersicht.</p>
<p>Nur Super-User sehen diese Seite: ein Job kann Daten löschen.</p>
