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
</section>
<section data-help-field="line_text">
<h3>Text (Zeile)</h3>
<p>Was die Zeile ist, z.B. «Telefon Oktober». Darf leer sein.</p>
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
</section>
<section data-help-field="split">
<h3>+ Zeile</h3>
<p>Eine weitere Zeile für ein zweites Konto oder einen zweiten MWST-Code. Es bleibt eine Rechnung. Mit × entfernst du eine Zeile.</p>
</section>
<section data-help-field="take_default">
<h3>Als Standard übernehmen</h3>
<p>Erscheint, wenn die Rechnung anders kontiert ist als der Standard des Kreditors. Eingeschaltet werden Konto und MWST der ersten Zeile und «Bezahlt mit» beim Speichern sein neuer Standard.</p>
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
<p>Lässt das System die Rechnung nochmals lesen, z.B. wenn etwas fehlt. Meist nach einer Minute ist es fertig. Es füllt nur leere Felder. Speichere vorher, sonst gehen deine Eingaben im Fenster verloren.</p>
</section>
<section data-help-field="adopt">
<h3>Kreditor übernehmen</h3>
<p>Für eine Rechnung mit einem neuen Lieferanten («Vorschlag: …»). Du wählst einen bestehenden Kontakt oder legst einen neuen an — vorausgefüllt mit dem Vorschlag. Der Kontakt wird Kreditor und bekommt IBAN und UID der Rechnung.</p>
<p>Gibt es den Kontakt schon (gleicher Name oder gleiche UID), schlägt das Fenster ihn vor. Einen zweiten Kontakt legt es nur an, wenn du «Trotzdem einen neuen Kontakt anlegen» ankreuzt.</p>
<p>Ist der Vorschlag unvollständig, z.B. ohne Strasse, steht oben, was fehlt. Ergänze es aus dem PDF.</p>
<p>Weitere Rechnungen desselben Lieferanten im Journal bekommen den Kreditor gleich mit. Die Meldung zählt sie.</p>
</section>
