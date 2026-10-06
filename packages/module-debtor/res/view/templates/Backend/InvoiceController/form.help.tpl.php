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
<p>Erstellen legt das Dokument <strong>in Fakturierung</strong> an: es hat eine Nummer, ist aber noch nicht verbucht. Solange es in Fakturierung ist, kann es beliebig oft <strong>neu fakturiert</strong> werden — die Nummer bleibt. Verbucht wird erst mit «Definitiv stellen» in der Liste; danach ist es unveränderlich, eine Korrektur ist eine Gutschrift.</p>
<p>Beim Neu-Fakturieren werden Adresse und Zahlungskonditionen so übernommen, wie sie <strong>jetzt</strong> beim Debitor stehen.</p>

<h3>Positionen</h3>
<ul>
    <li><strong>Leistung</strong>: Menge × Preis (Menge mit höchstens drei Dezimalen, auch negativ).</li>
    <li><strong>Pauschale</strong>: ein Preis, keine Menge.</li>
    <li><strong>Text</strong>: nur Text — keine Menge, kein Preis, kein MWST-Code, kein Konto.</li>
    <li><strong>unter</strong>: die Zeile wird unter der Position darüber gedruckt (eine Ebene, z.B. der Inhalt eines Pakets zu 0.00). Nur eine Position mit Preis trägt Unterpositionen.</li>
    <li>Rabatt in Prozent, auf den Positionsbetrag. Leere Zeilen werden ignoriert.</li>
    <li>MWST-Code und Ertragskonto: angeboten werden die aktiven; ein Code, den das Dokument schon trägt, bleibt wählbar.</li>
</ul>
<p>Das Total wird auf 5 Rappen gerundet; die Differenz erscheint als eigene Rundungszeile.</p>

<?php if ($isCredit): ?>
<h3>Leistungsdatum der Gutschrift</h3>
<p>Vorbelegt mit dem Leistungsdatum der Rechnung — die Gutschrift folgt dem MWST-Satz der ursprünglichen Leistung. Nur für eine Teilperiode ändern; ergäbe das einen anderen Satz, wird die Gutschrift abgelehnt.</p>
<p>Die Positionen sind aus der Rechnung übernommen: auf das kürzen, was gutgeschrieben wird. Beträge werden positiv erfasst — das Vorzeichen gibt die Gutschrift. Eine Gutschrift hat keinen Zahlteil.</p>
<?php else: ?>
<h3>Zahlteil</h3>
<p>Das Zahlungsziel bestimmt den QR-Zahlteil: mit <strong>QR-IBAN</strong> trägt der Beleg eine QR-Referenz aus der Rechnungsnummer, mit einer normalen <strong>IBAN</strong> keine Referenz (die Rechnung wird in der Mitteilung genannt). «Kein Zahlteil» erstellt die Rechnung ohne Einzahlungsschein. Der Zahlteil wird mit dem Dokument festgehalten — eine spätere Änderung am Zahlungsziel ändert eine ausgestellte Rechnung nicht.</p>
<p><strong>Zahlungskonditionen</strong>: leer = die des Debitors.</p>
<?php endif; ?>
