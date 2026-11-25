<?php
/**
 * Help for the yearly overview of the VAT return (ADR-048 — a page, its trigger in the top bar;
 * board S7-2 part 8, owner S7-4): the general part names the screen, then one section per part —
 * what z77 is («keine Steuerberatung», S7-4: here, not on the PDF), the corrections and the sum per
 * field, «Erfasst, noch nicht abgerechnet», the deadlines, interest, an error found later, the two
 * reconciliations, 2209, «vorläufig», «Ablegen …» and «Seit der Ablage geändert», the fiscal year
 * that is not the calendar year. Also what the KI-Assistent knows (ADR-055).
 */
?>
<h3>MWST-Jahresübersicht</h3>
<p>Die Übersicht stellt ein Steuerjahr zusammen — das Kalenderjahr: die Abrechnungen des Jahres, die Korrekturen, die für das Jahr gemeldet wurden, die Umsatzabstimmung und die Abstimmung der MWST-Konten (Art. 128 MWSTV). Sie bucht nichts. Mit <strong>PDF</strong> druckst du sie, mit <strong>Ablegen …</strong> legst du sie unveränderbar ins Archiv. Geöffnet wird das Steuerjahr, dessen 31.12. im gewählten Geschäftsjahr liegt; das andere Jahr wählst du oben in der Schiene über das Geschäftsjahr.</p>

<section data-help-field="scope">
<h3>Was z77 ist</h3>
<p>z77 ist ein Hilfsmittel zur Selbstveranlagung. Es gibt keine Steuerberatung und ist von keiner Behörde geprüft oder zugelassen. Die steuerpflichtige Person reicht selbst im ePortal ein und bleibt verantwortlich. Unterstützt ist nur der beschriebene Umfang.</p>
</section>

<section data-help-field="findings">
<h3>Offene Punkte</h3>
<p>Nur sichtbar, wenn es etwas gibt — nichts davon sperrt, auch nicht den Jahresabschluss oder «Ablegen …». <strong>Rest</strong> (rot): ein MWST-Konto, die Umsatzabstimmung oder das Konto «Vorsteuer, noch nicht abgerechnet» geht nicht auf — der Link führt hin. <strong>Warnung</strong>: prüfen, zum Beispiel ein Ertrag ohne MWST-Code.</p>
</section>

<section data-help-field="returns">
<h3>Abrechnungen</h3>
<p>Jede MWST-Periode des Jahres mit ihrer gebuchten Abrechnung: wann gebucht, die Abrechnungsbuchung, die Zahllast, wie sie gebucht wurde, und die Fälligkeit (60 Tage nach Periodenende). Das Total ist die Summe der gebuchten Zahllasten — nicht neu gerundet.</p>
</section>

<section data-help-field="corrections">
<h3>Korrekturen für das Jahr</h3>
<p>Jeder Block «Korrekturen früherer Perioden», der eine Periode dieses Jahres korrigiert — mit der Korrekturbuchung, der Abrechnung, die ihn gemeldet hat, und deren Buchungstag (gebucht heisst gemeldet). «Vor dem 240. Tag» oder «nach dem 240. Tag» steht nur, wenn das Geschäftsjahr das Kalenderjahr ist. Ohne Korrektur: «Keine Korrekturen — keine Jahresabstimmung einzureichen».</p>
</section>

<section data-help-field="year_sum">
<h3>Summe pro Ziffer</h3>
<p><strong>deklariert</strong> (die gebuchten Abrechnungen), <strong>Korrekturen</strong> (die Blöcke) und <strong>neu</strong>. Die Spalte «Korrekturen» tippst du im ePortal in die Jahresabstimmung (Berichtigungsabrechnung). Unter jedem Steuerbetrag steht klein der exakte Wert, damit jede Summe nachgerechnet werden kann; «neu» ist einmal aus den exakten Werten gerundet. Die Zahllast ist die Summe der gebuchten Zahllasten plus die Differenz Zahllast der Korrekturen — sie wird nicht neu gerundet.</p>
</section>

<section data-help-field="pending">
<h3>Erfasst, noch nicht abgerechnet</h3>
<p>Korrekturbuchungen für dieses Jahr, deren Abrechnung noch nicht gebucht ist — «kommt mit» nennt sie. Sie sind noch nicht in der Summe pro Ziffer. Vor dem Einreichen der Jahresabstimmung abwarten: sie geht nur einmal pro Steuerjahr; was später kommt, geht als Korrekturabrechnung der Periode.</p>
</section>

<section data-help-field="deadlines">
<h3>180. Tag, 240. Tag</h3>
<p>Korrekturen gehören spätestens in die Abrechnungsperiode, in die der 180. Tag nach Ende des Geschäftsjahres fällt (Art. 72 MWSTG). Nach dem 240. Tag geht die ESTV davon aus, dass das Steuerjahr vollständig deklariert ist. Die Tage stehen nur, wenn das Geschäftsjahr das Kalenderjahr ist; sonst «Frist bei der ESTV klären».</p>
</section>

