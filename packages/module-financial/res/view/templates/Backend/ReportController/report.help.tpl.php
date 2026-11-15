<?php
/**
 * Help for the reports «Auswertungen» (ADR-048; «Hilfe» at the right end of the toolbar beside the
 * report tabs, HELP-003) — one help for the five tabs: what each report answers, the range form
 * (Zeitraum / Stichtag, Vorjahre, Bildschirm / PDF) and the journal report's search; a section per
 * form field (`data-help-field` = the field's `name`). Attached by `ReportControllerTrait::reportPage()`.
 * Also the KI-Assistent's knowledge (ADR-055): «Bilanz», «Erfolgsrechnung», «Kontoauszug», «Saldo».
 */
?>
<h3>Auswertungen</h3>
<p>Die <strong>Auswertungen</strong> zeigen, was gebucht ist: Saldobilanz, Bilanz, Erfolgsrechnung, Kontoblatt (Kontoauszug) und Journal, am Bildschirm oder als PDF — z.B. den Saldo eines Kontos oder den Gewinn eines Zeitraums.</p>
<p>Hier wird nichts gebucht.</p>
<ul>
    <li><strong>Saldobilanz</strong> — jedes Konto mit Buchungen im Zeitraum: Total Soll, Total Haben und der Saldo. Die Kontrolle, ob alles aufgeht.</li>
    <li><strong>Bilanz</strong> — Aktiven und Passiven (Fremd- und Eigenkapital) an einem <strong>Stichtag</strong>; der Gewinn oder Verlust des laufenden Jahres steht als eigene Zeile im Eigenkapital.</li>
    <li><strong>Erfolgsrechnung</strong> — Ertrag und Aufwand eines Zeitraums, das Ergebnis ist der Gewinn oder Verlust.</li>
    <li><strong>Kontoblatt</strong> — alle Buchungen eines Kontos mit Gegenkonto und laufendem Saldo, beginnend mit dem Saldo am Anfang des Zeitraums.</li>
    <li><strong>Journal</strong> — alle Buchungen des Zeitraums mit ihren Zeilen, durchsuch- und sortierbar.</li>
</ul>
<p>Die Auswertungen zeigen immer <strong>ein</strong> Geschäftsjahr — gewählt oben im Menü. Die Salden des Vorjahres kommen über die Eröffnung (Stammdaten › Finanzen › Geschäftsjahre, «Eröffnung erstellen …») ins neue Jahr. Eine Kontonummer in einer Auswertung führt zu ihrem Kontoblatt, eine Buchungsnummer zur Buchung. Belege im Belegjournal und Rechnungen im Faktura-Journal sind noch nicht gebucht — ein Hinweis über der Auswertung nennt sie.</p>

<section data-help-field="account">
<h3>Konto</h3>
<p>Nur beim Kontoblatt: das Konto, dessen Buchungen gezeigt werden — aus der Liste wählen.</p>
</section>
<section data-help-field="from">
<h3>Von</h3>
<p>Der erste Tag des Zeitraums, innerhalb des gewählten Geschäftsjahres. Die Bilanz hat kein «Von» — sie rechnet immer ab Jahresbeginn.</p>
</section>
<section data-help-field="to">
<h3>Bis / Stichtag</h3>
<p>Der letzte Tag des Zeitraums; bei der Bilanz der Stichtag, an dem die Bestände gezeigt werden.</p>
</section>
<section data-help-field="period">
<h3>Zeitraum / Auswahl</h3>
<p>Füllt Von und Bis auf einen Klick: ganzes Jahr, Halbjahre, Quartale oder ein Monat — bei der Bilanz («Auswahl») den Stichtag: Jahresende, Halbjahr, Quartalsenden, Monatsenden. Ein von Hand geändertes Datum wählt die passende Auswahl wieder.</p>
</section>
<section data-help-field="compare">
<h3>Vergleich: Vorjahre</h3>
<p>Bei Bilanz und Erfolgsrechnung: dieselbe Spanne der beiden Vorjahre in eigenen Spalten daneben. Standardmässig eingeschaltet.</p>
</section>

<h3>Bildschirm oder PDF</h3>
<p><strong>«Bildschirm»</strong> zeigt die Auswertung hier; lange Kontoblätter und das Journal sind in Seiten geteilt. <strong>«PDF»</strong> öffnet dieselbe Auswertung als PDF in einem neuen Tab — mit allen Zeilen und dem Namen des Mandanten im Kopf, zum Speichern oder Drucken. Beim Journal enthält das PDF die aktuelle Suche und Sortierung.</p>

<h3>Journal durchsuchen</h3>
<p>Im Journal sortiert ein Klick auf Datum, Nr., Text oder Soll; die Lupe daneben sucht in der Spalte: Datum (Tag, Monat, Jahr), Nr. (genau), Konto (eine Buchung mit diesem Konto im Soll oder Haben), Text (ein Teil) und Soll (der Betrag der Buchung). Die Totale gelten für alles Gefundene. «Zurücksetzen» hebt Suche und Sortierung auf.</p>

<h3>Noch nicht verfügbar</h3>
<p>Die <strong>MWST-Abrechnung</strong> (Formular für die ESTV pro Quartal oder Semester) — folgt. Bis dahin: Umsatz und Steuer pro MWST-Code stehen im Journal (Spalte MWST) und auf den Kontoblättern der MWST-Konten (z.B. 1170, 1171, 2200).</p>
