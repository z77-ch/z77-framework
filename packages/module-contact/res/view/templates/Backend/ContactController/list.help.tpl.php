<?php
/**
 * Help for Kontakte (ADR-048, HELP-003) — attached by `listAction()` with `toolbar: true`, so
 * «Hilfe» stands at the right end of the toolbar. The contact screens open their forms as modal
 * dialogs, which carry no help of their own — this one help therefore explains the list, the
 * contact dialog, the addresses (incl. «Briefanrede») and the roles another module registers
 * (`contactRoles`) — generically: contact knows no module (contact.md rules), so no role is
 * named here; the debtor role is explained by module-order's `Backend/Guide/newCustomer.help`.
 * Static text.
 */
?>
<h3>Kontakte</h3>
<p>Kunden, Geschäftspartner und Personen erfassen: Kontakt anlegen, Adresse hinzufügen oder ändern, Briefanrede für E-Mails festlegen, eine Rolle festlegen, Kontakt deaktivieren, Kontakt suchen.</p>
<p>Ein <strong>Kontakt</strong> ist eine Person oder eine Organisation mit beliebig vielen <strong>Adressen</strong>, jede mit einem Typ (Hauptadresse, Rechnungsadresse, Lieferadresse …). Es gibt keinen eigenen «Kunden» neben dem Kontakt: ein Beleg nimmt sich die Adresse, die er braucht, und speichert davon eine <strong>Kopie</strong> — eine spätere Änderung am Kontakt ändert keinen bestehenden Beleg.</p>

<h4>Liste</h4>
<p><strong>+ Kontakt</strong> öffnet «Kontakt anlegen». Das Suchfeld oben sucht in Firma, Name, Vorname und E-Mail des Kontakts. Angezeigt werden höchstens 200 Kontakte (Vorgabe der Installation) («n von m angezeigt») — die Suche grenzt ein. Jede Zeile zeigt Name, Art, E-Mail, Sprache und die Adressen; «keine Adresse» heisst: an diesen Kontakt kann noch kein Beleg gehen. Neu angelegte Kontakte stehen zuerst oben, an ihrem Platz nach dem nächsten Laden.</p>
<p>Der Schalter <strong>Aktiv</strong> deaktiviert einen Kontakt: er wird für neue Belege nicht mehr angeboten, bestehende Aufträge und Rechnungen behalten ihn. <strong>Gelöscht wird ein Kontakt nie.</strong></p>
<p><strong>⋮</strong> öffnet die Aktionen eines Kontakts: <strong>Bearbeiten</strong>, <strong>Adresse hinzufügen …</strong> und seine Adressen, jede mit <strong>Bearbeiten</strong> und <strong>Entfernen</strong>.</p>

<h4>Kontakt anlegen und bearbeiten</h4>
<ul>
    <li><strong>Art</strong> — Person oder Organisation. Eine Organisation braucht die <strong>Firma</strong>, eine Person den <strong>Namen</strong>; das andere ist freiwillig (z.B. die Kontaktperson einer Firma).</li>
    <li><strong>Sprache</strong> — in dieser Sprache gehen Belege und E-Mails an den Kontakt.</li>
    <li><strong>E-Mail</strong>, <strong>Telefon</strong> — die des Kontakts selbst (Suche, Übersicht). Belege gehen an die E-Mail-Adresse der Rechnungsadresse, nicht an diese.</li>
    <li><strong>Erste Adresse</strong> (nur beim Anlegen, freiwillig) — leer lassen für einen Kontakt ohne Adresse; weitere Adressen über ⋮.</li>
</ul>

<h4>Adressen und Briefanrede</h4>
<ul>
    <li><strong>Adresstyp</strong> — wozu die Adresse dient. Ein Beleg nimmt die Rechnungsadresse, sonst die Hauptadresse; eine andere Adresse (z.B. die Lieferadresse) wählt man im Beleg. Zwei Adressen gleichen Typs unterscheidet die <strong>Bezeichnung</strong> (z.B. «Lager Ost»). Die Typen stehen unter Stammdaten › Kontakte › Adresstypen.</li>
    <li><strong>Name</strong>, <strong>Strasse</strong>, <strong>PLZ</strong>, <strong>Ort</strong>, <strong>Land</strong> sind Pflicht; das Land als Kürzel (CH, DE, …), in der Schweiz eine vierstellige PLZ.</li>
    <li><strong>Briefanrede</strong> — wie ein E-Mail an diese Adresse grüsst, z.B. «Sehr geehrter Herr Muster» oder «Liebe Anna». Leer: «Guten Tag» mit Anrede und Name («Guten Tag Herr Muster»), ohne Name nur «Guten Tag».</li>
    <li><strong>E-Mail</strong> und <strong>Telefon</strong> der Adresse — an diese E-Mail-Adresse gehen die Belege (Offerte, Rechnung …), wenn die Adresse im Beleg die Rechnungsadresse ist.</li>
</ul>
<p>Eine geänderte Adresse gilt für neue Belege; Belege, die sie schon verwenden, behalten ihre Kopie. <strong>Entfernen</strong> nimmt die Adresse vom Kontakt weg, ebenfalls ohne Folgen für bestehende Belege.</p>

<h4>Rollen</h4>
<p>Der Abschnitt <strong>Rollen</strong> im Kontakt-Dialog zeigt, was der Kontakt für andere Bereiche ist, je Rolle ein Schalter mit einer kurzen Zusammenfassung — welche Rollen es gibt, hängt von der Installation ab; ohne Rolle fehlt der Abschnitt. Beim <strong>Bearbeiten</strong> wirkt ein Schalter <strong>sofort</strong>, «Speichern» betrifft nur die Felder; beim <strong>Anlegen</strong> wird die Rolle erst nach dem Speichern des Kontakts gesetzt — lehnt sie ab, ist der Kontakt trotzdem gespeichert und die Meldung sagt warum. Löscht das Ausschalten etwas, fragt der Dialog zuerst nach. Ein ausgegrauter Schalter nennt seinen Grund. Der Link neben einer Rolle öffnet ihre eigenen Einstellungen.</p>
<p><strong>Noch nicht verfügbar:</strong> der Newsletter (Adresstyp «Newsletter») — folgt. Eine Liste mit mehr als 200 Kontakten blättert noch nicht.</p>
