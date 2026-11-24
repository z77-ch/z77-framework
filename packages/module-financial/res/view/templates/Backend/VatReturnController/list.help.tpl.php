<?php
/**
 * Help for «Finanzen › MWST-Abrechnungen» (ADR-048, HELP-003 — its trigger at the right end of the
 * toolbar): what the list is for, the action cell, the columns, a refused period, what is not
 * available yet. Everything the screen does not say stands here (backend-screen.md §5); also what
 * the KI-Assistent knows (ADR-055). Stage S1b: the return can be READ and printed, posting follows.
 */
?>
<h3>MWST-Abrechnungen</h3>
<p>Pro Abrechnungsperiode die MWST-Abrechnung, wie z77 sie aus den Buchungen mit MWST-Code rechnet. <strong>Eingereicht wird im ePortal der ESTV</strong> — z77 zeigt die Zahlen, die du dort eintippst, und erklärt, woher sie kommen. Die Abrechnung ist eine Hilfe zur Selbstveranlagung: verantwortlich für die eingereichte Abrechnung bleibt die steuerpflichtige Person. z77 gibt keine Steuerberatung.</p>
<p>Die Liste zeigt die Perioden, die im <strong>Geschäftsjahr</strong> oben in der Schiene <strong>enden</strong> — dieselbe Regel wie beim Jahresabschluss. Eine Periode über den Jahreswechsel steht im Jahr, in dem sie endet.</p>
<section data-help-field="next">
<h3>Nächste Abrechnung</h3>
<p>Öffnet die erste abgelaufene Periode, die noch nicht abgerechnet ist. Sie wird nicht neu angelegt — z77 rechnet jede Periode jederzeit aus den Buchungen. Gibt es keine abgelaufene offene Periode, ist die Aktionszelle leer.</p>
</section>
<section data-help-field="period">
<h3>Periode, Zeitraum und Methode</h3>
<p>Rhythmus (Quartal, Monat oder Jahr), «Abrechnung ab» und «Abrechnung bis» und die Methode stehen im Mandanten (Stammdaten › Firma › Mandant, Reiter «UID und MWST»). Unterstützt ist die <strong>effektive Methode mit vereinbartem Entgelt</strong>. Beginnt oder endet die Steuerpflicht mitten in einer Periode, ist diese verkürzt. «läuft» heisst: die Periode ist noch nicht zu Ende.</p>
</section>
<section data-help-field="state">
<h3>Zustand</h3>
<p><strong>offen</strong> — gerechnet, nicht gebucht. Die Zahlen ändern sich, solange in der Periode noch gebucht wird. Im Moment ist jede Periode «offen»: das Buchen der Abrechnung folgt (siehe unten).</p>
</section>
<section data-help-field="payable">
<h3>Zahllast</h3>
<p>Der Betrag der Ziffer 500 «Zu bezahlender Betrag». Ein Guthaben (Ziffer 510) steht mit Minus.</p>
</section>
<section data-help-field="pdf">
<h3>PDF</h3>
<p>Das Symbol neben dem Zustand öffnet die Abrechnung als PDF zum Ablegen. Das PDF ist <strong>keine Einreichung</strong>.</p>
</section>
<section data-help-field="refused">
<h3>Eine Periode ohne Zahllast</h3>
<p>Kann z77 eine Periode nicht rechnen, steht der Grund unter ihrer Zeile, mit dem Link dorthin, wo du es behebst: Buchungen mit Bezugsteuer (die Abrechnung mit Bezugsteuer folgt), ein MWST-Code mit einem Satz, für den das Formular keine Ziffer hat, oder eine Periode vor 2024 (ältere Formulare kennt z77 nicht).</p>
</section>
<h3>Keine Liste, sondern ein Hinweis</h3>
<p>Statt der Liste steht ein Hinweis, wenn der Mandant <strong>nicht mehrwertsteuerpflichtig</strong> ist oder in den MWST-Einstellungen etwas fehlt (Methode, Abrechnungsart, Rhythmus, «Abrechnung ab») oder etwas eingestellt ist, das z77 nicht abrechnet (Saldosteuersatz, Pauschalsteuersatz, vereinnahmtes Entgelt). Der Link führt zum Mandanten. Wer die Steuerpflicht beendet, setzt «Abrechnung bis» — dann bleiben die letzten Perioden hier.</p>
<p>Der Menüpunkt «MWST-Abrechnungen» fehlt im Menü, solange der Mandant nicht mehrwertsteuerpflichtig ist und kein «Abrechnung bis» hat.</p>
<h3>Noch nicht verfügbar</h3>
<ul>
    <li><strong>MWST buchen</strong> — die Abrechnung nach dem Einreichen im ePortal buchen und die Periode sperren; damit die Zustände «gebucht» und «aufgehoben», die Spalten «Eingereicht» und «Gebucht von / am» und das Protokoll unter der Liste.</li>
    <li><strong>Aufheben</strong> einer irrtümlich gebuchten Abrechnung.</li>
    <li><strong>Korrekturen</strong> einer schon eingereichten Periode (Korrekturabrechnung, Jahresabstimmung).</li>
    <li><strong>Bezugsteuer</strong> (Dienstleistungen aus dem Ausland, Ziffern 382 / 383).</li>
    <li>Die Spalte <strong>«Ins ePortal»</strong> — welche Zahl du in welche Ziffer tippst und welche das ePortal selbst rechnet.</li>
</ul>
<p>Bis dahin: die Abrechnung hier lesen und drucken und im ePortal einreichen.</p>
