<?php
/**
 * Help for the creditor invoice window (ADR-048) — attached by the trait, opened with «Hilfe» in
 * the title bar or F1; a general part, then a section per field (`data-help-field` = the field's
 * `name`, or its `data-help-key`: `line_text`, `line_account`, `line_tax`, `line_amount`, `split`,
 * `take_default`, `release`, `discard`, `delete`, `recognize`, `adopt`). The form itself carries no explanations. Also the
 * KI-Assistent's knowledge (ADR-055).
 *
 * Written for people (owner 2026-11-16: «die Hilfe ist für menschliches Lesen viel zu
 * kompliziert»): everyday words, short sentences, what the user does and sees; no internal terms.
 */
?>
<h3>Rechnung im Kreditorenjournal</h3>
<p>Links das PDF der Rechnung, rechts die Felder. Trag ein, was auf der Rechnung steht, und speichere. Speichern geht auch, wenn noch etwas fehlt.</p>
<p>Was noch fehlt, ist mit «— fehlt» markiert. Vollständig ist die Rechnung, wenn Kreditor, Betrag, «Bezahlt mit» und in jeder Zeile Konto und MWST gesetzt sind und die Zeilen zusammen den Betrag ergeben. «PDF öffnen» zeigt die Rechnung gross in einem neuen Tab.</p>

<h4>Oben im Fenster</h4>
<p><strong>Erkannt: Text</strong>, <strong>KI</strong> oder <strong>von Hand</strong> sagt, woher die Werte stammen. Das System füllt nur Felder, die leer waren — vergleich sie mit dem PDF. Darunter stehen Hinweise, z.B. «nicht erkannt — von Hand erfassen» oder eine IBAN, die mehreren Kreditoren gehört (dann wählst du den Kreditor).</p>
<p><strong>Kreditor am Gelernten erkannt</strong> heisst: Das System hat den Lieferanten an dem erkannt, was es sich von seinen früheren Rechnungen gemerkt hat. Speicherst du die Rechnung, lernt es weiter; korrigierst du Kreditor, Nummer, Datum oder Betrag, merkt es sich die Korrektur.</p>
<p>Steht oben <strong>in Arbeit …</strong> oder <strong>KI liest …</strong>, liest das System die Rechnung gerade. Ist es fertig, lädt das Fenster neu, solange du darin nichts geändert hast.</p>

<h4>Gesperrt — vielleicht doppelt</h4>
<p>Ist die Rechnung gesperrt, steht oben, mit welcher Rechnung sie vielleicht doppelt ist, mit einem Link dorthin. Du entscheidest: «Kein Doppel — übernehmen», «Verwerfen …» oder «Löschen …». Verwerfen behält das PDF in den Dokumenten, und dieselbe Datei bleibt gesperrt. Löschen nimmt die Rechnung ganz weg. War es nur ein Tippfehler (z.B. in der Rechnungs-Nr.), hebt das Speichern die Sperre selbst auf.</p>

<h4>Löschen</h4>
<p><strong>Löschen …</strong> löscht die Rechnung ganz, auch eine gesperrte. Das PDF kommt in den Papierkorb der Dokumente, und du kannst dieselbe Datei wieder hochladen. Mehrere Rechnungen löschst du in der Liste: ankreuzen, dann «Löschen …». Eine verbuchte Rechnung lässt sich nicht mehr ändern oder löschen.</p>

