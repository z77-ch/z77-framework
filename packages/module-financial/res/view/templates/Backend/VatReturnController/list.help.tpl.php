<?php
/**
 * Help for «Finanzen › MWST-Abrechnungen» (ADR-048, HELP-003 — its trigger at the right end of the
 * toolbar): what the list is for, the action cell, the tool «Jahresübersicht», the columns, posting and the lock, lifting as
 * the emergency brake, the voucher, the protocol, the old balances of the VAT accounts before the
 * first return, a refused period, what is not available yet. Everything the screen does not say
 * stands here (backend-screen.md §5); also what the KI-Assistent knows (ADR-055). Stage S2: the
 * owner decisions of 2026-11-25.
 */
?>
<h3>MWST-Abrechnungen</h3>
<p>Pro Abrechnungsperiode die MWST-Abrechnung, wie z77 sie aus den Buchungen mit MWST-Code rechnet. <strong>Eingereicht wird im ePortal der ESTV</strong> — z77 zeigt die Zahlen, die du dort eintippst, und erklärt, woher sie kommen. Danach buchst du die Abrechnung hier mit «MWST buchen …»: <strong>gebucht heisst eingereicht</strong>. Die Abrechnung ist eine Hilfe zur Selbstveranlagung: verantwortlich für die eingereichte Abrechnung bleibt die steuerpflichtige Person. z77 gibt keine Steuerberatung.</p>
<p>Die Liste zeigt die Perioden, die im <strong>Geschäftsjahr</strong> oben in der Schiene <strong>enden</strong> — dieselbe Regel wie beim Jahresabschluss. Eine Periode über den Jahreswechsel steht im Jahr, in dem sie endet.</p>
<section data-help-field="next">
<h3>Neue Abrechnung</h3>
<p>Öffnet die erste abgelaufene Periode, die noch nicht gebucht ist — als Seite der Abrechnung; dort steht «MWST buchen …». Sie wird nicht neu angelegt — z77 rechnet jede offene Periode jederzeit aus den Buchungen. Gibt es keine abgelaufene offene Periode, ist die Aktionszelle leer.</p>
</section>
<section data-help-field="year_overview">
<h3>Jahresübersicht</h3>
<p>Das Werkzeug «Jahresübersicht» öffnet die MWST-Jahresübersicht des Steuerjahres, dessen 31.12. im gewählten Geschäftsjahr liegt: Korrekturen des Jahres, Umsatz- und Vorsteuerabstimmung, PDF und Ablage. Sie bucht nichts. Das Werkzeug fehlt, solange das Steuerjahr keine MWST-Periode hat.</p>
</section>
<section data-help-field="period">
<h3>Periode, Zeitraum und Methode</h3>
<p>Rhythmus (Quartal, Monat oder Jahr), «Abrechnung ab» und «Abrechnung bis» und die Methode stehen im Mandanten (Stammdaten › Firma › Mandant, Reiter «UID und MWST»). Unterstützt ist die <strong>effektive Methode mit vereinbartem Entgelt</strong>. Beginnt oder endet die Steuerpflicht mitten in einer Periode, ist diese verkürzt. «läuft» heisst: die Periode ist noch nicht zu Ende.</p>
</section>
<section data-help-field="state">
<h3>Zustand</h3>
<p><strong>offen</strong> — gerechnet, nicht gebucht. Die Zahlen ändern sich, solange in der Periode noch gebucht wird. <strong>gebucht</strong> — mit «MWST buchen …» gebucht: die Zahlen sind festgehalten, wie sie gebucht wurden, und Buchungen mit MWST-Code sind in der Periode gesperrt. <strong>aufgehoben</strong> — eine gebuchte Fassung, die ein Admin aufgehoben hat; sie steht als kleiner Hinweis «Fassung 1 aufgehoben» in der Zeile ihrer Periode, die wieder offen ist. Der Hinweis öffnet die aufgehobene Fassung zum Lesen.</p>
<p>Das Symbol vorn öffnet die Abrechnung: das Auge eine offene, das Schloss eine gebuchte.</p>
</section>
<section data-help-field="posted-by">
<h3>Gebucht von / am</h3>
<p>Wer die Abrechnung gebucht hat und wann — für z77 ist das zugleich der Tag der Einreichung. Ein eigenes Feld «Eingereicht am» gibt es nicht.</p>
</section>
<section data-help-field="payable">
<h3>Zahllast</h3>
<p>Der Betrag der Ziffer 500 «Zu bezahlender Betrag». Ein Guthaben (Ziffer 510) steht mit Minus.</p>
</section>
<section data-help-field="pdf">
<h3>PDF und Beleg</h3>
<p>Das Symbol vor der Periode öffnet bei einer <strong>offenen</strong> Periode die Abrechnung als PDF — bei jedem Öffnen neu gerechnet, nicht abgelegt. Bei einer <strong>gebuchten</strong> Abrechnung öffnet es den <strong>Beleg</strong>: das PDF, das beim Buchen abgelegt wurde (Formular, Herkunft und Abstimmung der MWST-Konten, die Abrechnungsbuchung, die bestätigten Warnungen, wer und wann). Der Beleg wird nie verändert, auch nicht beim Aufheben. Weder PDF noch Beleg sind eine Einreichung.</p>
</section>
<section data-help-field="menu">
<h3>Aufheben … — die Notbremse</h3>
<p>Das ⋮ vor der neuesten gebuchten Abrechnung öffnet «Aufheben …» — nur für Admins und nur für die <strong>neueste</strong> gebuchte Abrechnung, immer mit einem Grund. Gedacht für eine Abrechnung, die irrtümlich gebucht wurde. Ein Fehler in einer eingereichten Abrechnung wird der ESTV gemeldet; was später dazukommt, gehört in die laufende Periode. Mehr in der Hilfe der Abrechnung.</p>
</section>
<section data-help-field="log">
<h3>Protokoll</h3>
<p>Unter der Liste die letzten drei Einträge: wer welche Abrechnung wann gebucht oder aufgehoben hat, beim Aufheben mit dem Grund. «Ganzes Protokoll» öffnet Finanzen › Änderungsprotokoll, Reiter «Abschlüsse und MWST».</p>
</section>
<section data-help-field="refused">
<h3>Eine Periode ohne Zahllast</h3>
<p>Kann z77 eine Periode nicht rechnen, steht der Grund unter ihrer Zeile, mit dem Link dorthin, wo du es behebst: ein MWST-Code mit einem Satz, für den das Formular keine Ziffer hat, oder eine Periode vor 2024 (ältere Formulare kennt z77 nicht).</p>
</section>
<h3>Keine Liste, sondern ein Hinweis</h3>
<p>Statt der Liste steht ein Hinweis, wenn der Mandant <strong>nicht mehrwertsteuerpflichtig</strong> ist oder in den MWST-Einstellungen etwas fehlt (Methode, Abrechnungsart, Rhythmus, «Abrechnung ab») oder etwas eingestellt ist, das z77 nicht abrechnet (Saldosteuersatz, Pauschalsteuersatz, vereinnahmtes Entgelt). Der Link führt zum Mandanten. Wer die Steuerpflicht beendet, setzt «Abrechnung bis» — dann bleiben die letzten Perioden hier.</p>
<p>Der Menüpunkt «MWST-Abrechnungen» fehlt im Menü, solange der Mandant nicht mehrwertsteuerpflichtig ist und kein «Abrechnung bis» hat.</p>
<h3>Vor der ersten Abrechnung: alte Salden der MWST-Konten</h3>
<p>Stehen auf den MWST-Konten (z.B. 2200 Geschuldete MWST, 1170 / 1171 Vorsteuer) noch Salden aus der Zeit <strong>vor «Abrechnung ab»</strong>, stören sie die erste Abrechnung nicht: die Abstimmung vergleicht nur die Bewegungen der Periode, und die Abrechnungsbuchung gleicht nur die Steuer der Buchungen mit MWST-Code aus — der alte Saldo bleibt einfach stehen. Wer ihn auf das MWST-Abrechnungskonto (z.B. 2201) übertragen will, macht das mit einer <strong>manuellen Buchung ohne MWST-Code mit einem Datum vor «Abrechnung ab»</strong>. Mit einem Datum <strong>ab</strong> «Abrechnung ab» wäre sie eine Bewegung auf dem MWST-Konto ohne MWST-Code in einer Periode und sperrt das Buchen von deren Abrechnung (siehe «Offene Punkte» der Abrechnung). Gibt es vor «Abrechnung ab» kein Geschäftsjahr in z77, kommt der alte Saldo mit der Eröffnung des ersten Jahres — dann den Betrag gleich dort auf das Abrechnungskonto setzen.</p>
<h3>Noch nicht verfügbar</h3>
<ul>
    <li>Die Spalte <strong>«Ins ePortal»</strong> — welche Zahl du in welche Ziffer tippst und welche das ePortal selbst rechnet.</li>
</ul>
