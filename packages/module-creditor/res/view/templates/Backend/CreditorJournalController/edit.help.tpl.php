<?php
/**
 * Help for the creditor invoice window (ADR-048) — attached by the trait, opened with «Hilfe» in
 * the title bar or F1; a general part, then a section per field (`data-help-field` = the field's
 * `name`, or its `data-help-key`: `line_text`, `line_account`, `line_tax`, `line_amount`, `split`,
 * `take_default`, `release`, `recognize`, `adopt`). The form itself carries no explanations. Also the
 * KI-Assistent's knowledge (ADR-055).
 */
?>
<h3>Rechnung im Kreditorenjournal</h3>
<p>Links das PDF der Rechnung, rechts die Felder. Trag ab, was auf der Rechnung steht, und teile den Betrag auf die Konten auf. Speichern geht auch, wenn noch etwas fehlt.</p>
<p>Was für «vollständig» fehlt, ist am Feld mit «— fehlt» markiert. <strong>Löschen …</strong> löscht die Rechnung ganz: das PDF kommt in den Papierkorb der Dokumente, und dieselbe Datei kannst du wieder hochladen. Eine verbuchte Rechnung ist unveränderlich und lässt sich nicht löschen. «PDF öffnen» zeigt die Rechnung in voller Grösse in einem neuen Tab.</p>
<p>Ist die Rechnung <strong>gesperrt</strong> (mögliches Doppel), steht oben, von welcher Rechnung, mit einem Link dorthin. Du entscheidest mit «Kein Doppel — übernehmen» oder «Verwerfen …» (das PDF bleibt in den Dokumenten, dieselbe Datei bleibt gesperrt).
 Korrigierst du einen Tippfehler (z.B. die Rechnungs-Nr.) und es passt danach zu keiner anderen Rechnung mehr, hebt das Speichern die Sperre selbst auf.</p>