<section data-help-field="creditor">
<h3>Kreditor</h3>
<p>Der Lieferant. Wählst du ihn, setzt das Fenster seine Standardwerte ein: IBAN (wenn er genau eine hat), «Bezahlt mit», Haben sowie Konto und MWST der ersten Zeile. Ein neuer Lieferant entsteht mit «Kreditor übernehmen …» oder im Kontakt mit der Rolle «Kreditor».</p>
<p>Hat der Kreditor noch kein Standard-Konto, wird beim Speichern das Konto der ersten Zeile sein Standard, mit ihrer MWST — ebenso «Bezahlt mit». Die Meldung sagt es. Ein Standard, den er schon hat, bleibt.</p>
</section>
<section data-help-field="iban">
<h3>IBAN</h3>
<p>Die IBAN, auf die du zahlst, wie sie auf der Rechnung steht. Die Prüfziffer wird kontrolliert.</p>
</section>
<section data-help-field="invoice_number">
<h3>Rechnungs-Nr.</h3>
<p>Die Nummer des Lieferanten. Hat derselbe Kreditor schon eine Rechnung mit dieser Nummer, wird diese hier gesperrt (vielleicht doppelt).</p>
</section>
<section data-help-field="invoice_date">
<h3>Rechnungsdatum</h3>
<p>Das Datum auf der Rechnung. Gebucht wird später mit dem Zahlungsdatum, nicht mit diesem.</p>
</section>
<section data-help-field="due_date">
<h3>Fällig am</h3>
<p>Bis wann zu zahlen ist. Nicht vor dem Rechnungsdatum.</p>
</section>
<section data-help-field="reference">
<h3>Referenz</h3>
<p>Die Referenz aus dem Zahlteil: eine QR-Referenz (27 Ziffern) oder eine RF-Referenz (beginnt mit RF). Leer, wenn die Rechnung keine hat. Gleiche IBAN und gleiche Referenz wie eine andere Rechnung sperrt diese hier (oft eine Mahnung).</p>
</section>
<section data-help-field="message">
<h3>Mitteilung</h3>
<p>Die Mitteilung aus dem Zahlteil, wenn es eine gibt.</p>
</section>
<section data-help-field="currency">
<h3>Währung</h3>
<p>CHF für eine Rechnung in Franken. Lautet sie auf eine andere Währung, z.B. USD, wählst du sie hier und trägst den Betrag der Rechnung unter «Betrag in Währung» ein. Gebucht wird trotzdem in CHF. Welche Währungen es gibt, legt die Einrichtung fest.</p>
</section>
<section data-help-field="original_amount">
<h3>Betrag in Währung</h3>
<p>Der Betrag, wie er auf der Rechnung steht, z.B. 9.00 bei USD 9.00. Bei CHF bleibt das Feld leer. Der Betrag erscheint später im Buchungstext, z.B. «Rechnung 123 · Tinify · 9.00 USD».</p>
</section>
<section data-help-field="amount">
<h3>Betrag CHF (inkl. MWST)</h3>
<p>Der Rechnungsbetrag in Franken, mit MWST. Hat die Rechnung nur eine Zeile ohne Betrag, bekommt die Zeile beim Speichern diesen Betrag.</p>
<p>Bei einer anderen Währung gehört hierher, was Bank oder Kreditkarte in CHF wirklich belastet haben, mit Gebühren — z.B. CHF 8.12 für USD 9.00. Bis du es weisst, bleibt das Feld leer; die Rechnung ist dann nicht vollständig und wird nicht verbucht. Den Kurs rechnet das System selbst und zeigt ihn unter dem Feld.</p>
</section>
<section data-help-field="exchange_rate">
<h3>Kurs</h3>
<p>Nur bei einer Rechnung in fremder Währung, und nur wenn du den Betrag in CHF nicht kennst: zum Beispiel wenn <strong>eine</strong> Kartenzahlung mehrere Rechnungen bezahlt hat und die Bank nur die Gesamtsumme und den Kurs zeigt. Trag den Kurs ein (CHF für 1 Einheit der Währung, z.B. 0.9342) und lass «Betrag CHF» leer — beim Speichern rechnet z77 den Betrag: Betrag in Währung × Kurs, auf den Rappen gerundet.</p>
<p>Der Kurs wird bei der Rechnung gespeichert, damit nachvollziehbar bleibt, wie der Betrag entstanden ist. Stehen Kurs <strong>und</strong> Betrag CHF im Fenster, müssen sie auf den Rappen zusammenpassen (ein Rappen Rundung ist erlaubt) — sonst eines der beiden Felder leeren.</p>
<p>Findet die Rechnung später ihre Zahlung auf dem Bankkonto («Zahlung suchen …»), gilt der Betrag der Bank; der von Hand eingetragene Kurs fällt dann weg.</p>
</section>
<section data-help-field="payment_method_code">
<h3>Bezahlt mit</h3>
<p>Wie du die Rechnung bezahlst, z.B. Bank. Das füllt Haben mit dem Konto dieser Zahlungsart. Die Zahlungsarten pflegst du unter Stammdaten › Finanzen › Zahlungsarten.</p>
</section>
<section data-help-field="credit_account">
<h3>Haben</h3>
<p>Das Konto, von dem bezahlt wird, meist die Bank. Kommt von «Bezahlt mit» und lässt sich ändern. Es ist nie dasselbe Konto wie eine Zeile.</p>
</section>
<section data-help-field="paid_on">
<h3>Bezahlt am</h3>
<p>Der Tag, an dem du bezahlt hast — nie in der Zukunft. Mit diesem Datum bucht «Journal verbuchen …» die Rechnung. Leer heisst unbezahlt: Die Rechnung bleibt im Journal. Für mehrere Rechnungen: in der Liste ankreuzen und «Bezahlt am …».</p>
<p><strong>Bankkonto mit Abgleich:</strong> Zahlst du über ein Bankkonto, das mit dem Kontoauszug abgeglichen wird (Datum «Abgleich ab» unter Stammdaten › Finanzen › Bankkonten), kommt «Bezahlt am» ab diesem Tag <strong>nur von der Bank</strong>. Eintippen wird abgelehnt. Die Rechnung bleibt offen, bis der Kontoauszug eingelesen ist; dann übernimmst du die Zahlung mit «Zahlung suchen …» (mit QR-Referenz geht es von selbst). Mit einer Zahlungsart ohne solches Bankkonto — z.B. bar — tippst du das Datum wie bisher.</p>
<p>Steht darunter <strong>«Bank · Datum · Betrag»</strong>, hat die Rechnung ihre Zahlung auf dem Kontoauszug gefunden: Das Datum ist dann der Buchungstag der Bank und lässt sich hier nicht ändern — ebenso wenig der Betrag.</p>
</section>
<section data-help-field="bank">
<h3>Zahlung suchen … / Zuordnung lösen</h3>
<p>Liest du den Kontoauszug der Bank ein (Finanzen › Bankkonto), findet die Rechnung ihre Zahlung dort:</p>
<ul>
    <li><strong>Mit QR- oder RF-Referenz</strong> geht es von selbst — sobald Rechnung und Kontoauszug beide da sind, steht «Bezahlt am» drin.</li>
    <li><strong>Ohne Referenz</strong> öffnet «Zahlung suchen …» die Bewegungen, die passen (gleicher Betrag, in der Nähe des Fälligkeitsdatums). Passt genau eine und nennt sie den Lieferanten, ist sie schon angekreuzt; sonst wählst du selbst.</li>
    <li><strong>Fremdwährung:</strong> Gesucht wird der Betrag in der Währung der Rechnung (zum Beispiel 9.00 USD). Der Betrag in Franken kommt dann von der Bank — genau das, was sie belastet hat.</li>
    <li>Bei Karten- und TWINT-Zahlungen steht kein Name auf dem Kontoauszug, nur der Händler. Ordnest du so eine Zahlung einmal von Hand zu, merkt sich der Kreditor den Händlertext («Banktext») — die nächste Rechnung wird vorgeschlagen.</li>
