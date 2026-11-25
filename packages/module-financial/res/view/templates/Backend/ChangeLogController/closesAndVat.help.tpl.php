<?php
/**
 * Help for the tab «Abschlüsse und MWST» of the Änderungsprotokoll (ADR-048, HELP-003: «Hilfe» at
 * the right end of the toolbar) — what the protocol records and how to read a row; a section per
 * search field (`data-help-field` = the column key). Also the KI-Assistent's knowledge (ADR-055):
 * «wer hat das Jahr geöffnet», «MWST-Abrechnung aufgehoben», «wann wurde die MWST gebucht».
 */
?>
<h3>Abschlüsse und MWST</h3>
<p>Der Reiter <strong>«Abschlüsse und MWST»</strong> zeigt das Protokoll des Jahresabschlusses und der MWST-Abrechnung: wer ein Geschäftsjahr abgeschlossen oder wieder geöffnet, wer eine MWST-Abrechnung gebucht oder aufgehoben und wer eine MWST-Jahresübersicht abgelegt hat, wann und warum. Die neuesten Einträge stehen zuoberst. Das Protokoll ist nur zum Nachlesen — hier wird nichts geändert.</p>
<ul>
    <li>Der Reiter <strong>«Buchungen»</strong> zeigt die Änderungen und Löschungen einzelner Buchungen; dieser Reiter die Abschlüsse und die MWST.</li>
    <li>Die Liste zeigt das Geschäftsjahr, das oben im Menü gewählt ist: seinen Abschluss und seine Öffnungen, die MWST-Abrechnungen der Perioden, die in diesem Jahr enden, und die abgelegten Jahresübersichten des Steuerjahres, dessen 31.12. in diesem Jahr liegt. Der letzte Eintrag der Auswahl, <strong>«Alle Jahre»</strong>, zeigt alles.</li>
    <li><strong>Betrifft</strong> führt zur MWST-Abrechnung der Periode, zu den Geschäftsjahren oder zur Jahresübersicht des Steuerjahres. Die Fassung zählt mit, wie oft eine Periode gebucht wurde: nach einem Aufheben wird sie als nächste Fassung neu gebucht.</li>
    <li>Ein Eintrag wird nie geändert oder gelöscht — auch eine aufgehobene MWST-Abrechnung bleibt mit beiden Einträgen stehen.</li>
</ul>
<h3>Was die Einträge bedeuten</h3>
<ul>
    <li><strong>MWST gebucht</strong>: die MWST-Abrechnung der Periode ist gebucht — gebucht heisst eingereicht. Angaben: die Abrechnungsbuchung (Nummer im Journal; «keine» bei einer Nullabrechnung), die Zahllast und die Warnungen, die beim Buchen bestätigt wurden.</li>
    <li><strong>MWST aufgehoben</strong>: ein Admin hat die Abrechnung aufgehoben — mit Grund. Die Abrechnungsbuchung wurde storniert (Storno-Nummer); die Periode ist wieder offen.</li>
    <li><strong>Jahr abgeschlossen</strong>: das ganze Geschäftsjahr wurde abgeschlossen. Angaben: die Warnungen der Abschlussprüfung, die bestätigt wurden, oder «keine Warnung».</li>
    <li><strong>Jahr geöffnet</strong>: ein Admin hat das abgeschlossene Jahr wieder geöffnet — mit Grund.</li>
    <li><strong>Jahresübersicht abgelegt</strong>: die MWST-Jahresübersicht des Steuerjahres wurde unveränderbar ins Archiv gelegt; vorn öffnet das Symbol das abgelegte PDF. Angaben: der Stand, die Korrekturen und die Differenz Zahllast. Hat sich die Übersicht danach geändert, wird sie nochmals abgelegt — jede Ablage bleibt stehen, die neueste gilt.</li>
</ul>
<p>Fährt man mit der Maus über «Grund / Angaben», erscheint der ganze Text, bei mehreren bestätigten Warnungen jede einzeln.</p>
<section data-help-field="f_at">
<h3>Zeit</h3>
<p>Wann abgeschlossen, geöffnet, gebucht oder aufgehoben wurde. Suche: Tag (<code>15.11.2026</code>), Monat (<code>11.2026</code>) oder Jahr.</p>
</section>
<section data-help-field="f_who">
<h3>Wer</h3>
<p>Der Benutzername, der es getan hat. Suche: ein Teil des Namens.</p>
</section>
<section data-help-field="f_what">
<h3>Was</h3>
<p>Die Art des Eintrags: «MWST gebucht», «MWST aufgehoben», «Jahr abgeschlossen», «Jahr geöffnet», «Jahresübersicht abgelegt». Suche: ein Teil davon — «MWST» findet beide MWST-Arten, «Jahr» die Jahres-Arten und die Jahresübersicht, «abgelegt» die Jahresübersicht.</p>
</section>
<section data-help-field="f_subject">
<h3>Betrifft</h3>
<p>Die MWST-Periode mit ihrer Fassung, das Geschäftsjahr oder das Steuerjahr einer Jahresübersicht. Suche: Tag, Monat oder Jahr — gefunden wird, was diesen Zeitraum berührt (<code>2026</code> findet das Geschäftsjahr 2026 und jede MWST-Periode in 2026).</p>
</section>
