<?php
/**
 * Help for the document mask (ADR-048) — new invoice, a draft (the editor),
 * a booked document (read-only, owner 2026-10-21), credit note. Attached by
 * the trait, opened with the «i»; the form itself carries no text (owner
 * 2026-09-29).
 *
 * @var \Z77\Module\Debtor\Ui\InvoiceForm $form
 * @var \Z77\Module\Debtor\Entities\Invoice|null $document  edit only
 * @var string|null $editRefusal  edit only: set when the document is final (the mask is read-only)
 */
$isCredit = $form->kind === \Z77\Module\Debtor\Entities\InvoiceKind::CreditNote;
$isFinal  = ($editRefusal ?? null) !== null;
// Without VAT (the mandator is not liable, owner 2026-10-21): no MWST wording, no price mode.
$vat = $form->withVat;
?>
<h3 data-help-title="Rechnung und Gutschrift"><?= !empty($isCredit) ? 'Gutschrift' : 'Rechnung' ?></h3>
<?php if ($isFinal): ?>
<?php if ($document !== null && $document->isInJournal()): ?>
<p>Das Dokument ist <strong>fakturiert</strong>: mit Nummer gedruckt, ein Beleg — es steht im <strong>Faktura-Journal</strong> und ist <strong>noch nicht verbucht</strong>. Sein PDF ist beim Fakturieren unveränderlich abgelegt worden; <strong>PDF</strong> öffnet genau diese Datei. Die kurze <strong>ID</strong> neben der Nummer kennzeichnet diese Fassung eindeutig. Zahlungen, Skonto und Gutschriften gibt es erst, wenn das Journal verbucht ist (Aufträge › Faktura-Journal › «Journal verbuchen …»).</p>
<?php else: ?>
<p>Das Dokument ist <strong>verbucht</strong>: mit Nummer gedruckt, ein Beleg. Die Maske zeigt es so, wie es ausgestellt wurde — nichts lässt sich ändern, eine Korrektur ist eine <strong>Gutschrift</strong>. In der Aktionszeile: <strong>PDF</strong> öffnet den Beleg erneut (neuer Tab, jederzeit dasselbe PDF)<?php if (!$isCredit): ?>, <strong>Zahlung erfassen …</strong> bucht einen Zahlungseingang, Skonto oder Verlust, solange etwas offen ist, <strong>Gutschrift erstellen …</strong> legt eine Gutschrift zu dieser Rechnung an (Positionen vorbelegt)<?php endif; ?>. Unter den Positionen stehen die Fakten: die Buchung (Klick öffnet den Journaleintrag), die Zahlungen mit <strong>ändern</strong>, der offene Betrag, Mahnungen, Gutschriften und der Zahlteil, wie er gedruckt wurde.</p>
<?php endif; ?>
<?php elseif ($document !== null && $document->isInJournal()): ?>
<p>Das Dokument ist <strong>fakturiert</strong> und steht im <strong>Faktura-Journal</strong> — noch nicht verbucht. Darum lässt es sich noch korrigieren, ohne Gutschrift. <strong>Speichern</strong> prüft zuerst, was die Änderung ist, und nennt es, bevor etwas gespeichert wird:</p>
<ul>
    <li><strong>Neue Version mit gleicher Nummer</strong> — Betrag, MWST, Kunde und Monat des Rechnungsdatums bleiben gleich (z.B. ein Text, ein Datum im gleichen Monat, die Zahlungskonditionen). Nummer und QR-Referenz bleiben; die Version zählt hoch und bekommt eine neue <strong>ID</strong>. Das PDF der früheren Version bleibt archiviert (unter den Positionen bei <strong>Versionen</strong>).</li>
    <li><strong>Ersatz mit neuer Nummer</strong> — Betrag, MWST, Kunde oder der Monat ändern sich. Es entsteht eine neue Rechnung mit neuer Nummer (aus dem Monat ihres Rechnungsdatums); sie druckt klein «ersetzt Rechnung … (ID …)». Die bisherige bleibt mit ihrer Nummer unter <strong>Ersetzt</strong>; noch wartende E-Mails zu ihr werden abgebrochen.</li>