</ul>
<p><strong>Zuordnung lösen:</strong> War es die falsche Bewegung, löst du sie hier. Die Rechnung ist danach wieder unbezahlt, die Bewegung wieder frei. Bei einer verbuchten Rechnung geht das nicht — die Buchung müsste zuerst zurückgenommen werden.</p>
</section>
<section data-help-field="line_text">
<h3>Text (Zeile)</h3>
<p>Was die Zeile ist, z.B. «Telefon Oktober». Darf leer sein.</p>
<p>Bei einer neuen Rechnung füllt z77 den Text selbst, wenn das Feld leer ist — in dieser Reihenfolge:</p>
<ul>
    <li><strong>Standardtext des Kreditors</strong> («Kontierungstext»): Ist einer festgelegt, steht immer er da. Du legst ihn mit «als Standard übernehmen» fest oder im Fenster des Kreditors.</li>
    <li><strong>Gelernte Spalte:</strong> Speicherst du einen Text, der so auf der Rechnung steht (z.B. «Studio Max» unter «Description»), merkt sich z77 die Spalte. Bei der nächsten Rechnung nimmt es die erste Zeile darunter, mit der Zeile darunter dazu: «Studio Max · Nov 14–Dec 14, 2026».</li>
    <li>Sonst den Text vom Einzahlungsschein oder der KI, zuletzt den Text der zuletzt gespeicherten Rechnung dieses Kreditors.</li>
