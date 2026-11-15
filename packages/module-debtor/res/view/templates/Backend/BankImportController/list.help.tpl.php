<?php
/**
 * Help for «Zahlungseingänge» (ADR-048, HELP-003) — attached by `listAction()` with
 * `toolbar: true`, opened with «Hilfe» at the right end of the toolbar. A quick help: reading
 * camt.054 (XML or a ZIP of them), the tabs «Offen» · «Erledigt», the counts, the step to «Verbuchen» on the message (which has
 * its own help, `detail.help`). The whole workflow: `Guide/bankImport.help`.
 */
?>
<h3>Zahlungseingänge</h3>
<p>Hier kommen die Zahlungen der Kunden von der Bank ins System: die <strong>camt.054</strong>-Datei aus dem E-Banking einlesen, die Gutschriften den Rechnungen zuordnen und verbuchen.</p>
<h4>camt.054-Datei einlesen</h4>
<p><strong>camt.054 Upload</strong> in der Aktionszelle öffnet die Dateiauswahl; die Dateien lassen sich auch irgendwo auf die Arbeitsfläche ziehen. XML oder ZIP, bis 5 MB je Datei, mehrere auf einmal. Aus einer ZIP-Datei der Bank wird jede XML-Datei darin eingelesen. Jede Datei bekommt eine Zeile mit dem Ergebnis. Eine Meldung, die schon eingelesen ist, wird abgewiesen — in einer ZIP-Datei still übersprungen («bereits vorhanden», nur Bekanntes: «keine neue Meldung»). Das Konto der Meldung muss ein aktives Zahlungsziel sein (Stammdaten › Auftragswesen › Zahlungsziele). Beim Einlesen wird jede Gutschrift über die QR-Referenz oder die Mitteilung einer verbuchten Rechnung zugeordnet — <strong>verbucht wird dabei nichts</strong>.</p>
<h4>Meldungen prüfen und verbuchen</h4>
<p>Die Liste zeigt die Meldungen, die neueste zuerst, mit der Anzahl Transaktionen je Status: <strong>offen</strong> (nicht zugeordnet), <strong>zugeordnet</strong>, <strong>verbucht</strong>, <strong>ignoriert</strong>.</p>
<p>Zwei Register in der Werkzeugleiste: <strong>Offen</strong> (beim Öffnen) zeigt die Meldungen, in denen noch etwas zuzuordnen oder zu verbuchen ist. Ist jede Transaktion verbucht oder ignoriert, steht die Meldung unter <strong>Erledigt</strong> — dort lässt sie sich weiterhin öffnen.</p>
<p>Ein Klick auf die Meldung öffnet sie. Dort lässt sich eine offene Transaktion mit der Rechnungsnummer <strong>zuordnen</strong> oder <strong>ignorieren</strong> (und zurücknehmen). Erst <strong>Verbuchen</strong> legt je zugeordnete Transaktion die Zahlung auf der Rechnung an — solange etwas zugeordnet und nicht verbucht ist, steht oben «… zugeordnet · noch nicht verbucht».</p>
