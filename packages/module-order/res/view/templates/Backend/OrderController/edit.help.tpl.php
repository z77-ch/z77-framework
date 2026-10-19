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
<p><strong>Speichern</strong> schreibt Kopf und Positionen; das Total rechnet die Debitorenbuchhaltung genau so, wie der Buchungsdruck die Rechnung erstellen wird (MWST pro Code, Rundung auf 5 Rappen). Was den Buchungsdruck heute verhindern würde, steht unter dem Total als «Vor dem Buchungsdruck beheben». Während Positionen geändert werden, rechnet das Total <strong>laufend</strong> aus den Zeilen mit (ohne Rundung, so beschriftet); massgebend ist das Total nach dem Speichern.</p>
<p><strong>Buchungsdruck …</strong> (nur in einem Status «zu fakturieren») erstellt aus dem Auftrag die Rechnung, vergibt die Belegnummer, verbucht sie und öffnet sie als PDF mit QR-Zahlteil — in einem Schritt. Danach ist der Auftrag <strong>gebucht</strong>: er bleibt, wie er verbucht wurde, eine Korrektur ist eine Gutschrift auf der Rechnung. Ob die Rechnung bezahlt ist, ist <strong>kein Status</strong> des Auftrags: es steht unter «Belege» und in der Liste «Gebucht» in der Spalte «Zahlung» und kommt aus der Debitorenbuchhaltung. «Erledigt» setzt man von Hand.</p>
<p>Ein Auftrag wird nie gelöscht — die Nummer ist vergeben. Ein Auftrag, der nicht zustande kommt, wird auf «Erledigt ohne Lagerbuchung» gesetzt.</p>
<p><strong>Verlauf</strong> zeigt jeden Statuswechsel (wann, wer, von → nach, wodurch). <strong>Belege</strong> zeigt die Rechnung mit ihrem Zahlungsstand und ihre Gutschriften.</p>

<section data-help-field="contact">
<h3>Kunde</h3>
<p><strong>Kunde wählen …</strong> öffnet die Suche: nach Name, Ort, Kundennummer oder E-Mail suchen, dann <strong>Übernehmen</strong>. Die Lieferadressen des gewählten Kunden stehen danach gleich zur Auswahl. Wer nicht gefunden wird, lässt sich dort mit <strong>+ Neuer Kontakt</strong> erfassen.</p>
<p>Gefunden werden die aktiven Debitoren und die aktiven <strong>Kontakte ohne Debitorenprofil</strong> («neu»). Ein solcher Kontakt wird erst Kunde, wenn er <strong>Debitor</strong> ist: <strong>Übernehmen</strong> fragt zuerst <strong>Debitor anlegen</strong> — mit Preisen netto oder brutto und den Zahlungskonditionen; die Kundennummer wird dabei vergeben. <strong>Abbrechen</strong> wählt keinen Kunden. So entsteht kein Auftrag mit dem falschen Preismodus, der nach dem Buchen gutgeschrieben und neu erstellt werden müsste. Ebenso fragt <strong>+ Neuer Kontakt</strong> nach dem Speichern des Kontakts nach dem Debitor.</p>
<p>Mit dem Kunden übernimmt der Auftrag dessen <strong>Preise</strong> (netto oder brutto, siehe dort).</p>
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
<p>Wann geliefert oder geleistet wird — bei Ware der <strong>Liefertermin</strong>, bei einer Dienstleistung der Beginn. Es ist dasselbe Datum wie das Leistungsdatum der Rechnung: der <strong>MWST-Satz</strong> richtet sich danach; leer heisst: das Rechnungsdatum des Buchungsdrucks. «bis» nur für einen Zeitraum, und nur mit «von».</p>
<p>Ist das Feld leer, setzt der Druck des <strong>Lieferscheins</strong> den Drucktag ein. Verschiebt sich die Lieferung, hier nachführen — die Auftragsliste sucht und sortiert in der Spalte «Leistung».</p>
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
<p>Ob die Preise der Positionen die MWST enthalten. Ein neuer Auftrag beginnt mit dem Preismodus des Debitors (im Debitor hinterlegt, «Debitor anpassen»); hier lässt er sich für diesen Auftrag ändern.</p>
<p>Ein Wechsel rechnet die Positionen <strong>sofort</strong> um: eine Artikelposition nimmt wieder genau den Artikelpreis in der neuen Art; ein von Hand geänderter Preis wird mit dem MWST-Satz der Zeile umgerechnet und auf den Rappen gerundet. Ohne JavaScript rechnet das Speichern um.</p>
</section>

