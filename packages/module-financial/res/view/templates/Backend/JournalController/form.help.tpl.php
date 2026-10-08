<?php
/**
 * Help for the compound entry (Sammelbuchung, ADR-048) — capture and edit. Attached by the
 * journal controller, opened with «? Hilfe» in the top bar, the «i» of a window, or F1 in a
 * field; the form itself carries no text (owner 2026-09-29).
 *
 * Structure (owner 2026-10-08): a general part first, then one section per field, marked
 * `data-help-field="<field name>"` (the `name` without `[]`): date, text, account, line_text,
 * debit, credit, tax_code. Here Soll / Haben are the row's AMOUNTS — the account is its own
 * column — unlike the one-line form, where they are accounts.
 *
 * @var \Z77\Module\Financial\Ui\ManualEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = capture
 */
$taxHints = [];
foreach ($form->rows() as $i => $row) {
    if ($form->taxHint($i) !== '') {
        $taxHints[] = 'Zeile ' . ($i + 1) . ': ' . $form->taxHint($i);
    }
}
$post = $entry !== null ? 'Speichern' : 'Buchen';
?>
<h3>Sammelbuchung</h3>
<p>Mehrere Zeilen, je ein Konto mit einem Betrag im Soll ODER im Haben. Gebucht wird erst, wenn Soll und Haben gleich sind («ausgeglichen») — die Total-Zeile zeigt die Differenz.</p>
<p>«Weitere Zeilen» fügt leere Zeilen an; leere Zeilen werden beim <?= e($post) ?> ignoriert. Mindestens zwei Zeilen.</p>
<p>«<?= e($post) ?>» prüft alles auf einmal: gültig → die Buchung steht im Journal<?= $entry === null ? ' mit der nächsten Nummer des Geschäftsjahres' : '' ?>; sonst bleiben die markierten Felder stehen und nichts wird gebucht.</p>
<p>Tipp: F1 in einem Feld öffnet die Hilfe zu genau diesem Feld.</p>

<section data-help-field="date">
<h3>Datum</h3>
<p>Das Datum des Belegs. Es muss in einem erfassten Geschäftsjahr liegen<?= $entry !== null ? ', beim Bearbeiten im selben Geschäftsjahr wie bisher' : ' — das Geschäftsjahr folgt dem Datum' ?>.</p>
</section>

<section data-help-field="text">
<h3>Buchungstext</h3>
<p>Was die ganze Buchung ist, z.B. «Lohnzahlung Oktober» oder «Büromaterial Papeterie Muster». Die Journalsuche findet die Buchung über diesen Text.</p>
</section>

<section data-help-field="account">
<h3>Konto</h3>
<p>Die Kontonummer der Zeile, z.B. «1020 Bankguthaben» oder «6500 Büromaterial» — die Vorschlagsliste zeigt die bebuchbaren Konten. Gruppen sind nicht bebuchbar.</p>
</section>

<section data-help-field="line_text">
<h3>Text der Zeile</h3>
<p>Freiwillig: was diese eine Zeile ist, wenn es vom Buchungstext abweicht — z.B. «AHV Arbeitnehmeranteil».</p>
</section>

<section data-help-field="debit">
<h3>Soll</h3>
<p>Der Betrag, um den das Konto der Zeile <strong>zunimmt</strong> (Aufwand, Aktiven), z.B. «450.00». Pro Zeile Soll ODER Haben, nie beides; kein negativer Betrag — dafür die andere Seite buchen.</p>
</section>

<section data-help-field="credit">
<h3>Haben</h3>
<p>Der Betrag, um den das Konto der Zeile <strong>abnimmt</strong> (Bezahlung, Ertrag), z.B. «486.45» auf «1020 Bankguthaben». Die Summe aller Haben muss der Summe aller Soll entsprechen.</p>
</section>

<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Der Code gehört auf die <strong>Nettozeile</strong>, z.B. «VM» auf der Aufwandzeile. Die Steuerzeile (Vorsteuer / geschuldete MWST) wird wie jede andere Zeile separat erfasst — mit dem Betrag, den das System für den Code berechnet.</p>
<?php if ($taxHints !== []): ?>
<ul>
    <?php foreach ($taxHints as $hint): ?>
    <li><?= e($hint) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<?php if ($entry !== null): ?>
<h3>Bearbeiten</h3>
<p>Die Nummer <?= e(($year?->getCode() ?? '') . '/' . $entry->getNumber()) ?> bleibt. Jede Änderung wird protokolliert. Für ein anderes Geschäftsjahr: löschen und dort neu erfassen.</p>
<?php endif; ?>
