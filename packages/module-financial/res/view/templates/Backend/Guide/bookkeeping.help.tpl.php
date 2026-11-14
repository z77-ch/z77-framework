<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — the bookkeeping across the financial screens:
 * chart of accounts → fiscal year → journal (manual and generated entries) → reports → year
 * opening → year close, plus what is NOT built yet (the VAT return). Not attached to a screen:
 * `HelpKnowledge` indexes every `*.help.tpl.php` under the module's template root. Static text
 * only — no controller variables.
 */
?>
<h3>Buchhaltung: Kontenplan, Journal, Geschäftsjahr, Abschluss</h3>
<p>Wie die Buchhaltung aufgebaut ist und auf welchem Bildschirm was passiert: Konten anlegen, ein Geschäftsjahr eröffnen, buchen, eine Buchung korrigieren, Bilanz und Erfolgsrechnung ansehen, das neue Jahr eröffnen und ein Jahr abschliessen — und was es noch nicht gibt (MWST-Abrechnung).</p>

<h4>1. Einrichten: Kontenplan, Mandant, Geschäftsjahr</h4>
<p><strong>Stammdaten › Finanzen › Kontenplan</strong>: bei einem leeren Kontenplan «KMU-Kontenrahmen übernehmen …», danach Konten ergänzen oder deaktivieren (nie löschen). <strong>Stammdaten › Firma › Mandant</strong>, Reiter «Konten»: die Konten für Debitoren, MWST und die Eröffnung. <strong>Stammdaten › Finanzen › Geschäftsjahre</strong>: «+ Geschäftsjahr» — ohne eröffnetes Geschäftsjahr wird keine Buchung angenommen. Die MWST-Codes stehen unter Stammdaten › Finanzen › MWST-Codes.</p>

<h4>2. Buchen von Hand — das Journal</h4>
<p><strong>Finanzen › Journal</strong>: oben das Formular, darunter die Buchungen. Die <strong>Einzelbuchung</strong> ist eine Zeile «Soll an Haben, Betrag» — mit dem Schalter MwSt rechnet das System die Steuer aus dem Bruttobetrag heraus und bucht die Steuerzeile selbst. Für mehr als zwei Konten (z.B. eine Lohnzahlung) «Sammel»: beliebig viele Zeilen, gebucht wird, wenn Soll = Haben. «Buchen» vergibt die nächste Nummer des Geschäftsjahres (lückenlos, z.B. 2026/17).</p>
<p>Eine Buchung von Hand lässt sich bearbeiten oder löschen, solange ihr Geschäftsjahr offen ist; jede Änderung steht im <strong>Änderungsprotokoll</strong> (Finanzen › Änderungsprotokoll), eine gelöschte Nummer bleibt als Lücke. Auf Sammelkonten mit «nur Modul» (z.B. 1100 Debitoren) wird von Hand nicht gebucht.</p>

<h4>3. Buchungen der Module</h4>
<p>Rechnungen und Gutschriften (Faktura-Journal), Zahlungseingänge, Mahngebühren, Belege vom Handy (Belegjournal) und die Eröffnung buchen <strong>automatisch</strong> — im Journal mit dem Blitz-Zeichen. Solche Buchungen ändert, löscht oder storniert nur das Modul, das sie gebucht hat: eine falsche Rechnung wird in der Debitorenbuchhaltung korrigiert (Gutschrift), nicht im Journal. Was ein Modul noch nicht verbucht hat (Faktura-Journal, Belegjournal), fehlt in den Auswertungen; ein Hinweis über der Auswertung nennt es.</p>

<h4>4. Auswertungen</h4>
<p><strong>Finanzen › Auswertungen</strong>: Saldobilanz, Bilanz (an einem Stichtag), Erfolgsrechnung, Kontoblatt eines Kontos und Journal — immer für ein Geschäftsjahr, mit Zeitraum (Jahr, Halbjahr, Quartal, Monat), am Bildschirm oder als PDF. Bilanz und Erfolgsrechnung zeigen die zwei Vorjahre daneben.</p>

<h4>5. Neues Jahr: Eröffnung</h4>
<p>Das neue Geschäftsjahr eröffnen («+ Geschäftsjahr»), dann im ⋮ des neuen Jahres <strong>«Eröffnung erstellen …»</strong>: die Schlusssalden des Vorjahres werden pro Bilanzkonto gegen 9100 Eröffnungsbilanz vorgetragen, der Gewinn oder Verlust auf 2979. Ändert sich das Vorjahr später noch, «Eröffnung neu erstellen …». Die Gewinnverwendung (Reserven, Ausschüttung) wird von Hand gebucht.</p>

<h4>6. Jahresabschluss</h4>
<p>Im ⋮ des ältesten offenen Jahres <strong>«Jahr abschliessen …»</strong>. Gesperrt ist der Abschluss, solange Rechnungen des Jahres nicht verbucht sind (Entwurf oder Faktura-Journal), Zahlungseingänge oder Belege im Belegjournal warten. Warnungen (z.B. 1100 stimmt nicht mit den offenen Posten überein) werden mit einem Häkchen bestätigt. Danach nimmt das Jahr keine Buchung mehr an. Ein Administrator kann das zuletzt abgeschlossene Jahr mit Begründung wieder öffnen.</p>

<h4>Noch nicht verfügbar</h4>
<ul>
    <li><strong>MWST-Abrechnung</strong> — die Abrechnung für die ESTV pro Quartal oder Semester (Umsatz und Steuer pro Code, Abrechnungsbuchung, Sperre der abgerechneten Monate) — folgt. Bis dahin: Kontoblätter der MWST-Konten (1170, 1171, 2200) und das Journal mit der Spalte MWST.</li>
    <li><strong>Kreditoren</strong> (Lieferantenrechnungen mit offenen Posten) — gibt es nicht; Einkäufe werden im Journal oder als Beleg im Belegjournal gebucht.</li>
    <li><strong>Fremdwährungen</strong> — gebucht wird nur in der Basiswährung (CHF).</li>
</ul>
