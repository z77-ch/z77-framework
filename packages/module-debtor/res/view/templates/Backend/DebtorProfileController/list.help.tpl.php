<?php
/**
 * Help for the debtor master data «Debitoren» under Stammdaten (ADR-048, HELP-003) — attached by
 * `listAction()` with `toolbar: true`, opened with «Hilfe» at the right end of the toolbar. The
 * list, the switch, the dialog «Debitor bearbeiten» (a modal: its fields are explained here, one
 * section per field name) and the account settings above the list. Static text.
 */
?>
<h3>Debitoren — Stammdaten</h3>
<p>Die Kunden als Debitoren: welche Zahlungskonditionen, welcher Preismodus und welcher Betreffname für neue Rechnungen gelten, eine Mahnsperre setzen, einen Debitor deaktivieren — und ob die Konten der Debitorenbuchhaltung stimmen.</p>
<p>Die Liste zeigt die <strong>Kontakte</strong> (die Suche oben findet nach Name, Firma oder E-Mail): mit Debitor die Kundennummer, die Zahlungskonditionen und allenfalls «Mahnsperre», ohne Debitor «kein Debitor». <strong>Debitor wird ein Kontakt</strong> im Kontakt selbst (Kontakte › Kontakte, Kontakt bearbeiten, Abschnitt «Rollen», Schalter «Debitor») oder beim ersten Rechnungsentwurf («Debitor anlegen») — dabei bekommt er die nächste <strong>Kundennummer</strong> und die als «Standard» markierten Zahlungskonditionen. Adresse, Sprache und E-Mail stehen beim Kontakt.</p>
<ul>
    <li><strong>Schalter</strong> — aktiv: wird für neue Rechnungen und Aufträge angeboten und gemahnt. Inaktiv: bleibt an bestehenden Belegen, wird nicht gemahnt. Gelöscht wird ein Debitor mit Belegen nie; ohne Belege entfernt ihn der Kontakt (Rolle «Debitor» aus).</li>
    <li><strong>⋮</strong> — <strong>Debitor bearbeiten</strong>: Zahlungskonditionen, Preise, Betreffname, Mahnsperre. Eine Änderung gilt für neue Belege (ein Entwurf mit «wie beim Debitor» beim nächsten Speichern); fakturierte Rechnungen behalten ihre Konditionen.</li>
</ul>
<p><strong>Konteneinstellungen</strong> oben: die Konten, auf die Forderungen, Skonto, Verlust, Rundung und Mahngebühren gebucht werden, je mit «ok» oder «prüfen» und dem Grund. Geändert werden sie beim Mandanten (Stammdaten › Firma › Mandant, Reiter «Konten»), nicht hier.</p>

<section data-help-field="q">
<h3>Suche</h3>
<p>Ein Teil von Name, Firma oder E-Mail; Enter sucht. Gezeigt wird eine begrenzte Anzahl — «… von … angezeigt» heisst: die Suche eingrenzen.</p>
</section>

<section data-help-field="payment_terms_code">
<h3>Zahlungskonditionen</h3>
<p>Zahlungsfrist und Skonto, mit denen jede neue Rechnung dieses Kunden beginnt («wie beim Debitor»); in der Rechnung für das eine Dokument änderbar. Angeboten werden die aktiven Konditionen (Stammdaten › Auftragswesen › Zahlungskonditionen).</p>
</section>

<section data-help-field="price_mode">
<h3>Preise netto / brutto</h3>
<p>Ob die Preise in neuen Aufträgen und Rechnungen dieses Kunden ohne MWST (netto) oder mit MWST (brutto) erfasst werden — z.B. brutto für Privatkunden. Im Beleg änderbar.</p>
</section>

<section data-help-field="default_subject_label">
<h3>Betreffname</h3>
<p>Das Wort vor dem Betreff jedes neuen Belegs, z.B. «Projekt» oder «Ihre Bestellnummer» (höchstens 80 Zeichen). Leer = die Vorgabe beim Mandanten.</p>
</section>

<section data-help-field="dunning_block">
<h3>Mahnsperre</h3>
<p>Eingeschaltet erscheinen die Rechnungen dieses Debitors nie in den fälligen Mahnungen — z.B. bei einer Abmachung oder einem Streitfall. Die offenen Posten zeigen sie weiter.</p>
</section>
