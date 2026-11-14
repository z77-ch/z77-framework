<?php
/**
 * Help of Service › E-Mail (ADR-048; attached by `EmailSettingsController::listAction()` —
 * the page has no list toolbar, so the trigger stands in the top bar). The two sections
 * «Formular-Mails» and «Beleg-Mails», the origin badges, the switch, «Übersteuern» /
 * «Anpassen», the ⋮ items and the fields of both edit dialogs (the dialogs are popups and carry
 * no help of their own). Also prompt material of the KI-Assistent (ADR-055).
 */
?>
<h3>E-Mail-Einstellungen</h3>
<p><strong>Wer die E-Mails eines Formulars bekommt</strong> (Kontaktformular, Alarme) und <strong>welchen Betreff und Text die Beleg-Mails haben</strong> (Rechnung, Gutschrift, Mahnung, Offerte, Auftragsbestätigung, Lieferschein) — selbst ändern, ohne Entwickler.</p>
<p>Nicht hier eingestellt werden das <strong>Mail-Konto</strong>, über das alle E-Mails hinausgehen (Absenderadresse, Server — das richtet der Entwickler in <code>config/client/mail.inc.php</code> ein), und der persönliche <strong>Absender</strong> («Senden als»: Name, E-Mail, Telefon im Abschluss der Beleg-Mails) — der steht bei deinem Benutzer (System › Benutzer › Bearbeiten › «Absender für E-Mails»). Was versendet wurde, zeigt Service › Postausgang.</p>

<h3>Woher ein Wert kommt</h3>
<p>Jede Zeile hat eine Vorgabe aus dem System und kann im Backend übersteuert werden. Das Kennzeichen sagt, was gilt:</p>
<ul>
    <li><strong>Config</strong> bzw. <strong>Modul</strong> — es gilt die Vorgabe des Entwicklers bzw. des Moduls.</li>
    <li><strong>Backend</strong> — es gelten die hier gespeicherten Werte.</li>
    <li><strong>Backend inaktiv</strong> — eigene Werte sind gespeichert, aber ausgeschaltet; es gilt die Vorgabe.</li>
</ul>
<p><strong>Übersteuern</strong> (Formular-Mails) bzw. <strong>Anpassen</strong> (Beleg-Mails) öffnet die Vorgabe zum Ändern; das erste <strong>Speichern</strong> legt die eigenen Werte an, eingeschaltet. Danach hat die Zeile einen <strong>Schalter</strong>: aus = die Vorgabe gilt wieder, deine Werte bleiben gespeichert. <strong>⋮ › Bearbeiten</strong> ändert sie, <strong>⋮ › Zurücksetzen</strong> löscht sie nach einer Bestätigung — dann gilt wieder die Vorgabe. Eine Zeile mit «(nicht mehr in der Config)» oder «(kein Modul sendet diese Art mehr)» ist ein Überbleibsel: sie wirkt nicht mehr und lässt sich nur zurücksetzen.</p>

<h3>Formular-Mails: Empfänger, Kopie, Betreff, Routen</h3>
<p>Eine Zeile pro Formular bzw. Meldung, mit den geltenden Empfängern, dem Betreff und der Anzahl Routen.</p>
<ul>
    <li><strong>Empfänger</strong> — eine Adresse pro Zeile; mindestens eine.</li>
    <li><strong>CC</strong> — optional, eine Adresse pro Zeile, bekommt eine Kopie.</li>
    <li><strong>Betreff</strong> — der Betreff der E-Mail.</li>
    <li><strong>Routen</strong> — optional: hat das Formular eine Auswahl (z.B. «Thema»), schickt eine Route die Anfragen mit diesem Auswahl-Wert an andere Empfänger, wahlweise mit eigenem Betreff. Ohne passende Route gelten Empfänger und Betreff oben. Leere Zeilen werden ignoriert; für mehr Routen speichern und erneut öffnen.</li>
</ul>
<p>Eine <strong>Alarm-Meldung</strong> (z.B. wenn das Backend keine 2FA-Codes annimmt oder eine Adresse das Tageslimit an App-Anmeldecodes erreicht) hat nur Empfänger; ihren Text schreibt das System selbst.</p>

<h3>Beleg-Mails: Betreff, Faktenzeile, Text</h3>
<p>Eine Zeile pro Belegart, die ein Modul per E-Mail sendet (der Abschnitt erscheint nur, wenn es solche gibt). Das Fenster hat einen Block pro Sprache; <strong>Deutsch</strong> ist Pflicht, eine leere andere Sprache nimmt den deutschen Text.</p>
<ul>
    <li><strong>Betreff</strong> — höchstens 150 Zeichen.</li>
    <li><strong>Faktenzeile</strong> — optional, steht fett zuoberst in der E-Mail, z.B. Nummer, Betrag und Fälligkeit. Ein Abschnitt zwischen « · », dessen Platzhalter leer sind, fällt weg.</li>
    <li><strong>Text</strong> — beginnt mit <code>{briefanrede}</code> und bleibt neutral (kein «Sie», kein «du» — die Briefanrede der Adresse bestimmt den Ton); eine Leerzeile beginnt einen neuen Absatz. Gruss und Unterschrift gehören nicht hinein.</li>
    <li><strong>Platzhalter</strong> — unter den Feldern steht, welche diese Belegart kennt, z.B. <code>{nummer}</code> oder <code>{betrag}</code>; sie werden beim Senden ersetzt.</li>
</ul>
<p>Den Abschluss setzt die E-Mail selbst: «Diese Nachricht wurde maschinell erstellt. Fragen beantwortet gerne:» mit Name, E-Mail und Telefon des Absenders («Senden als») und die Firmenadresse. Der Beleg geht als PDF mit. Eine Änderung gilt für E-Mails, die ab jetzt gesendet werden — schon eingereihte behalten ihren Text.</p>

<h3>Noch nicht verfügbar</h3>
<p>Ein <strong>Newsletter</strong> (eine E-Mail an viele Empfänger) ist noch nicht gebaut — folgt.</p>
