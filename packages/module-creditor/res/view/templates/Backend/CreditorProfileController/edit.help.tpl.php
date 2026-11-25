<?php
/**
 * Help for the creditor window (ADR-048) — a general part, then a section per field
 * (`data-help-field` = the field's `name`, or its `data-help-key` for the IBAN rows). Also the
 * KI-Assistent's knowledge (ADR-055). Static text.
 */
?>
<h3>Kreditor bearbeiten</h3>
<p>Hier legst du für einen Lieferanten die UID, seine IBAN und die Standardwerte fest — auf welches Konto, mit welcher MWST und womit bezahlt seine Rechnungen gebucht werden.</p>
<p>Name und Adresse stehen beim Kontakt und werden dort geändert. «Speichern» übernimmt alles auf einmal; hat inzwischen jemand anders den Kreditor geändert, wird nichts gespeichert und du lädst die Liste neu.</p>

<h4>Wann die Standardwerte gelten</h4>
<p>Solange keine Standardwerte festgelegt sind, übernimmt sie die erste vollständig erfasste Rechnung dieses Kreditors. Trägst du sie hier ein, gelten sie ab sofort; spätere Rechnungen ändern sie nicht mehr, ausser du bestätigst es in der Rechnung ausdrücklich. Leerst du alle drei, legt die nächste vollständige Rechnung sie wieder fest.</p>

<section data-help-field="bank_text">
<h4>Banktext</h4>
<p>So nennt die <strong>Bank</strong> diesen Lieferanten auf dem Kontoauszug, wenn du mit Karte oder TWINT bezahlst — dort steht kein Name, nur der Händler nach «Bezugsort:», zum Beispiel «EXAMPLE SHOP BERLIN». Mit dem Banktext findet eine Rechnung ihre Zahlung auf dem Kontoauszug von selbst. Du musst ihn nicht eintippen: Ordnest du einer Rechnung dieses Lieferanten einmal von Hand eine Kartenzahlung zu, merkt sich das System den Händlertext. Stimmt er nicht (etwa weil der Ort wechselt), kürze ihn hier auf den Teil, der immer gleich bleibt. Gross- und Kleinschreibung spielt keine Rolle. Freiwillig.</p>
</section>

<section data-help-field="uid">
<h4>UID</h4>
<p>Die Unternehmens-Identifikationsnummer des Lieferanten, z.B. CHE-123.456.789 — steht auf seiner Rechnung, oft mit «MWST» dahinter (das Wort lässt du weg). Freiwillig; die Prüfziffer wird kontrolliert. Eine UID gehört zu genau einem Kreditor.</p>
</section>

<section data-help-field="default_account">
<h4>Standardkonto</h4>
<p>Das Aufwandkonto, mit dem jede neue Rechnung dieses Kreditors beginnt, z.B. 6500 Büromaterial. Nur bebuchbare, aktive Konten. In der einzelnen Rechnung änderbar.</p>
</section>

<section data-help-field="default_tax">
<h4>Standard-MWST</h4>
<p>Der MWST-Code der Vorsteuer, z.B. VM für Material und Dienstleistungen oder VI für Investitionen und übrigen Betriebsaufwand — oder «ohne MWST», wenn der Lieferant keine MWST verrechnet. «— offen —» heisst: noch nicht entschieden. Ist deine Firma nicht MWST-pflichtig, gibt es nur «ohne MWST».</p>
<p>«VM — nur Steuer» passt für den Zoll, dessen Rechnung nur die Einfuhrsteuer enthält: Das Standardkonto bleibt dann leer («automatisch»), z77 setzt das Vorsteuerkonto des Codes. Ist die erste Zeile einer Rechnung «nur Steuer», lernt der Kreditor die Art zusammen mit dem Code.</p>
</section>

<section data-help-field="default_payment_method_code">
<h4>Bezahlt mit</h4>
<p>Womit du diesen Lieferanten normalerweise bezahlst — die Zahlungsarten aus Stammdaten › Finanzen › Zahlungsarten, z.B. «Bank» oder «Firmenkarte». Sie bestimmt das Haben-Konto der Buchung.</p>
</section>

<section data-help-field="default_line_text">
<h4>Kontierungstext</h4>
<p>Der Text, den jede neue Rechnung dieses Kreditors in der Zeile bekommt, z.B. «KI-Entgelte». Ist er gesetzt, gilt immer er. Freiwillig.</p>
<p>Ohne Kontierungstext nimmt z77 den Text aus der Spalte, die es sich gemerkt hat (z.B. unter «Description»), sonst den Text vom Einzahlungsschein oder der KI, zuletzt den Text der zuletzt gespeicherten Rechnung. Festlegen kannst du ihn auch in der Rechnung mit «als Standard übernehmen». Leerst du das Feld, gilt wieder diese Reihenfolge.</p>
</section>

<section data-help-field="iban">
<h4>IBAN des Kreditors</h4>
<p>Die Konten, auf die du diesen Lieferanten bezahlst — auch mehrere, z.B. eine QR-IBAN und eine normale IBAN, oder eine ausländische. Die Prüfziffer wird kontrolliert. Eine weitere IBAN trägst du in «Neue IBAN» ein; eine IBAN entfernst du, indem du das Feld leerst und speicherst.</p>
</section>

<section data-help-field="hint">
<h4>Gemeinsame IBAN und Hinweis</h4>
<p>Manche Inkasso- oder Abrechnungsfirmen kassieren für mehrere Lieferanten auf dieselbe IBAN. Das ist erlaubt; das Fenster zeigt dann «Auch bei: …». Der Hinweis — z.B. der Name oder die UID, wie sie auf der Rechnung stehen — sagt später, welcher Kreditor gemeint ist. Ohne eindeutigen Treffer wählst du selbst.</p>
</section>

<section data-help-field="iban_active">
<h4>IBAN aktiv</h4>
<p>Eine inaktive IBAN bleibt beim Kreditor stehen, wird aber nicht mehr verwendet, um eine Rechnung diesem Kreditor zuzuordnen.</p>
</section>

<section data-help-field="learned">
<h4>Gelernt aus Rechnungen</h4>
<p>Rechnungen aus dem Ausland haben oft keinen QR-Einzahlungsschein und keine Schweizer UID. Damit z77 solche Lieferanten trotzdem erkennt, merkt es sich beim Speichern einer Rechnung, was auf ihren Rechnungen immer gleich ist: die ausländische MWST-Nummer, die Internetadresse und die Absenderzeile mit dem Namen. Dazu merkt es sich, neben welchem Wort Rechnungsnummer, Datum, Fälligkeit und Betrag stehen, z.B. «Amount due».</p>
<p>Bei den ersten Rechnungen liest die KI noch mit, wenn sie eingeschaltet ist. Stimmt zweimal alles überein, liest z77 die Rechnungen dieses Lieferanten allein — das kostet nichts. Korrigierst du einen Wert, lernt z77 neu und die KI liest wieder mit.</p>
<p>«Gelerntes vergessen» löscht alles hier Gelernte. Die nächsten Rechnungen bringen es wieder bei.</p>
</section>
