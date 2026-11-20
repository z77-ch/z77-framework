<?php
/**
 * Help for the mandator page (ADR-048; a PAGE — «? Hilfe» in the top bar, F1 in a field) — a
 * general part, then one section per form field (`data-help-field` = the field's `name`;
 * the account fields are `account_` + the key in snake_case, `Mandator::accountField()`).
 * The page itself keeps only short hints. Also the KI-Assistent's knowledge (ADR-055):
 * «Firmenadresse ändern», «Logo», «UID», «MWST-pflichtig», «Konten für Debitoren und MWST».
 * The logo window has its own help (`logo.help`, 2026-11-20); here only the block.
 */
?>
<h3>Mandant: deine Firma</h3>
<p>Der <strong>Mandant</strong> ist deine eigene Firma: Adresse und Logo für Rechnungen und E-Mails, die UID, ob die Firma <strong>mehrwertsteuerpflichtig</strong> ist, und die <strong>Konten</strong> für Debitoren, MWST und die Jahreseröffnung.</p>
<p>Name, Adresse und Logo stehen oben auf Rechnungen, Offerten und Mahnungen; der Name auch im Kopf der Auswertungen, die Firmenangaben am Fuss jeder E-Mail. Es gibt genau einen Mandanten. Solange keiner erfasst ist, heisst der Knopf «Mandant anlegen» — der erste Klick legt ihn an.</p>
<p>Die vier Reiter oben (Briefkopf, UID und MWST, Konten, Belege) gehören zu <strong>einem</strong> Formular — «Speichern» speichert alle. Nicht hier: die Bankverbindung (IBAN) und der Zahlungsempfänger des QR-Einzahlungsscheins — die stehen im <strong>Zahlungsziel</strong> (Stammdaten › Auftragswesen › Zahlungsziele). Dass der Kontoinhaber dort anders heisst als die Firma hier, ist kein Fehler.</p>

<h3>Briefkopf: Adresse und Logo</h3>
<section data-help-field="name">
<h4>Name</h4>
<p>Der Firmenname, wie er gedruckt wird — Pflicht. Er steht auch am Fuss jeder E-Mail und als Aussteller im Kopf der Auswertungen.</p>
</section>
<section data-help-field="address_suffix_one">
<h4>Adresszusatz 1 und 2</h4>
<p>Freie Zeilen zwischen Name und Strasse, z.B. «Abteilung Buchhaltung» oder «c/o Muster Treuhand AG».</p>
</section>
<section data-help-field="street">
<h4>Strasse, Hausnummer, PLZ, Ort, Land</h4>
<p>Die Postadresse. Land als ISO-Code (CH, DE, …), Pflicht. Bleibt im Zahlungsziel die Adresse des Zahlungsempfängers leer, nimmt der QR-Einzahlungsschein diese Adresse.</p>
</section>
<section data-help-field="email">
<h4>E-Mail, Telefon, Website</h4>
<p>Kontaktangaben für den Briefkopf und den Fuss der E-Mails, z.B. info@example.ch, +41 44 123 45 67, www.example.ch. Nur ausgefüllte Angaben werden gedruckt.</p>
</section>
<section data-help-field="logos">
<h4>Firmenlogo</h4>
<p>Unter «Firmenlogo» siehst du, welches Logo heute wo erscheint: auf den <strong>Belegen (PDF)</strong>, oben in den <strong>E-Mails</strong>, auf der <strong>Anmeldeseite</strong> des Backends und auf der <strong>Website</strong>. Der Knopf «Firmenlogo …» öffnet das Fenster zum Hochladen — ein Bild (PNG, JPG oder GIF) genügt, die passenden Grössen macht das System selbst. Mehr dazu in der Hilfe des Fensters.</p>
</section>

<h3>UID und Mehrwertsteuerpflicht</h3>
<section data-help-field="uid">
<h4>UID</h4>
<p>Die Unternehmens-Identifikationsnummer, z.B. CHE-123.456.789 — wie getippt, das System bringt sie in die richtige Form und prüft die Prüfziffer. «MWST» nicht anhängen: das kommt beim Drucken dazu, wenn die Firma mehrwertsteuerpflichtig ist.</p>
</section>
<section data-help-field="liable_to_vat">
<h4>Mehrwertsteuerpflichtig</h4>
<p>Eingeschaltet: Rechnungen, Gutschriften, Offerten und Auftragsbestätigungen werden mit MWST berechnet, verbucht und gedruckt. Ausgeschaltet: ohne MWST, und unter dem Total steht der «Hinweis ohne MWST». Eine bereits erstellte Rechnung bleibt, wie sie ist. Das Journal und das Belegjournal kennen den Schalter noch nicht — dort setzt du bei Bedarf selbst «ohne MWST».</p>
</section>
<section data-help-field="vat_exempt_note">
<h4>Hinweis ohne MWST</h4>
<p>Der Satz unter dem Total, solange der Schalter aus ist, z.B. «Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig.» Bei eingeschaltetem Schalter wird er nicht gedruckt.</p>
</section>

