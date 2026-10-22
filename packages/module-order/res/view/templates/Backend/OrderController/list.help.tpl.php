<?php
/**
 * Help for the order list (ADR-048, HELP-003) — attached by `listAction()` with `toolbar: true`,
 * opened with «Hilfe» at the right end of the toolbar. A quick help: what the list is for, the
 * tabs (the phases, `OrderListing::VIEWS`), the main actions. The order window has its own help
 * (`edit.help`).
 */
?>
<h3>Aufträge</h3>
<p>Alle Offerten und Aufträge, ein Reiter pro <strong>Phase</strong>. Jeder Status gehört zu einer Phase; ein Auftrag steht im Reiter der Phase seines Status. Die Liste öffnet auf <strong>Termin</strong>.</p>
<ul>
    <li><strong>Eröffnet</strong> — Offerten und erfasste Aufträge: noch kein Lager, kein Buchungsdruck.</li>
    <li><strong>Termin</strong> — bestätigte Aufträge: der Lagerbestand ist reserviert.</li>
    <li><strong>Geliefert</strong> — die Ware ist weg: der Lagerbestand ist abgebucht.</li>
    <li><strong>Abgeschlossen</strong> — gebucht oder erledigt; gesperrt. Die Spalte <strong>Zahlung</strong> zeigt den Zahlungsstand der Rechnung.</li>
    <li><strong>Storniert</strong> — kommt nicht zustande; gesperrt.</li>
    <li><strong>Alle</strong> — jeder Auftrag.</li>
</ul>
<p><strong>+ Neuer Auftrag</strong> öffnet ein leeres Auftragsfenster. Das Symbol am Anfang einer Zeile öffnet den Auftrag im Fenster: der Stift, solange er bearbeitbar ist, das Schloss, wenn er eine Rechnung hat oder abgeschlossen bzw. storniert ist.</p>
<p><strong>Buchungsdruck …</strong> (nur in <strong>Termin</strong> und <strong>Geliefert</strong>): die Aufträge ankreuzen, dann zeigt eine Bestätigung Anzahl, Total und das Rechnungsdatum. Der Buchungsdruck erstellt und verbucht je Auftrag die Rechnung und zeigt danach die Liste der Rechnungen — jedes PDF einzeln, <strong>Alle als ein PDF</strong>, <strong>Alle als ZIP</strong> oder <strong>Alle per E-Mail senden …</strong> an die E-Mail-Adresse der Rechnungsadresse; die Aufträge gehen nach <strong>Abgeschlossen</strong>. Ein Auftrag, der seit dem Anzeigen der Liste geändert wurde, wird nicht gebucht. Steht im <strong>Betrag</strong> «–», ist im Auftrag vor dem Buchungsdruck etwas zu beheben.</p>
<p><strong>Suchen und sortieren:</strong> ein Spaltentitel sortiert, die Lupe daneben sucht in der Spalte; ein Klick auf eine Zelle öffnet die Suche ihrer Spalte. Ein Datum lässt sich als Tag, Monat (<code>11.2026</code>) oder Jahr suchen; <strong>Status</strong> sucht im Statusnamen. <strong>Zurücksetzen</strong> hebt Suche und Sortierung auf, der Reiter bleibt.</p>
