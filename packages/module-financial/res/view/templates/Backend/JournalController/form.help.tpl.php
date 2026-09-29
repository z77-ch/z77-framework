<?php
/**
 * Help for the compound entry (Sammelbuchung, ADR-048) — capture and edit. Attached by the
 * journal controller, opened with the «i»; the form itself carries no text (owner 2026-09-29).
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
?>
<h3>Sammelbuchung</h3>
<p>Mehrere Zeilen, je ein Konto mit einem Betrag im Soll ODER im Haben. Gebucht wird erst, wenn Soll und Haben gleich sind («ausgeglichen»).</p>
<p>«Weitere Zeilen» fügt leere Zeilen an.</p>
<p><strong>Datum</strong>: muss in einem erfassten Geschäftsjahr liegen<?= $entry !== null ? ', beim Bearbeiten im selben Geschäftsjahr wie bisher' : '' ?>.</p>

<h3>MWST</h3>
<p>Der Code gehört auf die <strong>Nettozeile</strong>. Die Steuerzeile (Vorsteuer / geschuldete MWST) wird wie jede andere Zeile separat erfasst — mit dem Betrag, den das System für den Code berechnet.</p>
<?php if ($taxHints !== []): ?>
<ul>
    <?php foreach ($taxHints as $hint): ?>
    <li><?= e($hint) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($entry !== null): ?>
<h3>Bearbeiten</h3>
<p>Die Nummer <?= e(($year?->getCode() ?? '') . '/' . $entry->getNumber()) ?> bleibt. Jede Änderung wird protokolliert. Für ein anderes Geschäftsjahr: löschen und dort neu erfassen.</p>
<?php endif; ?>
