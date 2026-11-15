<?php
/**
 * Help for the list «Kreditoren» under Stammdaten › Finanzen (ADR-048, HELP-003) — attached by
 * `listAction()` with `toolbar: true`. What the list is for, how a contact becomes a creditor, the
 * search and the switch; the fields are explained in the window's help (`edit.help`). Also the
 * KI-Assistent's knowledge (ADR-055). Static text.
 */
?>
<h3>Kreditoren</h3>
<p>Deine Lieferanten als Kreditoren: wer welche IBAN hat, welche UID, und auf welches Konto mit welcher MWST eine Rechnung von ihm gebucht wird. Hier findest und bearbeitest du sie.</p>

<h4>Kreditor anlegen</h4>
<p><strong>Kreditor wird ein Kontakt</strong> im Kontakt selbst: Kontakte › Kontakte, Kontakt bearbeiten, Abschnitt «Rollen», Schalter <strong>«Kreditor»</strong>. Adresse, Sprache und E-Mail stehen beim Kontakt; ein Lieferant, der auch Kunde ist, bleibt ein einziger Kontakt. Darum ist das Feld oben links leer — hier gibt es kein «+ Kreditor». Schaltest du die Rolle im Kontakt wieder aus, wird der Kreditor mit seinen IBAN gelöscht.</p>

<h4>Liste und Suche</h4>
<ul>
    <li>Die <strong>Lupe</strong> über den Spalten Kreditor, UID und IBAN sucht in der Datenbank — bei der IBAN genügt ein Teil, Leerzeichen spielen keine Rolle.</li>
    <li>Das Zeichen am Zeilenanfang öffnet den Kreditor zum Bearbeiten: UID, IBAN und Standardwerte.</li>
    <li><strong>Konto</strong>, <strong>MWST</strong> und <strong>Bezahlt mit</strong> sind die Standardwerte für neue Rechnungen dieses Kreditors; «—» heisst: noch nicht festgelegt.</li>
    <li><strong>Aktiv</strong> — ein inaktiver Kreditor wird für neue Rechnungen nicht mehr angeboten; bestehende behalten ihn.</li>
</ul>

<h4>Was noch kommt</h4>
<p>Das Kreditorenjournal (Rechnungen als PDF hochladen, «bezahlt am» setzen, «Journal verbuchen …») folgt in der nächsten Ausbaustufe; bis dahin pflegst du hier die Kreditoren.</p>
