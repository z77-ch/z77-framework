<?php
/**
 * Help for «Zahlungseingänge» (ADR-048, HELP-003) — attached by `listAction()` with
 * `toolbar: true`, opened with «Hilfe» at the right end of the toolbar (the message detail has
 * none). A quick help: reading camt.054, the counts, the step to «Verbuchen».
 */
?>
<h3>Zahlungseingänge</h3>
<p>Hier werden die Gutschriften der Bank als <strong>camt.054</strong>-Meldung eingelesen und den Rechnungen zugeordnet.</p>
<p><strong>Einlesen:</strong> <strong>camt.054 Upload</strong> in der Aktionszelle öffnet die Dateiauswahl; die Dateien lassen sich auch irgendwo auf die Arbeitsfläche ziehen. XML oder ZIP, bis 5 MB je Datei, mehrere auf einmal. Aus einer ZIP-Datei (z.B. von der LUKB) wird jede XML-Datei darin eingelesen. Eine Meldung, die schon eingelesen ist, wird abgewiesen — in einer ZIP-Datei still übersprungen («bereits vorhanden»). Beim Einlesen wird jede Gutschrift über die QR-Referenz oder die Mitteilung einer verbuchten Rechnung zugeordnet — <strong>verbucht wird dabei nichts</strong>.</p>
<p>Die Liste zeigt die Meldungen, die neueste zuerst, mit der Anzahl Transaktionen je Stand: <strong>offen</strong> (nicht zugeordnet), <strong>zugeordnet</strong>, <strong>verbucht</strong>, <strong>ignoriert</strong>.</p>
<p>Ein Klick auf die Meldung öffnet sie. Dort lässt sich eine offene Transaktion mit der Rechnungsnummer <strong>zuordnen</strong> oder <strong>ignorieren</strong> (und zurücknehmen). Erst <strong>Verbuchen</strong> legt je zugeordnete Transaktion die Zahlung auf der Rechnung an — solange etwas zugeordnet und nicht verbucht ist, steht oben «… zugeordnet · noch nicht verbucht».</p>
