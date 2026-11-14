<?php
/**
 * Help for the order list (ADR-048, HELP-003) — attached by `listAction()` with `toolbar: true`,
 * opened with «Hilfe» at the right end of the toolbar. A quick help: what the list is for, the
 * tabs (the phases, `closed` split in three — `OrderListing::VIEWS`), the main actions, the stock
 * (not built yet — P7b). The order window has its own help (`edit.help`); the whole flow is
 * `Backend/Guide/orderFlow.help`.
 */
?>
<h3>Aufträge</h3>
<p>Alle Offerten und Aufträge, ein Reiter pro <strong>Phase</strong>: Offerte suchen, Auftrag öffnen, neuen Auftrag erfassen, mehrere Aufträge fakturieren. Ein Auftrag steht im Reiter der Phase seines Status; die Liste öffnet auf <strong>Termin</strong>.</p>
<ul>
    <li><strong>Eröffnet</strong> — Offerten und erfasste Aufträge: noch kein Fakturieren.</li>
    <li><strong>Termin</strong> — bestätigte Aufträge.</li>
    <li><strong>Geliefert</strong> — der Lieferschein ist gedruckt bzw. die Ware ist geliefert.</li>
    <li><strong>Fakturiert</strong> — die Rechnung ist erstellt und steht im Faktura-Journal; der Auftrag ist noch änderbar (danach «Neu fakturieren …»).</li>
    <li><strong>Gebucht</strong> — die Rechnung ist verbucht; gesperrt. In beiden Reitern zeigt die Spalte <strong>Zahlung</strong> den Zahlungsstand der Rechnung.</li>
    <li><strong>Erledigt</strong> — von Hand abgeschlossen, ohne Rechnung; gesperrt.</li>
    <li><strong>Abgelegt</strong> — beendet ohne Warenbewegung und aufbewahrt, z.B. eine abgelehnte Offerte (Status «Storniert»); gesperrt.</li>
    <li><strong>Alle</strong> — jeder Auftrag ausser den gelöschten.</li>
    <li><strong>Gelöscht</strong> — Erfassungsfehler: mit Nummer und Grund aufbewahrt; nichts mehr änderbar.</li>
</ul>
<p><strong>+ Neuer Auftrag</strong> öffnet ein leeres Auftragsfenster. Das Symbol am Zeilenanfang öffnet den Auftrag: der Stift, solange er bearbeitbar ist, das Schloss, wenn er gesperrt ist.</p>
<p><strong>Fakturieren …</strong> (nur in <strong>Termin</strong> und <strong>Geliefert</strong>): Aufträge ankreuzen, die Bestätigung zeigt Anzahl, Total und Rechnungsdatum. Je Auftrag entsteht die Rechnung im Faktura-Journal (verbucht wird dort mit «Journal verbuchen»); danach die Liste der Rechnungen — PDF einzeln, <strong>Alle als ein PDF</strong>, <strong>Alle als ZIP</strong>, <strong>Alle per E-Mail senden …</strong>. Ein seither geänderter Auftrag wird ausgelassen. Steht im <strong>Betrag</strong> «–», ist im Auftrag vorher etwas zu beheben.</p>
<p><strong>Suchen und sortieren:</strong> ein Spaltentitel sortiert, die Lupe sucht in der Spalte, ein Klick auf eine Zelle öffnet die Suche ihrer Spalte. Ein Datum als Tag, Monat (<code>11.2026</code>) oder Jahr; <strong>Status</strong> sucht im Statusnamen. <strong>Zurücksetzen</strong> hebt Suche und Sortierung auf.</p>
<p><strong>Lager: noch nicht verfügbar — folgt.</strong> Ein Auftrag reserviert und bucht heute keinen Bestand.</p>
