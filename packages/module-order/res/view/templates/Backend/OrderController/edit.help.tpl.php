<?php
/**
 * Help for the order window (ADR-048, plan §10) — attached by the trait, opened with «Hilfe» in
 * the title bar or F1; a general part (the flow, what «gebucht» means, why payment is not a
 * status) and a section per field (`data-help-field` = the field's `name`, a line field by its
 * `data-help-key`). The form itself carries no explanations.
 *
 * @var bool $isNew
 */
?>
<h3>Auftrag</h3>
<p>Ein Auftrag läuft durch seine Status: <strong>Offerte</strong> → <strong>Terminauftrag</strong> → <strong>Lieferschein</strong> (setzt «Geliefert») → <strong>Zu fakturieren</strong> → <strong>Buchungsdruck …</strong> (setzt «Gebucht») → <strong>Erledigt</strong>. Offerte und Auftrag sind derselbe Datensatz mit derselben Nummer; was er ist, sagt der Status. Welche Status es gibt und was sie bewirken, steht unter Stammdaten › Aufträge › Auftragsstatus.</p>
<p><strong>Speichern</strong> schreibt Kopf und Positionen; das Total rechnet die Debitorenbuchhaltung genau so, wie der Buchungsdruck die Rechnung erstellen wird (MWST pro Code, Rundung auf 5 Rappen). Was den Buchungsdruck heute verhindern würde, steht unter dem Total als «Vor dem Buchungsdruck beheben».</p>
<p><strong>Buchungsdruck …</strong> (nur in einem Status «zu fakturieren») erstellt aus dem Auftrag die Rechnung, vergibt die Belegnummer, verbucht sie und öffnet sie als PDF mit QR-Zahlteil — in einem Schritt. Danach ist der Auftrag <strong>gebucht</strong>: er bleibt, wie er verbucht wurde, eine Korrektur ist eine Gutschrift auf der Rechnung. Ob die Rechnung bezahlt ist, ist <strong>kein Status</strong> des Auftrags: es steht unter «Belege» und in der Liste «Gebucht» in der Spalte «Zahlung» und kommt aus der Debitorenbuchhaltung. «Erledigt» setzt man von Hand.</p>
<p>Ein Auftrag wird nie gelöscht — die Nummer ist vergeben. Ein Auftrag, der nicht zustande kommt, wird auf «Erledigt ohne Lagerbuchung» gesetzt.</p>
<p><strong>Verlauf</strong> zeigt jeden Statuswechsel (wann, wer, von → nach, wodurch). <strong>Belege</strong> zeigt die Rechnung mit ihrem Zahlungsstand und ihre Gutschriften.</p>

<section data-help-field="contact">
<h3>Kunde</h3>
<p><strong>Kunde wählen …</strong> öffnet die Suche: nach Name, Ort, Kundennummer oder E-Mail suchen, dann <strong>Übernehmen</strong>. Die Lieferadressen des gewählten Kunden stehen danach gleich zur Auswahl. Wer nicht gefunden wird, lässt sich dort mit <strong>+ Neuer Kontakt</strong> erfassen.</p>
<p>Gefunden werden die aktiven Debitoren und die aktiven <strong>Kontakte ohne Debitorenprofil</strong> («neu») — eine Offerte an einen Interessenten braucht noch keine Kundennummer. Debitor wird ein solcher Kontakt erst beim <strong>Buchungsdruck</strong>, mit der nächsten Kundennummer und den Standard-Konditionen. <strong>+ Kontakt</strong> erfasst einen neuen Kontakt mit Adresse, ohne den Auftrag zu verlassen; er ist danach gewählt.</p>
</section>

<section data-help-field="subject">
<h3>Betreff</h3>
<p>Eine Zeile (höchstens 200 Zeichen): steht auf Offerte, Auftragsbestätigung, Lieferschein und — als «Auftrag Nr · Betreff» — auf der Rechnung.</p>
</section>

<section data-help-field="order_date">
<h3>Auftragsdatum</h3>
<p>Das Datum des Auftrags. Eine Artikelposition übernimmt den Preis, der an diesem Tag gilt.</p>
</section>

<section data-help-field="service_from">
<h3>Leistung von / bis</h3>
<p>Wann geliefert oder geleistet wird. Der <strong>MWST-Satz</strong> richtet sich nach diesem Datum; leer heisst: das Rechnungsdatum des Buchungsdrucks. «bis» nur für einen Zeitraum, und nur mit «von».</p>
</section>

