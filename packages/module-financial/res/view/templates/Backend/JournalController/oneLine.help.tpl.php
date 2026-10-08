<?php
/**
 * Help for the one-line entry (ADR-048) — capture and edit. Attached by the journal
 * controller, opened with «? Hilfe» in the top bar, the «i» of a window, or F1 in a field;
 * the form itself carries no text (owner 2026-09-29).
 *
 * Structure (owner 2026-10-08): a general part first, then one section per field, marked
 * `data-help-field="<field name>"` — the help window opens at the section of the field focused
 * last (core.js `_Z77.core.help`, `fetch.md` HELP-001). The keys are the form's `name`s:
 * debit, credit, date, text, amount, vat (the switch), tax_code, tax_amount.
 *
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = capture
 */
use Z77\Module\Financial\Ui\OneLineEntryForm;
?>
<h3>Einzelbuchung</h3>
<p>Eine Zeile: <strong>Soll</strong> an <strong>Haben</strong>, ein Betrag. Konten werden mit ihrer Nummer erfasst — die Vorschlagsliste zeigt die bebuchbaren Konten.</p>
<p><?= $entry !== null ? '«Speichern»' : '«Buchen»' ?> prüft die ganze Zeile. Ist alles gültig, steht die Buchung sofort im Journal<?= $entry === null ? ' und erhält die nächste Nummer des Geschäftsjahres (lückenlos)' : '' ?>; sonst bleibt das Formular mit den markierten Feldern stehen und nichts wird gebucht.</p>
<p>Tipp: F1 in einem Feld öffnet die Hilfe zu genau diesem Feld.</p>

<section data-help-field="debit">
<h3>Soll</h3>
<p>Das Konto, das <strong>zunimmt</strong> (Aufwand, Aktiven): die Kontonummer, z.B. «6500 Büromaterial» beim Einkauf oder «1020 Bankguthaben» bei einem Zahlungseingang.</p>
<p>Nur bebuchbare Konten des Kontenplans — die Vorschlagsliste zeigt Nummer und Name.</p>
</section>

<section data-help-field="date">
<h3>Datum</h3>
<p>Das Datum des Belegs. Es muss in einem erfassten Geschäftsjahr liegen<?= $entry !== null ? ', beim Bearbeiten im selben Geschäftsjahr wie bisher' : ' — das Geschäftsjahr folgt dem Datum' ?>.</p>
<p>Ein abgeschlossenes Geschäftsjahr nimmt keine Buchung an.</p>
</section>

<section data-help-field="text">
<h3>Text</h3>
<p>Was gebucht wird, kurz und wiederfindbar — z.B. «Büromaterial Papeterie Muster» oder «Miete Oktober». Die Journalsuche findet die Buchung über diesen Text.</p>
</section>

<section data-help-field="credit">
<h3>Haben</h3>
<p>Das Konto, das <strong>abnimmt</strong> (Bezahlung, Ertrag): z.B. «1020 Bankguthaben» beim Bezahlen per Bank oder «3200 Warenertrag» bei einem Verkauf.</p>
<p>Soll und Haben müssen verschiedene Konten sein. Gegenrichtung (z.B. Rückerstattung): Soll und Haben tauschen, nicht den Betrag negativ machen.</p>
</section>

<section data-help-field="amount">
<h3>Betrag</h3>
<p>Der Betrag in der Basiswährung, grösser als 0, mit Punkt als Dezimalzeichen — z.B. «450.00» oder «1250.5». Mit MWST ist es der Betrag <strong>laut Beleg</strong> (inkl. MWST); die Steuer rechnet das System heraus.</p>
</section>

<section data-help-field="vat">
<h3>MwSt</h3>
<p>Der Schalter «MwSt» öffnet MWST-Code und Steuerbetrag. Der Code steht auf der Nettoseite; die Steuerzeile (Vorsteuer bzw. geschuldete MWST) bucht das System beim <?= $entry !== null ? 'Speichern' : 'Buchen' ?> selbst.</p>
<p>Ohne MWST: Schalter aus lassen. Sind Soll und Haben beides Aufwand- oder Ertragskonten, ist die Nettoseite nicht eindeutig — dann als Sammelbuchung erfassen.</p>
</section>

<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Der Steuercode des Belegs, z.B. «VM 8.1 %» für Vorsteuer auf Material und Dienstleistungen. Der Satz gilt am Buchungsdatum; ein inaktiver Code («(inaktiv)») bleibt nur bestehenden Buchungen.</p>
</section>

<section data-help-field="tax_amount">
<h3>Steuerbetrag</h3>
<p><strong>Steuerbetrag leer</strong> = aus dem Betrag berechnet, mit dem Satz am Buchungsdatum.</p>
<p><strong>Steuerbetrag laut Beleg</strong>: darf um <?= (int) OneLineEntryForm::TAX_CORRECTION_PERCENT ?> % der berechneten Steuer daneben liegen — mindestens <?= e(OneLineEntryForm::TAX_CORRECTION_MIN) ?>, höchstens <?= e(OneLineEntryForm::TAX_CORRECTION_MAX) ?> (Rundung laut Beleg). Mehr weist die Prüfung zurück.</p>
<?php if ($form->vatHint() !== ''): ?>
<p><strong>Diese Buchung</strong>: <?= e($form->vatHint()) ?></p>
<?php endif; ?>
</section>

<?php if ($entry !== null): ?>
<h3>Bearbeiten</h3>
<p>Die Nummer <?= e(($year?->getCode() ?? '') . '/' . $entry->getNumber()) ?> bleibt. Jede Änderung wird protokolliert. Für ein anderes Geschäftsjahr: löschen und dort neu erfassen.</p>
<p>Passt die Buchung nicht in eine Zeile (mehrere Konten), «Als Sammelbuchung bearbeiten».</p>
<?php endif; ?>
