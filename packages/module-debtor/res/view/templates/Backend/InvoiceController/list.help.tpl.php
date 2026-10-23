<?php
/**
 * Help for the document list «Rechnungen» (ADR-048, HELP-003) — attached by `listAction()` with
 * `toolbar: true`, opened with «Hilfe» at the right end of the toolbar. A quick help: the views
 * (`INVOICE_VIEWS`), «+ Rechnung», the booking print. The editor has its own help (`form.help`).
 */
?>
<h3>Rechnungen</h3>
<p>Die Rechnungen und Gutschriften, ein Reiter pro Ansicht:</p>
<ul>
    <li><strong>In Fakturierung</strong> — Entwürfe: noch <strong>ohne Nummer</strong> («Entwurf» in der Spalte Nr.), nichts ist verbucht; sie lassen sich bearbeiten und als Vorschau öffnen.</li>
    <li><strong>Fakturiert</strong> — mit Nummer gedruckte Rechnungen im <strong>Faktura-Journal</strong>: beim Kunden, aber <strong>noch nicht verbucht</strong>; ihr PDF ist unveränderlich abgelegt.</li>
    <li><strong>Verbucht</strong> — Rechnungen, deren Journal verbucht ist: unveränderlich, eine Korrektur ist eine Gutschrift.</li>
    <li><strong>Gutschriften</strong> — Entwürfe, fakturierte und verbuchte, je mit der Rechnung, zu der sie gehören.</li>
</ul>
<p><strong>+ Rechnung</strong> öffnet eine neue Rechnung im Fenster. Das Symbol am Anfang einer Zeile öffnet das Dokument im Fenster — der Stift für einen Entwurf, das Schloss für ein verbuchtes Dokument; dort stehen Vorschau, Bearbeiten, PDF und Gutschrift.</p>
<p><strong>Fakturieren …</strong> (nur in <strong>In Fakturierung</strong>): die Entwürfe ankreuzen, dann zeigt eine Bestätigung Anzahl und Total. Fakturieren vergibt die Nummern, legt die PDFs ab und zeigt danach die Liste der fakturierten Dokumente — jedes PDF einzeln, <strong>Alle als ein PDF</strong>, <strong>Alle als ZIP</strong> oder <strong>Alle per E-Mail senden …</strong> an die E-Mail-Adresse der Rechnungsadresse (ohne Adresse übersprungen). Ein Entwurf, der seit dem Anzeigen der Liste geändert wurde, wird nicht fakturiert. Verbucht werden die fakturierten Dokumente erst im <strong>Faktura-Journal</strong> mit «Journal verbuchen …».</p>
<p><strong>Suchen und sortieren:</strong> ein Spaltentitel sortiert, die Lupe daneben sucht in der Spalte; ein Klick auf eine Zelle öffnet die Suche ihrer Spalte. Ein Datum lässt sich als Tag, Monat (<code>06.2026</code>) oder Jahr suchen. <strong>Zurücksetzen</strong> hebt Suche und Sortierung auf, der Reiter bleibt.</p>
