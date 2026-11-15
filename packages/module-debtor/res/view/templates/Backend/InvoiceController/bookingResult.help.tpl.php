<?php
/**
 * Help for the result list after «Fakturieren …» (ADR-048) — attached by `bookingResultAction()`,
 * opened with «Hilfe» in the top bar. The documents just issued: PDF, ZIP, e-mail run, the band,
 * the row ⋮, the next step (the Faktura-Journal). Also module-order's landing after its
 * «Fakturieren …». Static text: no controller variables.
 */
?>
<h3>Fakturiert — Rechnungen drucken und versenden</h3>
<p>Die Dokumente, die eben fakturiert wurden: die Rechnungen mit Nummer und QR-Zahlteil drucken, als ZIP herunterladen oder per E-Mail an die Kunden senden — und danach im Faktura-Journal verbuchen.</p>
<h4>Drucken, herunterladen, per E-Mail senden</h4>
<ul>
    <li><strong>Alle per E-Mail senden …</strong> — eine Bestätigung zeigt, wer eine E-Mail bekommt (an die E-Mail-Adresse der Rechnungsadresse), wer ohne Adresse übersprungen wird und mit welchem Absender («Senden als»). Die E-Mails gehen über den Postausgang, einige pro Minute. Schon gesendete oder wartende werden nicht nochmals gesendet; ist alles gesendet, verschwindet der Knopf. Nur mit dem Modul «Mailing».</li>
    <li><strong>Alle als ein PDF</strong> — alle Dokumente in einer Datei zum Drucken, jedes mit eigenen Seiten und eigenem Zahlteil.</li>
    <li><strong>Alle als ZIP</strong> — jedes PDF als eigene Datei, zusammen in einer ZIP-Datei.</li>
    <li><strong>Zum Faktura-Journal</strong> — der nächste Schritt: dort werden die Dokumente geprüft und mit «Journal verbuchen» in die Buchhaltung gebucht.</li>
</ul>
<h4>Ein einzelnes Dokument</h4>
<p>Je Zeile: das Dokument (ein Klick öffnet es), Datum, Kunde, Betrag (Gutschriften mit «−»), die <strong>E-Mail</strong>-Adresse auf dem Dokument (oder «keine E-Mail-Adresse») und <strong>Versand</strong> — was zuletzt per E-Mail ging. Das <strong>⋮</strong> der Zeile: <strong>PDF öffnen</strong> und, sobald seine E-Mail gesendet ist oder im Postausgang wartet, <strong>Erneut senden …</strong> für dieses eine Dokument. Das Band über der Liste sagt, wie weit der Versand ist; <strong>Postausgang ansehen</strong> zeigt die E-Mails dieses Laufs.</p>
<h4>Später nochmals</h4>
<p>Die Liste bleibt über ihre Adresse erreichbar (neu laden, später nochmals). <strong>Zur Liste «Fakturiert»</strong> führt zu den Rechnungen (kam die Liste aus einem Auftrag, führt <strong>Zurück</strong> dorthin); dort lässt sich jedes PDF auch später öffnen und senden. Eine fehlende oder falsche E-Mail-Adresse wird beim Kontakt (Rechnungsadresse) korrigiert — ein fakturiertes Dokument behält aber die Adresse, mit der es fakturiert wurde.</p>
