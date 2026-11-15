<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — supplier invoices across the screens: the creditor
 * (contact role «Kreditor», master data «Kreditoren»), the Kreditorenjournal (upload, check, lines,
 * duplicates, delete, «Bezahlt am …», «Journal verbuchen …», retention — stage 1c), the automatic
 * recognition, the intake folder and the opt-in AI (stage 2), and what is not built yet (the
 * collective-account variant). Not attached to a
 * screen: `HelpKnowledge` indexes every `*.help.tpl.php` under the module's template root. Static
 * text only — no controller variables.
 */
?>
<h3>Lieferantenrechnungen: Kreditoren und Kreditorenjournal</h3>
<p>Wie eine Rechnung eines Lieferanten als PDF in die Buchhaltung kommt: hochladen, im Kreditorenjournal prüfen und kontieren, «bezahlt am» setzen und mit «Journal verbuchen …» buchen.</p>

<h4>1. Einrichten</h4>
<ul>
    <li><strong>Zahlungsarten</strong> (Stammdaten › Finanzen › Zahlungsarten): die Auswahl «Bezahlt mit», z.B. Bank. Jede liefert das Haben-Konto.</li>
    <li><strong>Kreditor</strong>: im Kontakt (Kontakte › Kontakt › Rollen) den Schalter «Kreditor» einschalten. Unter Stammdaten › Finanzen › Kreditoren trägst du UID, IBAN und die Standardwerte ein (Konto, MWST, «Bezahlt mit»). Die Standardwerte kannst du auch leer lassen — die erste vollständige Rechnung setzt sie.</li>
</ul>

<h4>2. Rechnung einlesen</h4>
<p>Im Kreditorenjournal (Finanzen › Kreditorenjournal) «PDF hochladen» in der Werkzeugleiste oder die PDFs auf die Seite ziehen — oder die PDFs in den <strong>Eingangsordner</strong> auf dem Server legen (<code>data/framework/creditor/intake</code>); der Job «Kreditoren-Eingang» liest ihn jede Minute und löscht eine Datei, sobald sie sicher in den Dokumenten liegt. Jede Datei wird unverändert in den Dokumenten abgelegt (Kreditorenrechnungen / Geschäftsjahr / Monat) und ergibt eine Zeile. Dieselbe Datei wird nie zweimal eingelesen. Was aus dem Ordner nicht eingelesen werden kann, steht im Tab «Nicht eingelesen» mit dem Grund («Erneut versuchen» oder «Entfernen»).</p>
<p><strong>Automatische Erkennung:</strong> eine neue Rechnung steht zuerst mit «Erkennung ausstehend» im Journal; erkannt wird im Hintergrund im Job «Kreditoren-Eingang», meist innert einer Minute. Solange das läuft, zeigt die Spalte «Erkannt» «in Arbeit …» («KI liest …», während die KI liest), und die Liste aktualisiert sich selbst. Aus dem QR-Zahlteil im Text des PDF kommen IBAN, Betrag, Referenz, Mitteilung (sie wird der Text der Zeile) und der Lieferant, mit Swico-Zeile auch Rechnungs-Nr. und Daten — sonst aus dem Briefkopf neben «Rechnungsnummer», «Rechnungsdatum», «Zahlbar bis» — nur in leere Felder. «Jetzt erkennen» im Fenster lässt die Erkennung nochmals laufen. Ein bekannter Kreditor (über seine IBAN, sonst die UID) bringt seine Standardwerte mit; ein unbekannter erscheint als «Vorschlag». Gehört die IBAN mehreren Kreditoren, wählst du. Die Spalte «Erkannt» zeigt Text, KI oder von Hand. Scans und Rechnungen ohne QR-Zahlteil erfasst du von Hand — oder die KI liest sie, wenn sie für diese Installation eingeschaltet ist (sie schickt dann das PDF an Anthropic in den USA und rät nie ein Konto).</p>

<h4>3. Prüfen und kontieren</h4>
<p>Die Zeile öffnen: links das PDF, rechts die Felder. Kreditor wählen (das setzt seine Standardwerte), Rechnungs-Nr., Datum, Referenz und Betrag eintragen. Mit «+ Zeile» teilst du den Betrag auf mehrere Konten oder MWST-Codes auf — die Rechnung bleibt eine Rechnung. Ist der Mandant nicht MWST-pflichtig, ist jede Zeile «ohne MWST».</p>

<h4>4. Mögliche Doppel</h4>
<p>Hat derselbe Kreditor schon eine Rechnung mit derselben Nummer, oder gibt es schon eine Rechnung mit derselben IBAN und Referenz (oft eine Mahnung), wird die neue gesperrt (Tab «Gesperrt»). Du entscheidest: «Kein Doppel — übernehmen» oder «Verwerfen …». Verwerfen behält das PDF in den Dokumenten, und dieselbe Datei bleibt gesperrt. <strong>Löschen …</strong> einer nicht verbuchten Rechnung löscht sie ganz — das PDF kommt in den Papierkorb, und du kannst dieselbe Datei wieder hochladen.</p>

<h4>5. Bezahlt am …</h4>
<p>Hast du Rechnungen bezahlt, kreuzt du sie in «Im Journal» an und klickst «Bezahlt am …» über der Liste: Zahlungsdatum (nie in der Zukunft) und, wenn nötig, «Bezahlt mit» für alle. Einzeln geht es im Fenster der Rechnung im Feld «Bezahlt am». Unbezahlte Rechnungen bleiben einfach im Journal.</p>

<h4>6. Journal verbuchen</h4>
<p>«Journal verbuchen …» (der grüne Knopf oben links) bucht alle Rechnungen, die bis zum Stichtag bezahlt sind — eine Buchung pro Rechnung, datiert mit «bezahlt am»: Aufwand (und Vorsteuer) an das Konto von «Bezahlt mit». Ist eine bezahlte Rechnung gesperrt oder unvollständig, zeigt das Fenster die Fehler, und es wird nichts gebucht. Danach ist die Rechnung unveränderlich, und ihr PDF wird aufbewahrt: bis Ende des Geschäftsjahrs der Zahlung plus 10 Jahre — so lange lässt es sich in den Dokumenten nicht endgültig löschen.</p>
<p><strong>Ist-Prinzip:</strong> gebucht wird mit dem Zahlungsdatum. Eine Rechnung vom Dezember, die du im Januar bezahlst, ist Aufwand des neuen Jahres. Unbezahlte Rechnungen stehen am Jahresende nicht als Schulden in der Buchhaltung; der Jahresabschluss weist darauf hin. Eine bezahlte, noch nicht verbuchte Rechnung verhindert den Abschluss ihrer Periode.</p>

<h4>7. Was noch folgt</h4>
<ul>
    <li><strong>Sammelkonto (Kreditoren 2000): folgt.</strong> Die Rechnung schon beim Eingang als Schuld buchen und die Zahlung dagegen — bis dahin gilt das Ist-Prinzip.</li>
</ul>
