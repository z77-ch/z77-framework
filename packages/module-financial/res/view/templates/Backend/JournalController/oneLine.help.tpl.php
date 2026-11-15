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
 * The prose carries no PHP condition (ADR-055): the KI-Assistent renders helps with an empty
 * context, and this one then falls back to its static text — capture and edit wording stand
 * side by side, live values (the number, the tolerance) only in parentheses or lists.
 *
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = capture
 */
use Z77\Module\Financial\Ui\OneLineEntryForm;
?>
<h3>Einzelbuchung</h3>
<p>Eine <strong>Einzelbuchung</strong> von Hand erfassen oder korrigieren: eine Zeile <strong>Soll</strong> an <strong>Haben</strong> mit einem Betrag, z.B. Büromaterial bar bezahlt, Miete überwiesen, Bargeld abgehoben; mit MWST rechnet das System die Steuer heraus.</p>
<p>Konten werden mit ihrer Nummer erfasst — die Vorschlagsliste zeigt die bebuchbaren Konten.</p>
<p>«Buchen» (beim Bearbeiten «Speichern») prüft die ganze Zeile. Ist alles gültig, steht die Buchung sofort im Journal — eine neue erhält die nächste Nummer des Geschäftsjahres (lückenlos); sonst bleibt das Formular mit den markierten Feldern stehen und nichts wird gebucht.</p>
<p>Tipp: F1 in einem Feld öffnet die Hilfe zu genau diesem Feld.</p>

<section data-help-field="debit">
<h3>Soll</h3>
<p>Das Konto, das <strong>zunimmt</strong> (Aufwand, Aktiven): die Kontonummer, z.B. «6500 Büromaterial» beim Einkauf oder «1020 Bankguthaben» bei einem Zahlungseingang.</p>
<p>Nur bebuchbare Konten des Kontenplans — die Vorschlagsliste zeigt Nummer und Name.</p>
</section>

<section data-help-field="date">
<h3>Datum</h3>
<p>Das Datum des Belegs. Es muss in einem erfassten Geschäftsjahr liegen — das Geschäftsjahr folgt dem Datum; beim Bearbeiten bleibt es im selben Geschäftsjahr wie bisher.</p>
<p>Ein abgeschlossenes Geschäftsjahr nimmt keine Buchung an.</p>
</section>

<section data-help-field="text">
<h3>Text</h3>
<p>Was gebucht wird, kurz und wiederfindbar — z.B. «Büromaterial Papeterie Muster» oder «Miete Oktober». Die Journalsuche findet die Buchung über diesen Text.</p>
</section>

<section data-help-field="credit">
<h3>Haben</h3>
<p>Das Konto, das <strong>abnimmt</strong> (Bezahlung, Ertrag): z.B. «1020 Bankguthaben» beim Bezahlen per Bank oder «3200 Handelserlöse» bei einem Verkauf.</p>
<p>Soll und Haben müssen verschiedene Konten sein. Gegenrichtung (z.B. Rückerstattung): Soll und Haben tauschen, nicht den Betrag negativ machen.</p>
</section>

<section data-help-field="amount">
<h3>Betrag</h3>
<p>Der Betrag in der Basiswährung, grösser als 0 — z.B. «450.00», «1250.5» oder «1'250,50» (Punkt oder Komma, Apostroph erlaubt). Mit MWST ist es der Betrag <strong>laut Beleg</strong> (inkl. MWST); die Steuer rechnet das System heraus.</p>
</section>

<section data-help-field="vat">
<h3>MwSt</h3>
<p>Der Schalter «MwSt» öffnet MWST-Code und Steuerbetrag. Der Code steht auf der Nettoseite; die Steuerzeile (Vorsteuer bzw. geschuldete MWST) bucht das System beim Buchen bzw. Speichern selbst.</p>
<p>Ohne MWST: Schalter aus lassen. Sind Soll und Haben beides Aufwand- oder Ertragskonten, ist die Nettoseite nicht eindeutig — dann als Sammelbuchung erfassen.</p>
</section>

<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Der Steuercode des Belegs, z.B. «VM 8.1 %» für Vorsteuer auf Material und Dienstleistungen. Der Satz gilt am Buchungsdatum; ein inaktiver Code («(inaktiv)») bleibt nur bestehenden Buchungen.</p>
</section>

<section data-help-field="tax_amount">
<h3>Steuerbetrag</h3>
<p><strong>Steuerbetrag leer</strong> = aus dem Betrag berechnet, mit dem Satz am Buchungsdatum.</p>
<p><strong>Steuerbetrag laut Beleg</strong>: darf nur wenig von der berechneten Steuer abweichen, für die Rundung laut Beleg<?= e(' (um ' . (int) OneLineEntryForm::TAX_CORRECTION_PERCENT . ' % der berechneten Steuer, mindestens ' . OneLineEntryForm::TAX_CORRECTION_MIN . ', höchstens ' . OneLineEntryForm::TAX_CORRECTION_MAX . ')') ?>. Mehr weist die Prüfung zurück.</p>
<?php if ($form->vatHint() !== ''): ?>
<p><strong>Diese Buchung</strong>: <?= e($form->vatHint()) ?></p>
<?php endif; ?>
</section>

<?php if ($entry === null): ?>
<h3>Das Journal unter dem Formular</h3>
<p>Unter dem Formular stehen die Buchungen des Geschäftsjahres, das oben im Menü gewählt ist, die neuste zuerst; «Alle Jahre» (letzter Eintrag der Auswahl) zeigt alle. Ein Klick auf einen Spaltentitel sortiert, die Lupe daneben sucht in der Spalte. «Einzel | Sammel» oben wechselt zwischen Einzelbuchung und Sammelbuchung.</p>
<p>Das Zeichen am Zeilenanfang öffnet die Buchung: <strong>Stift</strong> = von Hand erfasst: öffnet sie zum Bearbeiten («Abbrechen» zeigt sie an, dort steht «Löschen …»); <strong>Blitz</strong> = automatisch gebucht von einem Modul (Rechnung, Zahlung, Beleg, Eröffnung) — nur das Modul ändert oder storniert sie; <strong>Schloss</strong> = Geschäftsjahr abgeschlossen. Jede Änderung und jede gelöschte Buchung steht unter Finanzen › Änderungsprotokoll.</p>
<?php endif; ?>

<?php if ($entry !== null): ?>
<h3>Bearbeiten</h3>
<p>Die Nummer <?= e(($year?->getCode() ?? '') . '/' . $entry->getNumber()) ?> bleibt. Jede Änderung wird protokolliert. Für ein anderes Geschäftsjahr: löschen und dort neu erfassen.</p>
<p>Passt die Buchung nicht in eine Zeile (mehrere Konten), «Als Sammelbuchung bearbeiten».</p>
<?php endif; ?>