<section data-help-field="terms">
<h3>Zahlungskonditionen</h3>
<p>Die Zahlungsfrist und ein allfälliges Skonto der Rechnung, die der Buchungsdruck erstellt («30 Tage netto»). «wie beim Kunden» = die Konditionen, die beim Debitor hinterlegt sind — so, wie sie beim Buchungsdruck gelten. Eine andere Wahl gilt nur für diesen Auftrag. «Debitor anpassen» ändert die Konditionen, die beim Kunden hinterlegt sind (für alle künftigen Rechnungen). Das Konto für den QR-Zahlteil wählt der Auftrag selbst: ein neuer Auftrag das erste aktive Zahlungsziel, ein bestehender behält seines.</p>
</section>

<section data-help-field="notes">
<h3>Notiz (intern)</h3>
<p>Nur hier sichtbar, wird nie gedruckt.</p>
</section>

<section data-help-field="type">
<h3>Positionen</h3>
<p>Ein Auftrag kennt zwei Arten von Zeilen:</p>
<ul>
    <li><strong>Leistung</strong>: ein Artikel, Menge × Preis. <strong>Jede Position mit Preis ist ein Artikel</strong> — auch eine Pauschale (ein Artikel mit Menge 1). MWST-Satz und Ertragskonto kommen vom Artikel.</li>
    <li><strong>Text</strong>: nur Text über die ganze Breite — kein Artikel, keine Menge, kein Preis.</li>
</ul>
<p><strong>+ Artikel</strong> und <strong>+ Textzeile</strong> fügen unten eine Zeile an, <strong>×</strong> entfernt eine. Die Reihenfolge ist die Druckreihenfolge: mit <strong>⋮⋮</strong> eine Zeile an ihren Platz ziehen (oder ⋮⋮ anwählen und <strong>Alt+↑ / Alt+↓</strong>); eine Position nimmt ihre eingerückten Zeilen mit. Die Nummern 1, 1.1, 2 … stellen sich dabei neu.</p>
<p>Eine Position <strong>ohne Artikel</strong> (aus der Zeit vor dem 18.10.2026) bleibt lesbar und lässt sich unverändert weiter speichern; wer sie ändern will, wählt einen Artikel. Eine frühere «Pauschale» steht als Leistung mit Menge 1 da — der Betrag bleibt derselbe.</p>
</section>

<section data-help-field="child">
<h3>Einrücken ⇥</h3>
<p>Eingeschaltet, steht die Zeile <strong>unter der Position darüber</strong> (eine Ebene, Nummer 1.1) und wird mit ihr gedruckt — z.B. eine Textzeile mit Details zu einem Artikel. Unter einer Textzeile stehen keine Positionen.</p>
</section>

<section data-help-field="article">
<h3>Artikel</h3>
<p>Die Artikelnummer (die Liste schlägt die aktiven Artikel vor). Sobald ein Artikel gewählt ist, füllt die Zeile <strong>Text, Einheit und Preis</strong> (der Preis am Auftragsdatum) — überschreiben lässt sich alles; ein anderer Artikel füllt sie neu. Der <strong>MWST-Satz</strong> steht in der Spalte MWST, das Ertragskonto zeigt der Auftrag nicht: beides kommt immer vom Artikel (ein anderes Konto ist ein anderer Artikel). Was leer bleibt, übernimmt das Speichern vom Artikel.</p>
</section>

<section data-help-field="text">
<h3>Text</h3>
<p>Was gedruckt wird, auch über mehrere Zeilen — das Feld wächst mit. Bei einem Artikel ist die <strong>erste Zeile</strong> sein Name (hervorgehoben), darunter kann eine Beschreibung stehen. Eine Zeile ohne Artikel, Text, Menge und Preis wird beim Speichern weggelassen.</p>
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