<p>Oben steht <strong>Erkannt: Text</strong> (aus dem QR-Zahlteil), <strong>KI</strong> oder <strong>von Hand</strong>, mit der Sicherheit und wie der Kreditor gefunden wurde. Erkannte Werte stehen nur in Feldern, die leer waren — prüf sie mit dem PDF und speichere. Darunter die Hinweise: z.B. ein Betrag in Euro (in CHF von Hand eintragen), eine IBAN, die mehreren Kreditoren gehört (dann wählst du den Kreditor), oder «nicht erkannt — von Hand erfassen». Steht oben <strong>Erkennung ausstehend</strong>, liest der Job «Kreditoren-Eingang» die Rechnung gleich; danach die Liste neu laden.</p>
<section data-help-field="creditor">
<h3>Kreditor</h3>
<p>Der Lieferant. Wählst du ihn, setzt das Fenster seine Standardwerte ein: IBAN (wenn er genau eine hat), «Bezahlt mit», Haben sowie Konto und MWST der ersten Zeile. Auch beim Speichern füllt er, was noch leer ist. Ein neuer Lieferant wird im Kontakt mit der Rolle «Kreditor» angelegt.</p>
</section>
<section data-help-field="iban">
<h3>IBAN</h3>
<p>Die IBAN, auf die du zahlst, wie sie auf der Rechnung steht. Sie wird mit der Rechnung gespeichert, auch wenn der Kreditor später eine andere hat. Die Prüfziffer wird kontrolliert.</p>
</section>
<section data-help-field="invoice_number">
<h3>Rechnungs-Nr.</h3>
<p>Die Nummer des Lieferanten. Hat derselbe Kreditor schon eine Rechnung mit dieser Nummer, wird diese hier gesperrt (mögliches Doppel).</p>
</section>
<section data-help-field="invoice_date">
<h3>Rechnungsdatum</h3>
<p>Das Datum auf der Rechnung. Gebucht wird später mit dem Zahlungsdatum, nicht mit diesem Datum.</p>
</section>
<section data-help-field="due_date">
<h3>Fällig am</h3>
<p>Bis wann zu zahlen ist. Nicht vor dem Rechnungsdatum.</p>
</section>
<section data-help-field="reference">
<h3>Referenz</h3>
<p>Die Referenz aus dem Zahlteil: eine QR-Referenz (27 Ziffern, gehört zu einer QR-IBAN) oder eine RF-Referenz (beginnt mit RF). Leer, wenn die Rechnung keine hat. Gleiche IBAN und gleiche Referenz wie bei einer anderen Rechnung sperrt diese hier (mögliches Doppel, z.B. eine Mahnung).</p>
</section>
<section data-help-field="message">
<h3>Mitteilung</h3>
<p>Die Mitteilung oder die Rechnungsinformationen aus dem Zahlteil, wenn es welche gibt.</p>
</section>
<section data-help-field="amount">
<h3>Betrag CHF (inkl. MWST)</h3>
<p>Der Rechnungsbetrag in Franken, mit MWST. Hat die Rechnung nur eine Zeile ohne Betrag, bekommt sie beim Speichern diesen Betrag. Rechnungen in Euro folgen später.</p>
</section>
<section data-help-field="payment_method_code">
<h3>Bezahlt mit</h3>
<p>Wie du die Rechnung bezahlst, z.B. Bank. Wählen füllt Haben mit dem Konto der Zahlungsart. Die Zahlungsarten pflegst du unter Stammdaten › Finanzen › Zahlungsarten.</p>
</section>
<section data-help-field="credit_account">
<h3>Haben</h3>
<p>Das Konto, von dem bezahlt wird, meist die Bank. Kommt von «Bezahlt mit» und lässt sich ändern. Haben ist nie dasselbe Konto wie eine Zeile.</p>
</section>
<section data-help-field="paid_on">
<h3>Bezahlt am</h3>
<p>Der Tag, an dem du die Rechnung bezahlt hast — nie in der Zukunft. Mit diesem Datum bucht «Journal verbuchen …» die Rechnung (Ist-Prinzip: bezahlt im Januar heisst Aufwand des neuen Jahres). Leer heisst unbezahlt; die Rechnung bleibt dann im Journal und wird nicht gebucht. Für mehrere Rechnungen auf einmal: in der Liste ankreuzen und «Bezahlt am …».</p>
</section>
<section data-help-field="line_text">
<h3>Text (Zeile)</h3>
<p>Was die Zeile ist, z.B. «Telefon Oktober». Leer ist erlaubt.</p>
</section>
<section data-help-field="line_account">
<h3>Konto (Zeile)</h3>
<p>Das Aufwandkonto der Zeile, z.B. 6510 Telefon. Nummer eintippen oder aus der Liste wählen; nur bebuchbare, aktive Konten.</p>
</section>
<section data-help-field="line_tax">
<h3>MWST (Zeile)</h3>
<p>Der Vorsteuer-Code der Zeile, z.B. VM oder VI, oder «ohne MWST». Ist dein Mandant nicht MWST-pflichtig, gibt es nur «ohne MWST» — jede Zeile wird so gespeichert.</p>
</section>
<section data-help-field="line_amount">
<h3>Betrag (Zeile)</h3>
<p>Der Teil des Rechnungsbetrags für diese Zeile, mit MWST. Alle Zeilen zusammen ergeben den Rechnungsbetrag, sonst ist die Rechnung nicht vollständig.</p>
</section>
<section data-help-field="split">
<h3>+ Zeile</h3>
<p>Eine weitere Zeile für ein zweites Konto oder einen zweiten MWST-Code auf derselben Rechnung. Die Rechnung bleibt eine Rechnung. Mit × entfernst du eine Zeile.</p>
</section>
<section data-help-field="take_default">
<h3>Als Standard übernehmen</h3>
<p>Erscheint, wenn diese Rechnung anders kontiert ist als der Standard des Kreditors. Eingeschaltet werden Konto und MWST der ersten Zeile und «Bezahlt mit» beim Speichern sein neuer Standard. Ausgeschaltet bleibt der Standard, wie er ist. Die erste vollständige Rechnung eines Kreditors ohne Standard setzt ihn von selbst.</p>
</section>
<section data-help-field="release">
<h3>Kein Doppel — übernehmen</h3>
<p>Die gesperrte Rechnung ist kein Doppel der genannten Rechnung: Sie kommt zurück ins Journal, und dieses Paar wird nicht mehr geprüft. Ist sie doch ein Doppel, nimm «Verwerfen …».</p>
</section>
<section data-help-field="recognize">
<h3>Jetzt erkennen</h3>
<p>Lässt die Erkennung für diese Rechnung (nochmals) laufen — erscheint, solange sie aussteht, fehlgeschlagen ist oder nichts gefunden hat. Sie läuft im Job «Kreditoren-Eingang», meist innert einer Minute, und füllt nur leere Felder: was du eingetragen hast, bleibt. Ungespeicherte Eingaben im Fenster gehen dabei verloren — zuerst speichern.</p>
</section>
<section data-help-field="adopt">
<h3>Kreditor übernehmen</h3>
<p>Für eine Rechnung mit einem vorgeschlagenen, noch unbekannten Kreditor. Entweder wählst du einen bestehenden Kontakt, oder du legst einen neuen an (vorausgefüllt mit dem Vorschlag). Der Kontakt wird Kreditor, die vorgeschlagene IBAN und UID kommen dazu, und die Rechnung nennt ihn.</p>
<p>Ist der Vorschlag <strong>unvollständig</strong> — z.B. ein QR-Zahlteil nur mit «Swisscom (Schweiz) AG, 3050 Bern», ohne Strasse —, öffnet sich das Formular «Neuen Kontakt anlegen» vorausgefüllt, und oben steht, was fehlt. Ergänze die markierten Felder (z.B. aus dem Briefkopf des PDF) und leg den Kontakt an. Steht die Strasse im Briefkopf mit genau demselben Namen und derselben PLZ und demselben Ort, setzt die Erkennung sie schon selbst ein.</p>
</section>
