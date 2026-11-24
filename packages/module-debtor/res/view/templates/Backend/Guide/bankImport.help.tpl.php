<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — reading the bank's camt.054 credit advices
 * (single XML files or a ZIP of them) into «Zahlungseingänge», matching, booking and the special
 * cases (overpayment, partial payment, a replaced invoice, a not yet posted invoice). Not attached
 * to a screen: `HelpKnowledge` indexes every `*.help.tpl.php` under the module's template root.
 * The screens' own helps are `BankImportController/list.help` and `detail.help`.
 */
?>
<h3>Zahlungseingänge einlesen (camt.054, ZIP von der Bank)</h3>
<p>Zahlungen aus dem E-Banking übernehmen: die camt.054-Datei der Bank (oder eine ZIP-Datei) einlesen, prüfen, zuordnen und verbuchen — und was bei Überzahlung, Teilzahlung oder falscher Referenz zu tun ist.</p>

<h4>Voraussetzungen</h4>
<ul>
    <li>Das Bankkonto ist ein <strong>aktives Zahlungsziel</strong> (Stammdaten › Auftragswesen › Zahlungsziele) mit seiner IBAN oder QR-IBAN — eine Meldung zu einem anderen Konto wird abgewiesen.</li>
    <li>Die Rechnungen sind <strong>verbucht</strong> (Faktura-Journal › «Journal verbuchen»). Eine Zahlung zu einer erst fakturierten Rechnung bleibt offen mit «Rechnung noch nicht verbucht».</li>
</ul>

<h4>1. Datei holen</h4>
<p>Im E-Banking die <strong>Gutschriftsanzeigen</strong> als <strong>camt.054</strong> herunterladen — als XML-Datei je Tag oder als ZIP-Datei mit mehreren XML-Dateien.</p>

<h4>2. Einlesen</h4>
<p>Auftragswesen › Zahlungseingänge › <strong>camt.054 Upload</strong> (oder die Dateien auf die Arbeitsfläche ziehen): XML oder ZIP, bis 5 MB je Datei, mehrere auf einmal. Jede Datei bekommt eine Zeile mit dem Ergebnis. Eine Meldung, die schon eingelesen ist, wird abgewiesen; in einer ZIP-Datei wird sie still übersprungen — enthält die ZIP-Datei nur Bekanntes, heisst es «keine neue Meldung». Beim Einlesen wird nichts verbucht: jede Gutschrift wird nur über die <strong>QR-Referenz</strong> oder die <strong>Mitteilung</strong> («Rechnung 26100012») einer Rechnung zugeordnet.</p>

<h4>3. Prüfen und zuordnen</h4>
<p>Die Meldung in der Liste anklicken — das Register <strong>Offen</strong> zeigt die Meldungen, in denen noch etwas zu tun ist; ist alles verbucht oder ignoriert, wechselt die Meldung nach <strong>Erledigt</strong>. Jede Transaktion hat einen Status: <strong>nicht zugeordnet</strong> (der Hinweis sagt warum), <strong>zugeordnet</strong>, <strong>verbucht</strong>, <strong>ignoriert</strong>. Eine offene Transaktion mit der Rechnungsnummer <strong>Zuordnen</strong>, oder <strong>Ignorieren</strong>, wenn sie keine Kundenzahlung ist. Eine Zuordnung nach der Mitteilung ist ein Vorschlag — vor dem Verbuchen prüfen. Belastungen, Rückbuchungen und Beträge von 0.00 sind von selbst ignoriert.</p>

<h4>4. Verbuchen</h4>
<p><strong>Verbuchen</strong> (oben auf der Meldung, solange etwas zugeordnet ist) legt je zugeordnete Transaktion die Zahlung auf der Rechnung an — mit dem Valutadatum, auf das Konto des Zahlungsziels. Alles oder nichts: lehnt die Buchhaltung ab (z.B. Geschäftsjahr abgeschlossen), wird nichts verbucht. Erst danach ist die Rechnung in den Debitoren bezahlt.</p>

<h4>5. Mit dem Kontoauszug abgleichen</h4>
<p>Liest das Bankkonto auch den Kontoauszug (camt.053, Finanzen › Bankkonto, «Kontoauszug einlesen» an), steht dort jede Sammelgutschrift der QR-Zahlungen als eine Bewegung. Das System verbindet sie mit den Zahlungen der camt.054 über den Eintrag der Bank, wenn Betrag und Anzahl stimmen — beim Einlesen der camt.054 oder innert einer Minute nach dem Kontoauszug; die Reihenfolge der Dateien spielt keine Rolle. In der Spalte «Zuordnung» des Bankkontos steht dann «Zahlungseingang · n Zahlungen». Was nicht von selbst geht, zeigt <strong>Bankabgleich …</strong> in der Werkzeugleiste der Zahlungseingänge: Abweichungen (nie zugeordnet), Vorschläge für Zahlungen, die vor dem 17.11.2026 eingelesen wurden (Tag, Betrag und Anzahl — bestätigen mit «Übernehmen»), und «Zuordnung lösen» für einen falsch bestätigten Vorschlag. Verbucht wird dabei nichts.</p>

<h3>Sonderfälle</h3>
<ul>
    <li><strong>Überzahlung</strong>: verbucht wird der offene Betrag; der Rest bezahlt zuerst offene Mahngebühren des Kunden, was dann noch bleibt, steht als «Überzahlung» an der Transaktion — von Hand erledigen (zurückzahlen oder einer anderen Rechnung zuordnen).</li>
    <li><strong>Teilzahlung</strong>: der Rest bleibt offen. Hat der Kunde Skonto abgezogen: die Rechnung öffnen, bei der Zahlung «ändern» und den Abzug als <strong>Skonto</strong> erfassen; hat die Bank des Zahlers Spesen abgezogen, ebenso als <strong>Gebühren</strong> (kürzt Umsatz und MWST nicht).</li>
    <li><strong>Ersetzte Rechnung</strong>: nennt die Referenz eine ersetzte Rechnung, schlägt die Zeile die neue vor — <strong>Zuordnen zu …</strong> bestätigt. Eine gelöschte Rechnung: von Hand zuordnen.</li>
    <li><strong>Bereits bezahlt</strong> oder <strong>Valuta vor dem Rechnungsdatum</strong>: beim Verbuchen wird nichts gebucht, die Transaktion wird wieder offen — von Hand klären.</li>
    <li><strong>Fremdwährung</strong>: eine Gutschrift in einer anderen Währung lässt sich nicht verbuchen und hält das Verbuchen der ganzen Meldung auf — zuerst ignorieren, dann verbuchen.</li>
    <li><strong>Falsch verbucht</strong>: eine verbuchte Transaktion bleibt; die Zahlung wird in der Rechnung geändert oder gelöscht.</li>
</ul>
<p>Was noch offen ist, zeigt die Startseite («Zahlungseingänge nicht verbucht») und das Register «Offen» der Liste je Meldung (Spalten «offen» und «zugeordnet»). <strong>Noch nicht verfügbar:</strong> eine Liste aller offenen Zahlungseingänge über alle Meldungen — bis dahin Meldung für Meldung.</p>
