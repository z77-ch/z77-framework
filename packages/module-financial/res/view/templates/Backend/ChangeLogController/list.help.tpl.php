<?php
/**
 * Help for the list «Änderungsprotokoll» (ADR-048, HELP-003: «Hilfe» at the right end of the
 * toolbar) — what the log records and how to read a change; a section per search field
 * (`data-help-field` = the column key). Also the KI-Assistent's knowledge (ADR-055): «wer hat die
 * Buchung geändert», «gelöschte Buchung», «Nummernlücke».
 */
?>
<h3>Änderungsprotokoll</h3>
<p>Das <strong>Änderungsprotokoll</strong> zeigt jede Änderung und jede Löschung einer Buchung: wer, wann, was vorher und nachher stand. Hier findest du heraus, wer eine Buchung geändert hat und warum im Journal eine Nummer fehlt (eine gelöschte Buchung hinterlässt eine Lücke). Das Protokoll ist nur zum Nachlesen — hier wird nichts geändert.</p>
<ul>
    <li>Die Liste zeigt das Geschäftsjahr, das oben im Menü gewählt ist; der letzte Eintrag der Auswahl, <strong>«Alle Jahre»</strong>, zeigt alle.</li>
    <li><strong>Art</strong>: «geändert» (vorher und nachher) oder «gelöscht» (nur vorher — die Nummer bleibt frei).</li>
    <li>Das Zeichen am Zeilenanfang öffnet die Änderung im Fenster: die Zeilen vorher und nachher. <strong>«Buchung anzeigen»</strong> führt zur Buchung, solange es sie noch gibt.</li>
    <li>Erfasst werden Buchungen von Hand (Einzel- und Sammelbuchung) und die Korrekturen, die ein Modul an seinen eigenen Buchungen macht (z.B. eine neu erstellte Eröffnung). Das Erfassen einer Buchung selbst steht in der Buchung (erfasst von, am).</li>
</ul>
<section data-help-field="f_at">
<h3>Zeitpunkt</h3>
<p>Wann geändert oder gelöscht wurde. Suche: Tag (<code>15.11.2026</code>), Monat (<code>11.2026</code>) oder Jahr.</p>
</section>
<section data-help-field="f_nr">
<h3>Nr.</h3>
<p>Die Nummer der Buchung im Geschäftsjahr. Suche: die genaue Nummer.</p>
</section>
<section data-help-field="f_text">
<h3>Text</h3>
<p>Der Buchungstext. Suche: ein Teil des Textes.</p>
</section>
<section data-help-field="f_who">
<h3>Wer</h3>
<p>Der Benutzername, der geändert hat (bei einem Modul der Name des Ablaufs). Suche: ein Teil des Namens.</p>
</section>
