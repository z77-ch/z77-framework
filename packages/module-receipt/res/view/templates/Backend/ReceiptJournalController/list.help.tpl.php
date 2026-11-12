<?php
/**
 * Help for the «Belegjournal» (ADR-048, HELP-003: at the right end of the toolbar; on the page
 * «Journal verbuchen» in the top bar) — a general part, then one section per search field of the
 * list and one for the cut-off (`data-help-field` = the field's key): F1 in a field jumps to its
 * section. Also the KI-Assistent's knowledge (ADR-055) — buttons and fields named as on screen.
 */
?>
<h3>Belegjournal</h3>
<p>Das <strong>Belegjournal</strong> sammelt die Belege, die mit dem Handy erfasst wurden: Foto, Betrag, Datum, Text — und, wenn auf dem Handy gewählt, die Vorlage (Soll und MWST) und «Bezahlt mit» (Haben). In der Buchhaltung stehen sie <strong>noch nicht</strong>. Hier werden sie geprüft, ergänzt und am Ende des Monats mit <strong>«Journal verbuchen …»</strong> gesammelt verbucht.</p>
<ul>
    <li><strong>Im Journal</strong> — die erfassten, noch nicht verbuchten Belege, nach Datum. Oben steht, wie viele es sind und was sie zusammen ausmachen — oder rot, welche Belege so nicht verbucht werden können. Unten die Summen je MWST-Code (die MWST ist im Betrag enthalten und wird herausgerechnet).</li>
    <li><strong>Verbuchte Journale</strong> — jedes «Journal verbuchen» mit Datum, wer, wie viele Belege und Total.</li>
</ul>
<p>Das Zeichen am Zeilenanfang öffnet den Beleg im Fenster: links das Foto, rechts die Felder. <strong>vollständig</strong> heisst: Soll, Haben, die MWST-Entscheidung (ein Code oder «ohne MWST») und ein Text sind gesetzt. <strong>Konten fehlen</strong> heisst: mindestens eines davon fehlt noch — im Fenster ist es mit «— fehlt» markiert.</p>
<p><strong>Journal verbuchen …</strong> zeigt zuerst den Lauf bis zum Stichtag: Anzahl, Total, MWST je Code und die Buchungen je Konto. Erst die Bestätigung verbucht — jeder Beleg wird eine eigene Buchung mit seinem Belegdatum, alles in einem Schritt, alles oder nichts. Danach ist der Beleg <strong>verbucht</strong> und lässt sich hier nicht mehr ändern.</p>
<p><strong>Gesperrt</strong> ist ein Lauf, solange ein Beleg darin unvollständig ist, kein Geschäftsjahr hat oder in einer abgeschlossenen Periode liegt — dann zuerst ergänzen, umdatieren oder löschen. Der Jahresabschluss und die MWST-Abrechnung bleiben gesperrt, solange Belege ihres Zeitraums hier stehen.</p>
<section data-help-field="f_date">
<h3>Datum</h3>
<p>Das Belegdatum — an diesem Datum wird der Beleg verbucht. Suche: Tag (<code>15.11.2026</code>), Monat (<code>11.2026</code>) oder Jahr.</p>
</section>
<section data-help-field="f_text">
<h3>Text</h3>
<p>Der Buchungstext, auf dem Handy getippt oder von der Vorlage übernommen. Suche: ein Teil des Textes.</p>
</section>
<section data-help-field="f_amount">
<h3>Betrag</h3>
<p>Der Betrag des Belegs inkl. MWST, wie er auf dem Zettel steht. Suche: der genaue Betrag.</p>
</section>
<section data-help-field="f_by">
<h3>Erfasst von</h3>
<p>Wer den Beleg erfasst hat: «Handy &lt;Benutzer&gt; · &lt;Gerät&gt;» für einen Beleg vom Handy (das Gerät gehört dem Benutzer, der es gekoppelt hat). Suche: ein Teil des Benutzer- oder Gerätenamens.</p>
</section>
<section data-help-field="cutoff">
<h3>Stichtag</h3>
<p>Verbucht werden alle Belege mit Datum <strong>bis und mit</strong> dem Stichtag; spätere bleiben im Journal und das Handy erfasst weiter. Vorgeschlagen ist der letzte Tag des Vormonats. «Anzeigen» rechnet die Seite für einen anderen Stichtag neu. Ist seit dem Anzeigen ein Beleg bis zum Stichtag dazugekommen oder geändert worden, wird nichts verbucht — die Seite neu laden.</p>
</section>