<h3>Konten für Debitoren, MWST und Eröffnung</h3>
<p>Die Kontonummern, auf die die Module buchen — beim ersten Speichern mit dem KMU-Kontenrahmen vorbelegt. Neben jedem Feld: <strong>ok</strong> (das Konto existiert und ist bebuchbar), <strong>prüfen</strong> (fehlt, ist eine Gruppe oder inaktiv — eine Buchung darauf wird abgelehnt) oder <strong>ungeprüft</strong> (ohne Buchhaltungsmodul). Leer = nicht hinterlegt: erst eine Buchung, die das Konto braucht, wird mit Hinweis abgelehnt.</p>
<section data-help-field="account_receivable">
<h4>Debitoren-Sammelkonto</h4>
<p>Die offenen Rechnungen (KMU 1100). Nur die Debitorenbuchhaltung bucht darauf; der Jahresabschluss vergleicht es mit den offenen Posten.</p>
</section>
<section data-help-field="account_discount">
<h4>Skonto</h4>
<p>Ein Abzug für Zahlung innert Skontofrist (KMU 3800 Erlösminderungen).</p>
</section>
<section data-help-field="account_loss">
<h4>Debitorenverlust</h4>
<p>Eine Forderung, die abgeschrieben wird (KMU 3805).</p>
</section>
<section data-help-field="account_rounding">
<h4>Rundungsdifferenz Rechnung</h4>
<p>Die Rundung des Rechnungstotals auf 5 Rappen (KMU 3809) — fällt bei fast jeder Rechnung in Franken an, also nicht leer lassen.</p>
</section>
<section data-help-field="account_dunning_fee">
<h4>Mahngebühr</h4>
<p>Der Ertrag aus Mahngebühren (KMU 6950). Muss ein Ertragskonto sein. Leer lassen, wenn nie gemahnt wird.</p>
</section>
<section data-help-field="account_vat_input_material">
<h4>Vorsteuer Material, Waren, Dienstleistungen</h4>
<p>Die Vorsteuer der Codes «Vorsteuer Material / Dienstleistungen», z.B. VM (KMU 1170).</p>
</section>
<section data-help-field="account_vat_input_other">
<h4>Vorsteuer Investitionen, übriger Betriebsaufwand</h4>
<p>Die Vorsteuer der Codes «Vorsteuer Investitionen / übriger Aufwand», z.B. VI (KMU 1171).</p>
</section>
<section data-help-field="account_vat_owed">
<h4>Geschuldete MWST (Umsatzsteuer)</h4>
<p>Die MWST auf Verkäufen — Normalsatz, reduzierter Satz und Sondersatz (KMU 2200).</p>
</section>
<section data-help-field="account_opening_balance">
<h4>Eröffnungsbilanz</h4>
<p>Das Gegenkonto der Eröffnung eines neuen Geschäftsjahres (KMU 9100): gegen dieses Konto werden die Schlusssalden des Vorjahres vorgetragen.</p>
</section>
<section data-help-field="account_annual_result">
<h4>Jahresgewinn/-verlust</h4>
<p>Wohin die Eröffnung den Gewinn oder Verlust des Vorjahres bucht (KMU 2979). Die Verteilung auf Reserven usw. buchst du danach selbst.</p>
</section>
<section data-help-field="account_private">
<h4>Privat (Privatbezug / Privateinlage)</h4>
<p>Das Konto für Geld, das du privat aus dem Geschäft nimmst oder ins Geschäft einlegst. Unter Finanzen › Bankkonto buchst du eine Bankbewegung mit «Privat» auf dieses Konto. Kein Vorschlag — trag die Nummer aus deinem Kontenplan ein. Leer = «Privat» steht dort nicht zur Wahl.</p>
</section>
<section data-help-field="account_bank_transfer">
<h4>Übertrag eigene Bankkonten</h4>
<p>Das Durchgangskonto für Geld, das du von einem eigenen Geschäftskonto auf ein anderes überträgst: beide Seiten werden darauf gebucht, am Schluss steht es auf null. Bleibt ein Saldo stehen, fehlt eine Seite — das meldet der Abgleich unter Finanzen › Bankkonto und sperrt den Abschluss. Kein Vorschlag — trag die Nummer aus deinem Kontenplan ein.</p>
</section>

<h3>Belege: Betreffname neuer Aufträge und Rechnungen</h3>
<section data-help-field="default_subject_label">
<h4>Betreffname</h4>
<p>Womit jeder neue Auftrag und jede neue Rechnung den Betreff beginnt, z.B. «Projekt» oder «Referenz» — im Beleg änderbar. Hat ein Debitor einen eigenen Betreffnamen, gilt seiner. Leer = keiner.</p>
</section>