<section data-help-field="status">
<h3>Status</h3>
<p><?= !empty($isNew) ? 'Ein neuer Auftrag beginnt im ersten aktiven Status (meist «Offerte»); ein anderer lässt sich hier wählen, nur nicht «Gebucht» und kein abschliessender.' : 'Geändert wird der Status mit <strong>Status ändern …</strong>: vorwärts immer, zurück nur, solange keine Rechnung verbucht ist. «Gebucht» setzt nur der Buchungsdruck, «Geliefert» setzt auch der Lieferschein.' ?></p>
</section>

<section data-help-field="ship">
<h3>Lieferadresse</h3>
<p>Wohin der Lieferschein geht. «wie Rechnungsadresse» = die Rechnungsadresse des Kunden. Sonst eine der Adressen des Kunden — sie wird beim Speichern als Kopie in den Auftrag übernommen; ändert sie sich später beim Kontakt, bleibt der Auftrag, wie er ist. Nach einem Wechsel des Kunden erst speichern, dann die Lieferadresse wählen. Die Rechnung geht immer an die Rechnungsadresse.</p>
</section>

<section data-help-field="price_mode">
<h3>Preise netto / brutto</h3>
<p>Ob die Preise der Positionen die MWST enthalten. Ein Artikelpreis in der anderen Art wird beim Übernehmen umgerechnet.</p>
</section>

<section data-help-field="target">
<h3>Zahlungsziel</h3>
<p>Das Konto, für das die Rechnung beim Buchungsdruck den QR-Zahlteil druckt (QR-IBAN mit Referenz, IBAN ohne). «kein Zahlteil» = die Rechnung ohne Einzahlungsschein.</p>
</section>

<section data-help-field="notes">
<h3>Notiz (intern)</h3>
<p>Nur hier sichtbar, wird nie gedruckt.</p>
</section>

<section data-help-field="type">
<h3>Art der Position</h3>
<ul>
    <li><strong>Leistung</strong>: Menge × Preis.</li>
    <li><strong>Pauschale</strong>: ein Preis, keine Menge (ein Artikel zählt im Lager als 1).</li>
    <li><strong>Text</strong>: nur Text — kein Artikel, keine Menge, kein Preis.</li>
</ul>
</section>

<section data-help-field="child">
<h3>unter</h3>
<p>Die Zeile wird unter der Position darüber gedruckt (eine Ebene). Nur eine Position mit Preis trägt Unterpositionen.</p>
</section>

<section data-help-field="article">
<h3>Artikel</h3>
<p>Die Artikelnummer (die Liste schlägt die aktiven Artikel vor). Leer = eine <strong>freie Position</strong> mit eigenem MWST-Code und Konto. Sobald ein Artikel gewählt ist, füllt die Zeile <strong>Text, Einheit und Preis</strong> (der Preis am Auftragsdatum) — überschreiben lässt sich alles; ein anderer Artikel füllt sie neu. <strong>MWST-Code und Ertragskonto</strong> kommen immer vom Artikel und stehen gesperrt (ein anderes Konto ist eine freie Position). Was leer bleibt, übernimmt das Speichern vom Artikel.</p>
</section>

<section data-help-field="text">
<h3>Text</h3>
<p>Was gedruckt wird. Eine Zeile ohne Artikel, Text, Menge und Preis wird weggelassen — so wird eine Position gelöscht.</p>
</section>

<section data-help-field="quantity">
<h3>Menge</h3>
<p>Mit höchstens drei Dezimalen (0.25 h). <strong>Negativ</strong> = eine Rücknahme (Ware zurück, im Lager ein Zugang).</p>
</section>

<section data-help-field="price">
<h3>Preis</h3>
<p>Pro Einheit, netto oder brutto wie im Kopf gewählt. Leer bei einem Artikel = der Artikelpreis am Auftragsdatum.</p>
</section>

<section data-help-field="discount">
<h3>Rabatt</h3>
<p>In Prozent auf den Positionsbetrag.</p>
</section>

<section data-help-field="account">
<h3>MWST-Code und Konto</h3>
<p>Nur bei einer freien Position: ein aktiver MWST-Code und ein Ertragskonto, das im Kontenplan «auf Belegen wählbar» ist. Bei einer Artikelposition stehen sie gesperrt — sie kommen vom Artikel.</p>
</section>