</ul>
<p>Mit <strong>… bestätigen</strong> im Hinweis wird gespeichert; ändert sich die Einstufung inzwischen, fragt die Maske nochmals. Eine Rechnung, die nicht mehr gilt, lässt sich mit <strong>Rechnung löschen …</strong> (mit Grund) löschen — sie bleibt mit ihrer Nummer unter <strong>Gelöscht</strong>. <strong>Senden …</strong> öffnet PDF, ZIP und E-Mail dieser Rechnung. Zahlungen gibt es erst nach «Journal verbuchen».</p>
<?php else: ?>
<p>Erstellen legt das Dokument <strong>in Fakturierung</strong> an: ein Entwurf <strong>ohne Nummer</strong>, nichts ist verbucht. Solange es in Fakturierung ist, kann es beliebig oft <strong>bearbeitet</strong> und als <strong>Vorschau</strong> (PDF ohne Nummer und ohne Zahlteil) geöffnet werden. Erst <strong>Fakturieren …</strong> (in der Liste für die angekreuzten, im Dokument für dieses) vergibt die Belegnummer, legt das PDF mit Nummer und QR-Zahlteil unveränderlich ab und öffnet es — ein gedrucktes Dokument mit Nummer ist ein Beleg. Es steht danach im <strong>Faktura-Journal</strong>; verbucht wird es mit «Journal verbuchen». Das PDF lässt sich jederzeit wieder öffnen (immer dieselbe abgelegte Datei), die Maske zeigt den Beleg dann nur noch an.</p>
<p>Solange nichts geändert ist, steht <strong>Speichern</strong> ruhig da und speichert nichts. Beim Speichern eines Entwurfs werden Adresse und Zahlungskonditionen so übernommen, wie sie <strong>jetzt</strong> beim Debitor stehen. Das Zahlungsziel muss aktiv sein; der Zahlteil (Konto, Referenzart, Zahlungsempfänger) wird beim Fakturieren nochmals so übernommen, wie er dann beim Zahlungsziel und beim Mandanten steht — was ihm fehlt, steht unter den Positionen bei <strong>Vor dem Fakturieren beheben</strong>. Ein Entwurf, der nicht mehr gebraucht wird, lässt sich im Dokument mit <strong>Entwurf löschen …</strong> entfernen.</p>
<?php endif; ?>