<section data-help-field="interest">
<h3>Verzugszins</h3>
<p>z77 rechnet keinen Verzugszins. Die ESTV stellt ihn ab der Fälligkeit der korrigierten Periode in Rechnung.</p>
</section>

<section data-help-field="earlier_year">
<h3>Fehler aus diesem Jahr, später gefunden</h3>
<p>Als Korrekturbuchung zur Periode erfassen und im ePortal melden. Eine normale Buchung im neuen Jahr deklariert die Steuer im falschen Steuerjahr; z77 kann das nicht erkennen.</p>
</section>

<section data-help-field="turnover">
<h3>Umsatz</h3>
<p>Die Ertragskonten des Kalenderjahres — Bewegungen ohne Eröffnung und MWST-Abrechnungsbuchungen — gegen die deklarierten Umsätze (Ziffer 200 minus 235), mit den Positionen von Art. 128 Abs. 2 MWSTV. Was z77 nicht kennen kann, steht als «von Hand prüfen» ohne Betrag. Grau steht, was nur zur Information da ist (Ziffer 235 ist in der Deklaration schon abgezogen). Ein unerklärter Rest ist rot und sperrt nichts. Korrekturen für das Jahr, die erst eine Abrechnung eines späteren Jahres gemeldet hat, stehen daneben: ihre Buchungen liegen im späteren Jahr, «Neu deklariert» zählt sie dazu. Die Kontonummern öffnen das Kontoblatt.</p>
</section>

<section data-help-field="input">
<h3>Vorsteuer und MWST-Konten</h3>
<p>Jedes MWST-Konto: seine Bewegung im Jahr, die Ziffern, in denen es deklariert ist, was die Abrechnungen deklariert haben, die Rundung (die Steuer der Buchungen mit Code minus deklariert — solange die Übersicht vorläufig ist, auch die Steuer der offenen Perioden) und der Rest (Bewegung minus die Steuer der Buchungen mit Code). Ein Rest ist rot. Das Konto «Vorsteuer, noch nicht abgerechnet» (z.B. 1179) steht am 31.12. auf 0.00, weil über die Jahresgrenze keine Vorsteuer nachgeholt wird.</p>
</section>

<section data-help-field="correction_pending">
<h3>MWST-Korrektur, noch nicht abgerechnet (z.B. 2209)</h3>
<p>Das Durchgangskonto für eine Korrektur über das Jahresende: am letzten Tag des Geschäftsjahres ohne MWST-Code gebucht, mit der Korrekturbuchung in der laufenden Periode ausgeglichen. Die Übersicht zeigt seinen Saldo am 31.12. und daneben, was davon heute noch offen ist. Ist noch etwas offen, steht die Korrekturbuchung aus — der Jahresabschluss warnt ebenfalls.</p>
</section>

<section data-help-field="provisional">
<h3>vorläufig</h3>
<p>Solange eine Periode des Jahres nicht gebucht oder das Geschäftsjahr mit dem 31.12. nicht abgeschlossen ist, ist die Übersicht vorläufig: lesbar und druckbar (das PDF trägt «VORLÄUFIG»), aber nicht ablegbar. Eine offene Periode zählt nicht in «deklariert»; ihr Umsatz steht in der Umsatzabstimmung als «noch nicht gebucht, berechnet».</p>
</section>

<section data-help-field="store">
<h3>Ablegen …</h3>
<p>Legt das PDF mit Stand und Zeit unveränderbar im Archiv ab und schreibt eine Zeile ins Protokoll «Abschlüsse und MWST» (Finanzen › Änderungsprotokoll). Es wird nichts gebucht. «Ablegen …» gibt es erst nach dem Abschluss: alle Perioden gebucht und das Geschäftsjahr abgeschlossen. Danach steht im Kopf «abgelegt», wer wann abgelegt hat und das abgelegte PDF.</p>
<p><strong>Seit der Ablage geändert</strong>: ändert sich die Übersicht danach — eine später gemeldete Korrektur, eine Buchung ohne Code im Jahr, ein wieder geöffnetes Geschäftsjahr —, steht im Kopf «Seit der Ablage geändert — das abgelegte PDF zeigt den Stand vom …», und «Ablegen …» ist wieder da. Jede Ablage bleibt im Archiv und im Protokoll; die neueste gilt.</p>
</section>

<section data-help-field="fiscal_year">
<h3>Geschäftsjahr ≠ Kalenderjahr</h3>
<p>Die Übersicht rechnet immer das Kalenderjahr, über alle Geschäftsjahre, die es berührt. Die Überleitung zur Jahresrechnung machst du von Hand.</p>
</section>
