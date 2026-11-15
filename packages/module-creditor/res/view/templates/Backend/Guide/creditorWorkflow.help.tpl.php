<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — supplier invoices across the screens: the creditor
 * (contact role «Kreditor», master data «Kreditoren»), the Kreditorenjournal (upload, check, lines,
 * duplicates, delete) and what is not built yet (posting with the payment date: stage 1c;
 * automatic recognition: stage 2). Not attached to a screen: `HelpKnowledge` indexes every
 * `*.help.tpl.php` under the module's template root. Static text only — no controller variables.
 */
?>
<h3>Lieferantenrechnungen: Kreditoren und Kreditorenjournal</h3>
<p>Wie eine Rechnung eines Lieferanten als PDF in die Buchhaltung kommt: hochladen, im Kreditorenjournal prüfen und auf Konten aufteilen — und was dafür einmal eingerichtet wird.</p>

<h4>1. Einrichten</h4>
<ul>
    <li><strong>Zahlungsarten</strong> (Stammdaten › Finanzen › Zahlungsarten): die Auswahl «Bezahlt mit», z.B. Bank. Jede liefert das Haben-Konto.</li>
    <li><strong>Kreditor</strong>: im Kontakt (Kontakte › Kontakt › Rollen) den Schalter «Kreditor» einschalten. Unter Stammdaten › Finanzen › Kreditoren trägst du UID, IBAN und die Standardwerte ein (Konto, MWST, «Bezahlt mit»). Die Standardwerte kannst du auch leer lassen — die erste vollständige Rechnung setzt sie.</li>
</ul>

<h4>2. Rechnung einlesen</h4>
<p>Im Kreditorenjournal (Finanzen › Kreditorenjournal) «PDF hochladen» oder die PDFs auf die Seite ziehen. Jede Datei wird unverändert in den Dokumenten abgelegt (Kreditorenrechnungen / Geschäftsjahr / Monat) und ergibt eine Zeile «zu prüfen». Dieselbe Datei wird nie zweimal eingelesen.</p>

<h4>3. Prüfen und kontieren</h4>
<p>Die Zeile öffnen: links das PDF, rechts die Felder. Kreditor wählen (das setzt seine Standardwerte), Rechnungs-Nr., Datum, Referenz und Betrag eintragen. Mit «+ Zeile» teilst du den Betrag auf mehrere Konten oder MWST-Codes auf — die Rechnung bleibt eine Rechnung. Ist der Mandant nicht MWST-pflichtig, ist jede Zeile «ohne MWST».</p>

<h4>4. Mögliche Doppel</h4>
<p>Hat derselbe Kreditor schon eine Rechnung mit derselben Nummer, oder gibt es schon eine Rechnung mit derselben IBAN und Referenz (oft eine Mahnung), wird die neue gesperrt (Tab «Gesperrt»). Du entscheidest: «Kein Doppel — übernehmen» oder «Verwerfen …». Verwerfen und Löschen behalten das PDF in den Dokumenten.</p>

<h4>5. Was noch folgt</h4>
<ul>
    <li><strong>Buchen: folgt.</strong> «Bezahlt am …» und «Journal verbuchen …» bringen die bezahlten Rechnungen mit dem Zahlungsdatum in die Buchhaltung. Bis dahin steht nichts davon im Journal der Buchhaltung. Eine Rechnung vom Dezember, die im Januar bezahlt wird, ist dann Aufwand des neuen Jahres.</li>
    <li><strong>Automatische Erkennung: folgt.</strong> Kreditor, Betrag, IBAN und Referenz aus dem QR-Einzahlungsschein lesen, und ein Ordner auf dem Server, aus dem Rechnungen von selbst eingelesen werden. Bis dahin trägst du die Werte im Fenster ein.</li>
</ul>