<section data-help-field="contact">
<h3>Kunde</h3>
<?php if (!$isCredit && $document !== null && !$isFinal && $document->isInJournal()): ?>
<p>Eine fakturierte Rechnung kann einem <strong>anderen Kunden</strong> zugeordnet werden (<strong>Kunde wechseln</strong>) — das ist immer ein <strong>Ersatz mit neuer Nummer</strong>, denn die Kundennummer steckt in der QR-Referenz.</p>
<?php endif; ?>
<?php if ($document === null && !$isCredit): ?>
<p><strong>Kunde wählen …</strong> (danach <strong>Kunde wechseln</strong>) öffnet die Suche: nach Name, Ort, Kundennummer oder E-Mail suchen, dann <strong>Übernehmen</strong>. Wer nicht gefunden wird, lässt sich dort mit <strong>+ Neuer Kontakt</strong> erfassen — das Eingegebene der Rechnung bleibt stehen.</p>
<p>Gefunden werden die aktiven Debitoren und die aktiven <strong>Kontakte ohne Debitorenprofil</strong> («neu»). Ein solcher Kontakt wird erst Kunde, wenn er <strong>Debitor</strong> ist: <strong>Übernehmen</strong> (und <strong>+ Neuer Kontakt</strong> nach dem Speichern) fragt zuerst <strong>Debitor anlegen</strong> — mit <strong>Preisen</strong> netto oder brutto und den <strong>Zahlungskonditionen</strong> (vorgeschlagen: die als «Standard» markierten); die nächste <strong>Kundennummer</strong> wird dabei vergeben. <strong>Abbrechen</strong> wählt keinen Kunden. So geht keine Rechnung im falschen Preismodus hinaus, die gutgeschrieben und neu erstellt werden müsste.</p>
<p>Mit dem Kunden übernimmt die Rechnung <?= $vat ? 'dessen <strong>Preise</strong> (netto oder brutto, siehe dort) und ' : '' ?>dessen Zahlungskonditionen («wie beim Debitor»).<?= $vat ? '' : ' Der Mandant ist nicht mehrwertsteuerpflichtig: das Dokument wird <strong>ohne MWST</strong> gerechnet und gedruckt.' ?></p>
<?php else: ?>
<p>Der Kunde eines bestehenden Dokuments<?= $isCredit ? ' (die Gutschrift gehört zum Kunden der Rechnung)' : '' ?> bleibt; er lässt sich hier nicht wechseln.</p>
<?php endif; ?>
<p>Im Titel stehen die <strong>Kundennummer</strong> und die Telefonnummer des Kontakts.</p>
<p><strong>Rechnung</strong> zeigt die Adresse, an die das Dokument geht: die Rechnungsadresse des Kontakts, sonst seine Hauptadresse, sonst die erste. Sie wird bei <strong>jedem Speichern</strong> so übernommen, wie sie dann beim Kontakt steht — ändern lässt sie sich nur beim Kontakt, nicht in der Rechnung.</p>
</section>

<section data-help-field="subject_label">
<h3>Betreffname</h3>
<p>Freiwillig, ein kurzes Wort (höchstens 80 Zeichen) vor dem Betreff — zum Beispiel «Projekt», «Referenz» oder «Ihre Bestellnummer». Gedruckt wird «Betreffname: Betreff» auf einer Zeile; ohne Betreff wird nichts gedruckt. Ein Doppelpunkt am Ende wird weggelassen, der Druck setzt ihn selbst. Eine neue Rechnung beginnt mit dem Betreffnamen des Debitors (Debitor bearbeiten), sonst mit dem des Mandanten (Mandant › Belege); solange du ihn nicht änderst, folgt er einem anderen Kunden. Die Liste schlägt die schon verwendeten Betreffnamen vor.</p>
</section>

<section data-help-field="subject">
<h3>Betreff</h3>
<p>Freiwillig, eine Zeile (höchstens 200 Zeichen): wird auf dem Dokument direkt unter dem Titel gedruckt — zum Beispiel das Projekt oder die Bestellung des Kunden. Eine Rechnung aus einem Auftrag trägt hier «Auftrag Nr · Betreff».<?php if ($document !== null): ?> <strong>Herkunft</strong> darunter sagt, woher das Dokument kommt und wer es wann erfasst hat.<?php endif; ?></p>
</section>

<section data-help-field="service_from">
<h3>Leistung von / bis</h3>
<p>Wann geleistet oder geliefert wurde.<?php if ($vat): ?> Der <strong>MWST-Satz</strong> jeder Zeile richtet sich nach «von» (leer: nach dem <?= $isCredit ? 'Gutschriftsdatum' : 'Rechnungsdatum' ?>).<?php endif; ?> «bis» nur für einen Zeitraum.</p>
</section>

<?php if ($vat): ?>
<section data-help-field="price_mode">
<h3>Preise netto / brutto</h3>
<p>Ob die Preise der Positionen die MWST enthalten. Eine neue Rechnung beginnt mit dem Preismodus des Debitors; hier lässt er sich für dieses Dokument ändern. Bereits erfasste Preise werden dabei <strong>sofort umgerechnet</strong> — jede Zeile mit ihrem MWST-Satz am Leistungsdatum, auf den Rappen gerundet (z.B. 20.00 netto → 21.62 brutto bei 8.1 %). Ohne JavaScript rechnet das Speichern um.</p>
</section>
<?php endif; ?>