</ul>
</section>
<section data-help-field="line_account">
<h3>Konto (Zeile)</h3>
<p>Das Aufwandkonto, z.B. 6510 Telefon. Nummer tippen oder aus der Liste wählen.</p>
</section>
<section data-help-field="line_tax">
<h3>MWST (Zeile)</h3>
<p>Der Vorsteuer-Code, z.B. VM oder VI, oder «ohne MWST». Ist dein Mandant nicht MWST-pflichtig, gibt es nur «ohne MWST».</p>
</section>
<section data-help-field="line_amount">
<h3>Betrag (Zeile)</h3>
<p>Der Teil des Betrags für diese Zeile, mit MWST. Alle Zeilen zusammen ergeben den Rechnungsbetrag.</p>
<p>Haben alle Zeilen einen Betrag und stimmt die Summe nicht mit «Betrag CHF» überein, wird nicht gespeichert — unter «Kontierung» steht die Differenz, z.B. «Zeilen ergeben CHF 25.00, Rechnungstotal CHF 20.00 — Differenz CHF 5.00». Eine Zeile ohne Betrag oder ein leeres «Betrag CHF» (Fremdwährung, bevor die Bank belastet hat) darf gespeichert werden; die Rechnung bleibt dann unvollständig.</p>
</section>
<section data-help-field="split">
<h3>+ Zeile</h3>
<p>Eine weitere Zeile für ein zweites Konto oder einen zweiten MWST-Code. Es bleibt eine Rechnung. Mit × entfernst du eine Zeile.</p>
</section>
<section data-help-field="take_default">
<h3>Als Standard übernehmen</h3>
<p>Erscheint, wenn die Rechnung anders kontiert ist als der Standard des Kreditors oder einen anderen Text hat. Eingeschaltet werden Konto, MWST und Text der ersten Zeile und «Bezahlt mit» beim Speichern sein neuer Standard — nur bei einer vollständigen Rechnung.</p>
<p>Der Text ist danach der <strong>Standardtext</strong>: Jede neue Rechnung dieses Kreditors bekommt ihn, z.B. immer «KI-Entgelte» statt «Studio Pro». Ändern oder leeren kannst du ihn im Fenster des Kreditors («Kontierungstext»).</p>
</section>
<section data-help-field="release">
<h3>Kein Doppel — übernehmen</h3>
<p>Die Rechnung ist kein Doppel der genannten: Sie kommt zurück ins Journal, und diese beiden werden nicht mehr verglichen. Ist sie doch ein Doppel, nimm «Verwerfen …».</p>
</section>
<section data-help-field="discard">
<h3>Verwerfen</h3>
<p>Nur bei einer gesperrten Rechnung. Sie ist ein Doppel und kommt weg. Das PDF bleibt in den Dokumenten, und dieselbe Datei wird nicht noch einmal eingelesen. Du bestätigst zuerst.</p>
</section>
<section data-help-field="delete">
<h3>Löschen</h3>
<p>Löscht die Rechnung ganz, auch eine gesperrte. Das PDF kommt in den Papierkorb der Dokumente, und du kannst dieselbe Datei wieder hochladen. Du bestätigst zuerst.</p>
</section>
<section data-help-field="recognize">
<h3>Jetzt erkennen</h3>
<p>Lässt das System die Rechnung nochmals lesen, z.B. wenn etwas fehlt. Meist nach einer Minute ist es fertig. Es füllt nur leere Felder. Die KI hilft nur, wenn der Schalter «KI-Erkennung» in der Liste an ist. Speichere vorher, sonst gehen deine Eingaben im Fenster verloren.</p>
</section>
<section data-help-field="adopt">
<h3>Kreditor übernehmen</h3>
<p>Für eine Rechnung mit einem neuen Lieferanten («Vorschlag: …»). Du wählst einen bestehenden Kontakt oder legst einen neuen an — vorausgefüllt mit dem Vorschlag. Der Kontakt wird Kreditor und bekommt IBAN und UID der Rechnung.</p>
<p>Gibt es den Kontakt schon (gleicher Name oder gleiche UID), schlägt das Fenster ihn vor. Einen zweiten Kontakt legt es nur an, wenn du «Trotzdem einen neuen Kontakt anlegen» ankreuzt.</p>
<p>Ist der Vorschlag unvollständig, z.B. ohne Strasse, steht oben, was fehlt. Ergänze es aus dem PDF.</p>
<p>Weitere Rechnungen desselben Lieferanten im Journal bekommen den Kreditor gleich mit. Die Meldung zählt sie.</p>
</section>
