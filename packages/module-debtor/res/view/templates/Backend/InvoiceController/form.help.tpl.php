<?php
/**
 * Help for the document editor (ADR-048) — new invoice, «neu fakturieren»,
 * credit note. Attached by the trait, opened with the «i»; the form itself
 * carries no text (owner 2026-09-29).
 *
 * @var \Z77\Module\Debtor\Ui\InvoiceForm $form
 * @var \Z77\Module\Debtor\Entities\Invoice|null $document  edit only
 */
$isCredit = $form->kind === \Z77\Module\Debtor\Entities\InvoiceKind::CreditNote;
?>
<h3><?= $isCredit ? 'Gutschrift' : 'Rechnung' ?></h3>
<p>Erstellen legt das Dokument <strong>in Fakturierung</strong> an: ein Entwurf <strong>ohne Nummer</strong>, nichts ist verbucht. Solange es in Fakturierung ist, kann es beliebig oft <strong>neu fakturiert</strong> und als <strong>Vorschau</strong> (PDF ohne Nummer und ohne Zahlteil) geöffnet werden. Erst der <strong>Buchungsdruck …</strong> (in der Liste für die angekreuzten, im Dokument für dieses) vergibt die Belegnummer, verbucht und öffnet das PDF mit Nummer und QR-Zahlteil — ein gedrucktes Dokument mit Nummer ist ein Beleg. Danach ist es unveränderlich, eine Korrektur ist eine Gutschrift; das PDF lässt sich jederzeit wieder öffnen.</p>
<p>Beim Neu-Fakturieren werden Adresse und Zahlungskonditionen so übernommen, wie sie <strong>jetzt</strong> beim Debitor stehen.</p>

<?php if ($document === null && !$isCredit): ?>
<section data-help-field="contact">
<h3>Debitor</h3>
<p>Angeboten werden die aktiven Debitoren und darunter die aktiven <strong>Kontakte ohne Debitorenprofil</strong>. Wird ein solcher Kontakt gewählt, legt das Speichern der Rechnung den Debitor gleich mit an — mit der nächsten <strong>Kundennummer</strong> und den <strong>Standard-Konditionen</strong> (unter Zahlungskonditionen als «Standard» markiert); «wie beim Debitor» heisst für ihn diese Standard-Konditionen. Andere Konditionen gelten nur für diese Rechnung, der Debitor behält den Standard. Ist kein aktiver Standard markiert, erhält der Debitor die hier gewählten Konditionen, und «wie beim Debitor» geht erst, wenn das Profil besteht. Wird die Rechnung abgelehnt, entsteht auch kein Debitor und keine Kundennummer wird verbraucht.</p>
<p><strong>+ Kontakt</strong> erfasst einen neuen Kontakt mit Adresse, ohne die Rechnung zu verlassen: das Eingegebene bleibt stehen, der neue Kontakt ist danach gewählt. Debitor wird er beim Speichern der Rechnung.</p>
</section>
<?php endif; ?>

<h3>Positionen</h3>
<ul>
    <li><strong>Leistung</strong>: Menge × Preis (Menge mit höchstens drei Dezimalen, auch negativ).</li>
    <li><strong>Pauschale</strong>: ein Preis, keine Menge.</li>
    <li><strong>Text</strong>: nur Text — keine Menge, kein Preis, kein MWST-Code, kein Konto.</li>
    <li><strong>unter</strong>: die Zeile wird unter der Position darüber gedruckt (eine Ebene, z.B. der Inhalt eines Pakets zu 0.00). Nur eine Position mit Preis trägt Unterpositionen.</li>
    <li>Rabatt in Prozent, auf den Positionsbetrag. Leere Zeilen werden ignoriert.</li>
    <li>MWST-Code und Ertragskonto: angeboten werden die aktiven; ein Code, den das Dokument schon trägt, bleibt wählbar.</li>
    <li>Konto: nur ein Ertragskonto (Typ «Ertrag» im Kontenplan), das dort <strong>auf Belegen wählbar</strong> ist — Erlösminderungen, Verluste aus Forderungen und Rundungsdifferenzen sind es nicht. Ein neues Ertragskonto ist sofort wählbar; ausschalten lässt es sich im Kontenplan beim Konto. Ein Entwurf mit einem anderen Konto zeigt es weiter an, wird aber erst nach der Korrektur gespeichert oder verbucht.</li>
</ul>
<p>Das Total wird auf 5 Rappen gerundet; die Differenz erscheint als eigene Rundungszeile.</p>

<?php if ($isCredit): ?>
<h3>Leistungsdatum der Gutschrift</h3>
<p>Vorbelegt mit dem Leistungsdatum der Rechnung — die Gutschrift folgt dem MWST-Satz der ursprünglichen Leistung. Nur für eine Teilperiode ändern; ergäbe das einen anderen Satz, wird die Gutschrift abgelehnt.</p>
<p>Die Positionen sind aus der Rechnung übernommen: auf das kürzen, was gutgeschrieben wird. Beträge werden positiv erfasst — das Vorzeichen gibt die Gutschrift. Eine Gutschrift hat keinen Zahlteil.</p>
<?php else: ?>
<h3>Zahlteil</h3>
<p>Das Zahlungsziel bestimmt den QR-Zahlteil: mit <strong>QR-IBAN</strong> trägt der Beleg eine QR-Referenz aus der Rechnungsnummer, mit einer normalen <strong>IBAN</strong> keine Referenz (die Rechnung wird in der Mitteilung genannt). Referenz und Mitteilung entstehen mit der Nummer beim Buchungsdruck. «Kein Zahlteil» erstellt die Rechnung ohne Einzahlungsschein. Der Zahlteil wird mit dem Dokument festgehalten — eine spätere Änderung am Zahlungsziel ändert eine ausgestellte Rechnung nicht.</p>
<p><strong>Zahlungskonditionen</strong>: leer = die des Debitors.</p>
<?php endif; ?>