<section data-help-field="terms">
<h3>Zahlungskonditionen</h3>
<p>Die Zahlungsfrist und ein allfälliges Skonto. «wie beim Debitor» = die Konditionen, die beim Debitor hinterlegt sind (die Auswahl nennt sie) — so, wie sie beim Speichern gelten. Eine andere Wahl gilt nur für dieses Dokument. <strong>Debitor anpassen</strong> ändert die Konditionen, die beim Kunden hinterlegt sind (für alle künftigen Dokumente); nach dem Schliessen nennt die Auswahl die neuen.</p>
</section>

<h3>Positionen</h3>
<ul>
    <li><strong>Leistung</strong>: Menge × Preis (Menge mit höchstens drei Dezimalen, auch negativ).</li>
    <li><strong>Pauschale</strong>: ein Preis, keine Menge.</li>
    <li><strong>Text</strong>: nur Text — keine Menge, kein Preis, <?= $vat ? 'kein MWST-Code, ' : '' ?>kein Konto.</li>
    <li><strong>unter</strong>: die Zeile wird unter der Position darüber gedruckt (eine Ebene, z.B. der Inhalt eines Pakets zu 0.00). Nur eine Position mit Preis trägt Unterpositionen.</li>
    <li>Rabatt in Prozent, auf den Positionsbetrag. Leere Zeilen werden ignoriert.</li>
<?php if ($vat): ?>
    <li>MWST-Code und Ertragskonto: angeboten werden die aktiven; ein Code, den das Dokument schon trägt, bleibt wählbar.</li>
<?php endif; ?>
    <li>Konto: nur ein Ertragskonto (Typ «Ertrag» im Kontenplan), das dort <strong>auf Belegen wählbar</strong> ist — Erlösminderungen, Verluste aus Forderungen und Rundungsdifferenzen sind es nicht. Ein neues Ertragskonto ist sofort wählbar; ausschalten lässt es sich im Kontenplan beim Konto. Ein Entwurf mit einem anderen Konto zeigt es weiter an, wird aber erst nach der Korrektur gespeichert oder verbucht.<?php if ($isCredit): ?> Eine Gutschrift folgt der Rechnung: ein Ertragskonto der Rechnung bleibt wählbar, auch wenn es im Kontenplan inzwischen nicht mehr auf Belegen wählbar ist.<?php endif; ?></li>
</ul>
<p>Das Total wird auf 5 Rappen gerundet; die Differenz erscheint als eigene Rundungszeile.</p>

<?php if ($isCredit): ?>
<h3>Leistungsdatum der Gutschrift</h3>
<p>Vorbelegt mit dem Leistungsdatum der Rechnung<?= $vat ? ' — die Gutschrift folgt dem MWST-Satz der ursprünglichen Leistung. Nur für eine Teilperiode ändern; ergäbe das einen anderen Satz, wird die Gutschrift abgelehnt' : '; die Rechnung war ohne MWST, die Gutschrift ist es auch' ?>.</p>
<p>Die Positionen sind aus der Rechnung übernommen: auf das kürzen, was gutgeschrieben wird. Beträge werden positiv erfasst — das Vorzeichen gibt die Gutschrift. Eine Gutschrift hat keinen Zahlteil.</p>
<?php else: ?>
<section data-help-field="target">
<h3>Zahlungsziel und Zahlteil</h3>
<p>Das Zahlungsziel bestimmt den QR-Zahlteil: mit <strong>QR-IBAN</strong> trägt der Beleg eine QR-Referenz aus der Rechnungsnummer, mit einer normalen <strong>IBAN</strong> keine Referenz (die Rechnung wird in der Mitteilung genannt). Referenz und Mitteilung entstehen mit der Nummer beim Fakturieren. «Kein Zahlteil» erstellt die Rechnung ohne Einzahlungsschein. Der Zahlteil wird mit dem Dokument festgehalten — eine spätere Änderung am Zahlungsziel ändert eine ausgestellte Rechnung nicht.</p>
</section>
<?php endif; ?>
