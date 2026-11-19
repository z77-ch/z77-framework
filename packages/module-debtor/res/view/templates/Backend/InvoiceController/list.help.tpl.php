<?php
/**
 * Help for the document list «Rechnungen» (ADR-048, HELP-003) — attached by `listAction()` with
 * `toolbar: true`, opened with «Hilfe» at the right end of the toolbar. A quick help: the views
 * (`INVOICE_VIEWS`), «+ Rechnung», «Fakturieren …», the state icons. The mask has its own help
 * (`form.help`); the whole workflow is `Guide/invoiceWorkflow.help`.
 */
?>
<h3>Rechnungen</h3>
<p>Rechnungen und Gutschriften schreiben, fakturieren und wiederfinden: eine neue Rechnung erstellen, Entwürfe nummerieren und drucken, das PDF einer Rechnung öffnen, eine Rechnung korrigieren oder gutschreiben.</p>
<h4>Die Reiter — ein Stand pro Reiter</h4>
<ul>
    <li><strong>In Fakturierung</strong> — Entwürfe: noch <strong>ohne Nummer</strong> («Entwurf» in der Spalte Nr.), nichts ist verbucht; sie lassen sich bearbeiten und als Vorschau öffnen.</li>
    <li><strong>Fakturiert</strong> — mit Nummer gedruckte Rechnungen im <strong>Faktura-Journal</strong>: bereit für den Kunden, aber <strong>noch nicht verbucht</strong>; ihr PDF ist unveränderlich abgelegt. Korrigieren geht hier noch ohne Gutschrift.</li>
    <li><strong>Verbucht</strong> — Rechnungen und Mahngebühren, deren Journal verbucht ist: unveränderlich, eine Korrektur ist eine Gutschrift.</li>
    <li><strong>Gutschriften</strong> — Entwürfe, fakturierte und verbuchte, je mit der Rechnung, zu der sie gehören. Eine Gutschrift entsteht in der verbuchten Rechnung (<strong>Gutschrift erstellen …</strong>); ihr Entwurf wird in seinem Fenster mit <strong>Fakturieren …</strong> fakturiert, nicht über die Liste.</li>
    <li><strong>Ersetzt</strong> und <strong>Gelöscht</strong> — fakturierte Rechnungen, die vor dem Verbuchen durch eine neue ersetzt oder mit Grund gelöscht wurden. Sie bleiben mit ihrer Nummer stehen, damit die Nummern lückenlos belegt sind.</li>
</ul>
<h4>Bezahlt? — der Reiter «Verbucht»</h4>
<p>Im Reiter <strong>Verbucht</strong> zeigt jede Zeile, ob und wie die Rechnung bezahlt ist:</p>
<ul>
    <li><strong>Bezahlt am</strong> — leer: noch <strong>offen</strong>. Ein Datum: <strong>bezahlt</strong>, am Tag der letzten Zahlung («(2)» dahinter: in zwei Zahlungen). Ein Datum mit «· Teil»: <strong>teilbezahlt</strong>. «gutgeschrieben»: die Rechnung wurde durch eine Gutschrift aufgehoben, nicht bezahlt.</li>
    <li><strong>Zahlbetrag</strong> — das eingegangene Geld. Skonto, Verlust und Gutschriften zählen hier nicht mit; darum kann der Zahlbetrag kleiner sein als der Betrag einer bezahlten Rechnung.</li>
    <li><strong>Zahlziel</strong> — über welches Zahlungsziel das Geld kam (z.B. <code>lukb</code>); «+1»: über ein weiteres.</li>
</ul>
<p>Hat eine Rechnung eine verbuchte Gutschrift, steht hinter dem Namen «· Gutschrift» mit deren Nummer. Nach dem Zahlungsdatum suchen und sortieren kannst du unter <strong>Debitoren</strong> (Reiter «Alle»).</p>
<h4>Rechnung erstellen und öffnen</h4>
<p><strong>+ Rechnung</strong> öffnet eine neue Rechnung im Fenster. Das Symbol am Anfang einer Zeile öffnet das Dokument im Fenster — der <strong>Stift</strong>, wenn es sich noch ändern lässt (ein Entwurf oder eine fakturierte, nicht aus einem Auftrag stammende Rechnung), sonst das <strong>Schloss</strong>. Dort stehen je nach Stand Vorschau, Fakturieren, Entwurf löschen, PDF, Per E-Mail, Rechnung löschen, Zahlung erfassen und Gutschrift erstellen. Rechnungen aus einem Auftrag werden im Auftrag geändert.</p>
<h4>Entwürfe fakturieren</h4>
<p><strong>Fakturieren …</strong> (nur im Reiter «In Fakturierung»): die Entwürfe ankreuzen, dann zeigt eine Bestätigung Anzahl und Total. Fakturieren vergibt die Nummern, legt die PDFs ab und zeigt danach die Liste der fakturierten Dokumente — jedes PDF einzeln, <strong>Alle als ein PDF</strong>, <strong>Alle als ZIP</strong> oder <strong>Alle per E-Mail senden …</strong> an die E-Mail-Adresse der Rechnungsadresse (ohne Adresse übersprungen). Ein Entwurf, der seit dem Anzeigen der Liste geändert wurde, wird nicht fakturiert. Verbucht werden die fakturierten Dokumente erst im <strong>Faktura-Journal</strong> mit «Journal verbuchen …».</p>
<h4>Suchen und sortieren</h4>
<p>Ein Spaltentitel sortiert, die Lupe daneben sucht in der Spalte; ein Klick auf eine Zelle öffnet die Suche ihrer Spalte. Ein Datum lässt sich als Tag, Monat (<code>06.2026</code>) oder Jahr suchen. <strong>Zurücksetzen</strong> hebt Suche und Sortierung auf, der Reiter bleibt.</p>
