<?php
/**
 * Help for the data import (ADR-032, ADR-048, HELP-003) — attached by
 * `ImportController::listAction()` with `toolbar: true`. Source, plan, the groups and their
 * per-record buttons, Übernehmen / Verwerfen. Static text, no context. Facts: import.md,
 * ImportController::GROUPS / JOB_THRESHOLD.
 */
?>
<h3>Import</h3>
<p>Datensätze in die Installation übernehmen: neue Menüeinträge, Adressen (Nav Alias) und Metadaten aus einem Framework-Update oder aus einer eigenen JSON-Datei einspielen — geprüft, Eintrag für Eintrag bestätigt.</p>
<p>Der Import <strong>übernimmt</strong> nur: er ersetzt nie still, löscht nie und schreibt erst, wenn du bestätigt hast.</p>

<h4>1. Quelle wählen und Plan berechnen</h4>
<ul>
    <li><strong>Framework-Standarddaten</strong> — was das installierte Framework mitliefert, verglichen mit deiner Installation: <strong>Plan berechnen</strong> oben links.</li>
    <li><strong>Eigene Datei</strong> — eine JSON-Datei per FTP nach <code>data/framework/import/inbox/</code> legen; sie erscheint in der Liste. Ziel wählen (welche Art Datensätze) und <strong>Plan berechnen</strong>.</li>
</ul>

<h4>2. Entscheiden</h4>
<p>Der Plan ordnet jeden Datensatz einer Gruppe zu; jede Gruppe erklärt sich mit einem Satz. Bei abweichenden Einträgen zeigt eine Tabelle «bei dir» und «wird zu».</p>
<ul>
    <li><strong>Zuordnung nötig</strong> — <strong>Ist mein …</strong> (Vorschlag), oder im Feld einen eigenen Eintrag wählen und <strong>Zuordnen</strong>, oder <strong>Habe ich nicht — neu anlegen</strong>.</li>
    <li><strong>Neu</strong>, <strong>Inhalt weicht ab</strong>, <strong>Kennung nachtragen</strong> — <strong>Anlegen</strong>, <strong>Änderung übernehmen</strong> bzw. <strong>Kennung setzen</strong> markiert; <strong>Ablehnen</strong> lässt den Eintrag aus; <strong>Zurücksetzen</strong> nimmt die Entscheidung zurück. «Neu» und «Kennung nachtragen» lassen sich mit <strong>Alle … markieren</strong> auf einmal markieren.</li>
    <li><strong>Wartet auf anderes</strong>, <strong>Nicht importierbar</strong>, <strong>Bereits vorhanden</strong> — nichts zu tun.</li>
</ul>

<h4>3. Übernehmen oder verwerfen</h4>
<p>Die Werkzeugzeile zählt die markierten Einträge. <strong>Übernehmen</strong> schreibt sie; bei mehr als 50 läuft das als Job im Hintergrund («Übernehmen (als Job)»). <strong>Verwerfen</strong> wirft den Plan weg, ohne etwas zu schreiben. Danach zeigt «Letzte Übernahme», was geschrieben wurde und was scheiterte.</p>
<p>Hat sich seit dem Berechnen etwas geändert, ist der Plan nicht mehr gültig: <strong>Plan verwerfen</strong> und neu berechnen.</p>
<p>Nur Super-User sehen diese Seite.</p>
