<?php
/**
 * Help for the compound entry (Sammelbuchung, ADR-048) — capture and edit. Attached by the
 * journal controller, opened with «? Hilfe» in the top bar, the «i» of a window, or F1 in a
 * field; the form itself carries no text (owner 2026-09-29).
 *
 * Structure (owner 2026-10-08): a general part first, then one section per field, marked
 * `data-help-field="<field name>"` (the `name` without `[]`): date, text, account, line_text,
 * debit, credit, tax_code, tax_amount — plus the topic sections of the VAT lock (board S4-1,
 * part 6): tax_only, corrections, before_start, old_entry — and of the correction entry (board S3-1,
 * part 5; stage S3): vat_period, two_ways, earlier_year, vat_rate, year_end (the year end through
 * 2209 «MWST-Korrektur, noch nicht abgerechnet», owner B-1 / C-E, with its input-tax mirror and the
 * reverse-charge pattern), old_year, rappen. Here Soll / Haben are the row's AMOUNTS — the account
 * is its own column — unlike the one-line form, where they are accounts.
 *
 * The prose carries no PHP condition (ADR-055): the KI-Assistent renders helps with an empty
 * context, and this one then falls back to its static text — capture and edit wording stand
 * side by side, live values only in lists.
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
<p>Eine <strong>Sammelbuchung</strong> mit mehr als zwei Konten erfassen oder korrigieren, z.B. eine Lohnzahlung oder eine auf mehrere Konten verteilte Rechnung: mehrere Zeilen, je ein Konto mit einem Betrag im Soll oder im Haben.</p>
<p>Gebucht wird erst, wenn Soll und Haben gleich sind («ausgeglichen») — die Total-Zeile zeigt die Differenz.</p>
<p>«Weitere Zeilen» fügt leere Zeilen an; leere Zeilen werden beim Buchen ignoriert. Mindestens zwei Zeilen.</p>
<p>«Buchen» (beim Bearbeiten «Speichern») prüft alles auf einmal: gültig → die Buchung steht im Journal, eine neue mit der nächsten Nummer des Geschäftsjahres; sonst bleiben die markierten Felder stehen und nichts wird gebucht.</p>
<p>Tipp: F1 in einem Feld öffnet die Hilfe zu genau diesem Feld.</p>

<section data-help-field="date">
<h3>Datum</h3>
<p>Das Datum des Belegs. Es muss in einem erfassten Geschäftsjahr liegen — das Geschäftsjahr folgt dem Datum; beim Bearbeiten bleibt es im selben Geschäftsjahr wie bisher.</p>
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
<h3>MWST — der Code auf der Nettozeile</h3>
<p>Den Code auf die <strong>Nettozeile</strong> setzen (Aufwand oder Ertrag), z.B. «VM» auf der Aufwandzeile 6500. Die Liste ist gruppiert: Umsatz, Vorsteuer, Bezugsteuer und die Korrekturen VE / VK / VN, die es nur in der Sammelbuchung gibt.</p>
<p>Ab «Abrechnung ab» schreibt z77 die Steuerzeile auf 1170 / 1171 / 2200 selber: sie steht unter «MWST-Zeilen (berechnet)» vor dem Total — je MWST-Konto und Seite eine Zeile, mehrere Nettozeilen mit demselben Konto ergeben eine Zeile. «Weitere Zeilen» und «Buchen» rechnen sie neu. Eine Zeile auf einem MWST-Konto ohne Code wird abgelehnt; ein Umsatz-Code gehört auf die Ertragszeile, nie auf 2200.</p>
<p>Bezugsteuer (BM, BI, BM2, BI2) auf die Aufwandzeile setzen: z77 schreibt beide Zeilen — die geschuldete MWST auf 2200 und die Vorsteuer auf dem Konto, das der Code nennt.</p>
<p>Vor «Abrechnung ab» (und ohne MWST-Abrechnung in z77) wie bisher: die Steuerzeile wird als eigene Zeile erfasst, mit dem Betrag, den z77 für den Code berechnet.</p>
<?php if ($taxHints !== []): ?>
<ul>
    <?php foreach ($taxHints as $hint): ?>
    <li><?= e($hint) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section data-help-field="tax_amount">
<h3>Steuer</h3>
<p>Leer: z77 rechnet Basis × Satz. Steht auf dem Beleg ein anderer Steuerbetrag, ihn hier eintragen — erlaubt bis 1 % der Steuer, mindestens 0.05, höchstens 1.00, wie bei der Einzelbuchung. Eine andere Steuer ist kein Rundungsfall: dann den Beleg prüfen.</p>
<p>Bei einer «nur Steuer»-Zeile bleibt «Steuer» leer — dort ist der Betrag selbst die Steuer.</p>
</section>

<section data-help-field="tax_only">
<h3>Nur Steuer</h3>
<p>Einfuhrsteuer, nachgeholte Vorsteuer, Vorsteuer auf Gründungskosten: die Zeile direkt auf 1170 / 1171 mit dem Vorsteuer-Code (VM / VI), der Betrag ist die Steuer. Bei VM / VI muss das MWST-Konto getippt sein. z77 schreibt dazu keine weitere Zeile; gebucht wird sie mit Basis 0.00 und dem Satz des Codes.</p>
<p>Gründungskosten: die Vorsteuer als solche Zeile im ersten Abrechnungszeitraum buchen — ob sie abziehbar ist, beurteilst du selbst.</p>
</section>

<section data-help-field="corrections">
<h3>VE / VK / VN</h3>
<p>Einlageentsteuerung (VE, Ziffer 410, erhöht die Vorsteuer), Vorsteuerkorrektur bei gemischter Verwendung oder Eigenverbrauch (VK, Ziffer 415) und Vorsteuerkürzung bei Subventionen und öffentlichen Beiträgen (VN, Ziffer 420) — beide verringern die Vorsteuer. z77 rechnet hier nichts: den Steuerbetrag als Zeile mit dem Code buchen, das Konto leer lassen (z77 setzt 1171) oder 1171 tippen. VE im Soll, VK und VN im Haben.</p>
<p>Privatanteil einer Einzelfirma: als Vorsteuerkorrektur VK (Ziffer 415) buchen.</p>
</section>

<section data-help-field="before_start">
<h3>Vor «Abrechnung ab»</h3>
<p>Wie bisher, ohne Code; die MWST-Konten sind frei. Hier gehört der einmalige Übertrag der alten Salden der MWST-Konten (2200, 1170, 1171) auf das Abrechnungskonto 2201 hin — datiert vor «Abrechnung ab» und gebucht, bevor das Jahr abgeschlossen ist. Ist es schon abgeschlossen: ein Admin öffnet das Jahr wieder, bucht den Übertrag und schliesst es wieder.</p>
</section>

<section data-help-field="old_entry">
<h3>Alte Buchung mit getippter MWST-Zeile</h3>
<p>Eine Buchung, die vor der MWST-Abrechnung in z77 auf die alte Art gebucht wurde, hat ihre MWST-Zeile getippt. Beim Öffnen vergleicht z77 sie mit dem, was es rechnet: stimmt sie (oder liegt sie in der Belegsteuer-Grenze), steht sie unter «MWST-Zeilen (berechnet)» und behält ihren Platz. Stimmt sie nicht, steht der Fehler an der Zeile: die Zeile entfernen und die Gegenzeile prüfen.</p>
<p>Text oder Datum lassen sich immer ändern, solange die Zeilen bleiben, wie sie sind — dann prüft z77 die MWST nicht. Wer Zeilen ändert, macht die ganze Buchung stimmig.</p>
</section>

<h3>Korrekturbuchung</h3>
<section data-help-field="vat_period">
<h3>Korrigiert Periode (Admin)</h3>
<p>Für einen Fehler in einer schon gebuchten MWST-Abrechnung: die Periode wählen, die korrigiert wird. Die Auswahl zeigt die gebuchten Perioden vor dem Buchungsdatum, die neueste zuerst; «— keine Korrektur —» ist eine normale Buchung. Das Feld gibt es nur für Admins und nur in der Sammelbuchung.</p>
<p>Die Korrekturbuchung wird in der <strong>laufenden Periode</strong> datiert — nie in die korrigierte — und hat mindestens eine Zeile mit MWST-Code. Sie zählt nicht ins Formular der laufenden Periode, sondern in deren Block «Korrekturen früherer Perioden — separat im ePortal melden». Diesen Block meldest du im ePortal; die nächste Abrechnung bucht ihn mit. Ändern, löschen und stornieren wie jede Buchung, solange ihre Periode offen ist. Im Journal trägt sie die Marke «Korrektur zu …».</p>
<p>Abgelehnt wird sie, wenn das Datum in einer abgerechneten Periode oder nach «Abrechnung bis» liegt, die gewählte Periode nicht (mehr) abgerechnet ist, sie nicht vor dem Buchungsdatum liegt oder keine Zeile einen MWST-Code hat — der Satz steht am Feld.</p>
</section>
<section data-help-field="two_ways">
<h3>Fehler im gleichen Kalenderjahr — zwei Wege</h3>
<p>Eine normale Buchung in der laufenden Periode steht im Formular dieser Periode; das Steuerjahr stimmt am Ende. Eine Korrekturbuchung wird separat gemeldet (Korrekturabrechnung der Periode im ePortal) — der Weg, den Art. 129 MWSTV verlangt. Du wählst pro Fall. «Gleich» heisst: das Kalenderjahr der laufenden Periode (der ersten, die noch nicht gebucht ist), nicht das heutige Datum.</p>
</section>
<section data-help-field="earlier_year">
<h3>Fehler aus einem früheren Kalenderjahr</h3>
<p>Immer als Korrekturbuchung zu der Periode erfassen und im ePortal melden: bis zum 240. Tag nach Ende des Geschäftsjahres in der Jahresabstimmung, später mit einer Korrekturabrechnung der Periode. Eine normale Buchung im neuen Jahr deklariert die Steuer im falschen Steuerjahr; z77 kann das nicht erkennen.</p>
<p>Bezugsteuer einer schon abgerechneten Periode: die Rechnung «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zur Periode des Rechnungsdatums erfassen — die Aufwandzeile mit BM / BI (BM2 / BI2) und dem Rechnungsbetrag (z77 rechnet die Vorsteuer auf 1170 / 1171 und die geschuldete MWST auf 2200), dagegen derselbe Aufwand im Haben ohne Code. Das Konto 2209 braucht es dafür nicht.</p>
</section>
<section data-help-field="vat_rate">
<h3>Steuersatz</h3>
<p>Es gilt der Satz am letzten Tag der korrigierten Periode, nicht der Satz am Buchungsdatum. Die berechnete MWST-Zeile nennt ihn.</p>
</section>
<section data-help-field="year_end">
<h3>Jahresende</h3>
<p>Eine Korrekturbuchung wird nie in eine abgerechnete Periode datiert. Soll ein Umsatz noch ins alte Geschäftsjahr, dessen letzte MWST-Periode schon gebucht ist, geht es in zwei Schritten über das Konto <strong>2209 «MWST-Korrektur, noch nicht abgerechnet»</strong> (im Mandanten, Reiter «Konten»):</p>
<p><strong>Schritt 1</strong> — am letzten Tag des Geschäftsjahres (meist 31.12.), ohne MWST-Code: Kasse an Ertrag netto und an 2209 die Steuer, z.B. 1000 Kasse Soll 108.10 · 3200 Ertrag Haben 100.00 · 2209 Haben 8.10.</p>
<p><strong>Schritt 2</strong> — in der laufenden Periode als Korrekturbuchung, «Korrigiert Periode» = die Periode des Umsatzes: 3200 Haben 100.00 mit dem MWST-Code (z77 rechnet 2200 Haben 8.10) · 3200 Soll 100.00 ohne Code · 2209 Soll 8.10. Der Ertrag bleibt im alten Jahr, die Steuer kommt in den Block «Korrekturen früherer Perioden», 2209 steht danach auf 0.00.</p>
<p>Die gebuchte Abrechnung der alten Periode zeigt danach in der Abstimmung «Seit dem Buchen geändert» — das stimmt: die Buchhaltung hat sich nach dem Einreichen geändert. Steht auf 2209 beim Jahresabschluss noch ein offener Betrag, warnt z77.</p>
<p>Vorsteuer gleich, gespiegelt: Schritt 1 Aufwand netto im Soll und 2209 im Soll an Kasse; Schritt 2 als Korrekturbuchung Aufwand netto im Soll mit VM / VI (z77 rechnet 1170 / 1171), derselbe Aufwand im Haben ohne Code und 2209 im Haben — oder kürzer eine «nur Steuer»-Zeile VM / VI auf 1170 / 1171 gegen 2209.</p>
</section>
<section data-help-field="old_year">
<h3>Älter als fünf Jahre</h3>
<p>Liegt das Steuerjahr der korrigierten Periode mehr als fünf Jahre zurück, steht unter dem Feld ein Hinweis; gebucht wird trotzdem. Ob die Steuer noch geschuldet ist, entscheidet nicht die Buchhaltung.</p>
</section>
<section data-help-field="rappen">
<h3>Rappen</h3>
<p>Rechnet das ePortal die Steuer der Korrektur einen Rappen anders als z77, die Differenz bei der Zahlung von Hand zwischen 2201 und 6945 buchen.</p>
</section>

<?php if ($entry === null): ?>
<h3>Das Journal unter dem Formular</h3>
<p>Unter dem Formular stehen die Buchungen des Geschäftsjahres, das oben im Menü gewählt ist, die neuste zuerst; «Alle Jahre» (letzter Eintrag der Auswahl) zeigt alle. Ein Klick auf einen Spaltentitel sortiert, die Lupe daneben sucht in der Spalte. «Einzel | Sammel» oben wechselt zwischen Einzelbuchung und Sammelbuchung.</p>
<p>Das Zeichen am Zeilenanfang öffnet die Buchung: <strong>Stift</strong> = von Hand erfasst: öffnet sie zum Bearbeiten («Abbrechen» zeigt sie an, dort steht «Löschen …»); <strong>Blitz</strong> = automatisch gebucht von einem Modul (Rechnung, Zahlung, Beleg, Eröffnung) — nur das Modul ändert oder storniert sie; <strong>Schloss</strong> = Geschäftsjahr abgeschlossen. Jede Änderung und jede gelöschte Buchung steht unter Finanzen › Änderungsprotokoll.</p>
<p>Das <strong>Blatt</strong> am Zeilenende öffnet den Beleg zur Buchung in einem neuen Tab: das Foto aus dem Belegjournal, das PDF aus dem Kreditorenjournal oder die Rechnung aus der Faktura. Es steht nur, wenn es einen Beleg gibt und du ihn sehen darfst. In der geöffneten Buchung steht er unter «Beleg».</p>
<?php endif; ?>

<?php if ($entry !== null): ?>
<h3>Bearbeiten</h3>
<p>Die Nummer <?= e(($year?->getCode() ?? '') . '/' . $entry->getNumber()) ?> bleibt. Jede Änderung wird protokolliert. Für ein anderes Geschäftsjahr: löschen und dort neu erfassen.</p>
<?php endif; ?>
