<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): a new customer across the screens — contact,
 * addresses, «Briefanrede», the debtor role (debtor's `DebtorContactRole` on contact's role
 * registry), the first order. Lives in module-order because order requires contact and debtor
 * (contact itself names no module — contact.md rules). Not attached to a screen: the help index
 * finds every `*.help.tpl.php` under the module's templates. Static text.
 */
?>
<h3>Neuen Kunden erfassen</h3>
<p>Wie ein neuer Kunde entsteht: Kontakt anlegen, Adressen und Briefanrede erfassen, Kontakt zum Debitor machen (Kundennummer, Zahlungskonditionen, Preise netto oder brutto), dann der erste Auftrag oder die erste Rechnung.</p>

<h4>1. Kontakt mit Adresse</h4>
<p>Kontakte › <strong>+ Kontakt</strong>: Art (Person oder Organisation), Firma bzw. Name, Sprache, dazu gleich die erste Adresse. Weitere Adressen (Rechnungsadresse, Lieferadresse …) über <strong>⋮ › Adresse hinzufügen …</strong>. Die Rechnung geht an die <strong>Rechnungsadresse</strong>, sonst an die Hauptadresse — und Offerte, Auftragsbestätigung, Lieferschein und Rechnung per E-Mail an die <strong>E-Mail der Rechnungsadresse</strong>, nicht an die E-Mail des Kontakts. Die <strong>Briefanrede</strong> der Adresse («Sehr geehrte Frau Muster») ist die Anrede dieser E-Mails; leer heisst es «Guten Tag» mit Anrede und Name.</p>

<h4>2. Debitor festlegen</h4>
<p>Ein Kontakt wird erst <strong>Kunde</strong>, wenn er <strong>Debitor</strong> ist. Drei Wege, alle gleich:</p>
<ul>
    <li>im Kontakt-Dialog unter <strong>Rollen</strong> den Schalter <strong>Debitor</strong> einschalten (beim Anlegen wird er nach dem Speichern gesetzt);</li>
    <li>im Auftrag oder in der Rechnung bei <strong>Kunde wählen …</strong> einen Kontakt ohne Debitor wählen — <strong>Übernehmen</strong> fragt zuerst <strong>Debitor anlegen</strong>;</li>
    <li>dort <strong>+ Neuer Kontakt</strong> — nach dem Speichern fragt das Fenster nach dem Debitor.</li>
</ul>
<p>Der Debitor erhält die nächste <strong>Kundennummer</strong> und die Standard-Zahlungskonditionen (Stammdaten › Auftragswesen › Zahlungskonditionen; ohne aktive Standard-Kondition geht es nicht). <strong>Preise netto / brutto</strong> und die Konditionen lassen sich mit <strong>Debitor bearbeiten …</strong> (im Kontakt) bzw. <strong>Debitor anpassen</strong> (im Auftrag) ändern; ein neuer Auftrag übernimmt beides. Ausschalten löscht das Debitorenprofil nach einer Rückfrage — die Kundennummer wird nicht wieder vergeben; hat der Debitor schon Belege, geht das nicht mehr. Ein inaktiver Kontakt wird nicht Debitor.</p>

<h4>3. Der erste Auftrag</h4>
<p>Auftragswesen › Aufträge › <strong>+ Neuer Auftrag</strong>, Kunde wählen, Positionen erfassen — weiter wie unter «Ablauf eines Auftrags». Im Auftrag lassen sich Rechnungs- und Lieferadresse wählen oder für diesen Auftrag <strong>anpassen</strong>; der Auftrag speichert eine Kopie, eine spätere Änderung am Kontakt ändert ihn nicht.</p>
<p>Kontakte werden nie gelöscht, nur <strong>deaktiviert</strong> (Schalter «Aktiv» in der Kontaktliste): ein inaktiver Kontakt wird für neue Belege nicht mehr angeboten, bestehende behalten ihn.</p>
