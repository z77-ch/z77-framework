<?php
/**
 * Help for the one-line entry (ADR-048) — capture and edit. Attached by the journal
 * controller, opened with the «i»; the form itself carries no text (owner 2026-09-29).
 *
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = capture
 */
use Z77\Module\Financial\Ui\OneLineEntryForm;
?>
<h3>Einzelbuchung</h3>
<p>Eine Zeile: <strong>Soll</strong> an <strong>Haben</strong>, ein Betrag. Konten werden mit ihrer Nummer erfasst — die Vorschlagsliste zeigt die bebuchbaren Konten.</p>
<p><strong>Datum</strong>: muss in einem erfassten Geschäftsjahr liegen<?= $entry !== null ? ', beim Bearbeiten im selben Geschäftsjahr wie bisher' : '' ?>.</p>

<h3>MWST</h3>
<p>«MwSt» öffnet Code und Steuerbetrag. Der Code steht auf der Nettoseite; die Steuerzeile bucht das System selbst.</p>
<p><strong>Steuerbetrag leer</strong> = aus dem Betrag berechnet, mit dem Satz am Buchungsdatum.</p>
<p><strong>Steuerbetrag laut Beleg</strong>: darf um <?= (int) OneLineEntryForm::TAX_CORRECTION_PERCENT ?> % der berechneten Steuer daneben liegen — mindestens <?= e(OneLineEntryForm::TAX_CORRECTION_MIN) ?>, höchstens <?= e(OneLineEntryForm::TAX_CORRECTION_MAX) ?> (Rundung laut Beleg). Mehr weist die Prüfung zurück.</p>
<?php if ($form->vatHint() !== ''): ?>
<p><strong>Diese Buchung</strong>: <?= e($form->vatHint()) ?></p>
<?php endif; ?>

<?php if ($entry !== null): ?>
<h3>Bearbeiten</h3>
<p>Die Nummer <?= e(($year?->getCode() ?? '') . '/' . $entry->getNumber()) ?> bleibt. Jede Änderung wird protokolliert. Für ein anderes Geschäftsjahr: löschen und dort neu erfassen.</p>
<p>Passt die Buchung nicht in eine Zeile (mehrere Konten), «Als Sammelbuchung bearbeiten».</p>
<?php endif; ?>
