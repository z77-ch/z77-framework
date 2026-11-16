<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — supplier invoices across the screens: the creditor
 * (contact role «Kreditor», master data «Kreditoren»), the Kreditorenjournal (upload, check, lines,
 * duplicates, delete, «Bezahlt am …», «Journal verbuchen …», retention — stage 1c), the automatic
 * recognition, the intake folder and the opt-in AI (stage 2), and what is not built yet (the
 * collective-account variant). Not attached to a screen: `HelpKnowledge` indexes every
 * `*.help.tpl.php` under the module's template root. Static text only — no controller variables.
 *
 * Written for people (owner 2026-11-16: «die Hilfe ist für menschliches Lesen viel zu
 * kompliziert»): everyday words, short sentences, the steps in the order the user takes them.
 */
?>
<h3>Lieferantenrechnungen: Kreditoren und Kreditorenjournal</h3>
<p>So kommt eine Rechnung eines Lieferanten in die Buchhaltung: PDF hochladen, im Kreditorenjournal prüfen, «bezahlt am» setzen und mit «Journal verbuchen …» buchen.</p>

<h4>1. Einrichten</h4>
<ul>
    <li><strong>Zahlungsarten</strong> (Stammdaten › Finanzen › Zahlungsarten): die Auswahl «Bezahlt mit», z.B. Bank. Jede bringt ihr Konto mit.</li>
    <li><strong>Kreditor</strong>: im Kontakt die Rolle «Kreditor» einschalten. Unter Stammdaten › Finanzen › Kreditoren stehen UID, IBAN und die Standardwerte (Konto, MWST, «Bezahlt mit»). Du kannst sie leer lassen: Das erste Konto, das du auf einer Rechnung einträgst, wird sein Standard.</li>
</ul>

<h4>2. Rechnung einlesen</h4>
<p>Finanzen › Kreditorenjournal: die PDFs auf «PDF hochladen» oder auf die Liste ziehen, oder den Knopf klicken und die Dateien wählen. Rechnungen kommen auch über den <strong>Eingangsordner</strong> auf dem Server herein. Jede Datei wird eine Zeile, das PDF liegt danach in den Dokumenten. Dieselbe Datei wird nie zweimal eingelesen. Was aus dem Eingangsordner nicht gelesen werden kann, steht im Tab «Nicht eingelesen». Dort versuchst du es erneut oder entfernst die Dateien, einzeln oder mehrere auf einmal.</p>
<p><strong>Automatische Erkennung:</strong> Eine neue Rechnung zeigt zuerst «Erkennung ausstehend» und in der Liste «in Arbeit …». Meist nach einer Minute stehen Betrag, IBAN, Referenz und Lieferant darin, oft auch Rechnungsnummer und Datum — nur in leeren Feldern. Ein bekannter Lieferant bringt seine Standardwerte mit, ein neuer erscheint als «Vorschlag». «Jetzt erkennen» in der Rechnung lässt das System nochmals lesen. Scans erfasst du von Hand, ausser die KI-Erkennung ist eingeschaltet (sie schickt dann das PDF an Anthropic in den USA und wählt nie ein Konto).</p>

<h4>3. Prüfen und kontieren</h4>
<p>Die Zeile öffnen: links das PDF, rechts die Felder. Kreditor wählen (das setzt seine Standardwerte), fehlende Angaben ergänzen, speichern. Mit «+ Zeile» teilst du den Betrag auf mehrere Konten oder MWST-Codes auf.</p>
<p><strong>Neuer Lieferant:</strong> «Kreditor übernehmen …» macht einen bestehenden Kontakt zum Kreditor oder legt einen neuen an. Weitere Rechnungen desselben Lieferanten bekommen ihn gleich mit.</p>
<p><strong>Fremde Währung</strong> (z.B. USD 9.00): Währung und Betrag der Rechnung bleiben stehen. Den CHF-Betrag trägst du ein, sobald du ihn von Bank oder Kreditkarte kennst. Gebucht wird in CHF.</p>

<h4>4. Doppel und Löschen</h4>
<p>Gleicher Lieferant und gleiche Rechnungsnummer, oder gleiche IBAN und Referenz (oft eine Mahnung): Die neue Rechnung wird gesperrt (Tab «Gesperrt»). Du entscheidest: «Kein Doppel — übernehmen», «Verwerfen …» oder «Löschen …». <strong>Löschen …</strong> löscht eine nicht verbuchte Rechnung ganz — einzeln im Fenster (auch eine gesperrte), oder mehrere in der Liste ankreuzen (auch gesperrte, das Kästchen im Kopf kreuzt alle an). Das PDF kommt in den Papierkorb, die Datei lässt sich wieder hochladen.</p>

<h4>5. Bezahlt am …</h4>
<p>Bezahlte Rechnungen in der Liste ankreuzen und «Bezahlt am …» klicken: Datum wählen und, wenn nötig, «Bezahlt mit». Einzeln geht es im Feld «Bezahlt am» der Rechnung. Eine gesperrte Rechnung entscheidest du zuerst (kein Doppel oder löschen), erst dann wird sie bezahlt.</p>

<h4>6. Journal verbuchen</h4>
<p>«Journal verbuchen …» (der grüne Knopf oben links) bucht alle Rechnungen, die bis zum Stichtag bezahlt sind — jede mit ihrem Zahlungsdatum. Ist eine davon gesperrt oder unvollständig, wird nichts gebucht, und das Fenster zeigt, was fehlt. Danach ist die Rechnung nicht mehr änderbar, ihr PDF wird bis Ende des Geschäftsjahrs der Zahlung plus 10 Jahre aufbewahrt.</p>
<p><strong>Ist-Prinzip:</strong> Massgebend ist das Zahlungsdatum. Eine Rechnung vom Dezember, die du im Januar bezahlst, ist Aufwand des neuen Jahres. Unbezahlte Rechnungen stehen am Jahresende nicht als Schulden in der Buchhaltung; der Jahresabschluss weist darauf hin.</p>

<h4>7. Was noch folgt</h4>
<ul>
    <li><strong>Sammelkonto (Kreditoren 2000): folgt.</strong> Die Rechnung schon beim Eingang als Schuld buchen und die Zahlung dagegen — bis dahin gilt das Ist-Prinzip.</li>
</ul>
