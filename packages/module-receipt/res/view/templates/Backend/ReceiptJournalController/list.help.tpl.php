<?php
/**
 * Help for the «Belegjournal» (ADR-048, HELP-003: at the right end of the toolbar; in the window
 * «Journal verbuchen» its title bar) — a general part, then one section per search field of the
 * list and one for the cut-off (`data-help-field` = the field's key): F1 in a field jumps to its
 * section. Also the KI-Assistent's knowledge (ADR-055) — buttons and fields named as on screen.
 */
?>
<h3>Belegjournal</h3>
<p>Die Belege, die mit dem Handy fotografiert wurden und noch nicht verbucht sind: prüfen, Konten ergänzen, aufteilen oder löschen — und mit «Journal verbuchen …» gesammelt in die Buchhaltung übernehmen.</p>
<p>Jeder Beleg bringt vom Handy Foto, Betrag, Datum, Text — und, wenn dort gewählt, die Vorlage (Soll und MWST) und «Bezahlt mit» (Haben). In der Buchhaltung steht er <strong>noch nicht</strong>. Lieferantenrechnungen als PDF gehören nicht hierher, sondern ins Kreditorenjournal (in Arbeit, folgt).</p>
<h4>Im Journal und Verbuchte Journale</h4>
<ul>
    <li><strong>Im Journal</strong> — die erfassten, noch nicht verbuchten Belege, nach Datum. Oben steht, wie viele es sind und was sie zusammen ausmachen — oder rot, welche Belege so nicht verbucht werden können. Unten die Summen je MWST-Code (die MWST ist im Betrag enthalten und wird herausgerechnet).</li>
    <li><strong>Verbuchte Journale</strong> — jedes «Journal verbuchen» mit Datum, wer, wie viele Belege und Total.</li>
</ul>
<p>Das Zeichen am Zeilenanfang öffnet den Beleg im Fenster: links das Foto, rechts die Felder. <strong>vollständig</strong> heisst: Soll, Haben, die MWST-Entscheidung (ein Code oder «ohne MWST») und ein Text sind gesetzt. <strong>Konten fehlen</strong> (oder <strong>Text fehlt</strong>, wenn nur der Text fehlt) heisst: mindestens eines davon fehlt noch — im Fenster ist es mit «— fehlt» markiert.</p>
<h4>Journal verbuchen: Stichtag, alles oder nichts</h4>
<p><strong>Journal verbuchen …</strong> öffnet ein Fenster mit dem Stichtag (vorgeschlagen: heute). Kann ein Beleg bis dahin nicht verbucht werden, stehen dort <strong>nur die Fehler</strong>: welcher Beleg, was fehlt — ein Klick öffnet den Beleg, nach dem Speichern rechnet das Fenster neu. Sonst steht kurz da, was verbucht wird (Anzahl, Total, MWST je Code), und <strong>«Jetzt buchen»</strong> verbucht — jeder Beleg wird eine eigene Buchung mit seinem Belegdatum, alles in einem Schritt, alles oder nichts. Danach schliesst sich das Fenster, die Liste lädt neu, und der Beleg ist <strong>verbucht</strong> — hier nicht mehr änderbar. Sein Foto wird ab dann <strong>10 Jahre</strong> nach Ende des Geschäftsjahres aufbewahrt (gesetzliche Aufbewahrungspflicht) und lässt sich in dieser Zeit nicht endgültig löschen.</p>
<h4>Warum ein Beleg nicht verbucht werden kann</h4>
<p><strong>Gesperrt</strong> ist ein Lauf, solange ein Beleg darin unvollständig ist, sein Datum kein Geschäftsjahr hat, in einer abgeschlossenen Periode liegt (oder in einer, deren MWST schon abgerechnet ist) oder seine Konten so nicht buchbar sind — dann zuerst ergänzen, umdatieren oder löschen. Der Jahresabschluss bleibt gesperrt, solange Belege seines Jahres hier stehen (ebenso später die MWST-Abrechnung, die noch nicht verfügbar ist), und die Auswertungen nennen sie in einem Hinweis — gebucht sind sie erst nach «Journal verbuchen».</p>
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
<p>Wer den Beleg erfasst hat: «Handy &lt;Benutzer&gt; · &lt;Gerät&gt;» für einen Beleg vom Handy (der Name der Person, mit deren App-Zugang das Handy angemeldet ist). Suche: ein Teil des Benutzer- oder Gerätenamens.</p>
</section>
<section data-help-field="cutoff">
<h3>Stichtag beim Verbuchen</h3>
<p>Verbucht werden alle Belege mit Datum <strong>bis und mit</strong> dem Stichtag; spätere bleiben im Journal (eine graue Zeile nennt sie) und das Handy erfasst weiter. Vorgeschlagen ist <strong>heute</strong> — also das ganze Journal; für «nur den Vormonat» den letzten Tag des Vormonats wählen. Ein anderes Datum rechnet das Fenster sofort neu (getippt: beim Verlassen des Feldes, oder «Anzeigen»). Ist seit dem Anzeigen ein Beleg bis zum Stichtag dazugekommen oder geändert worden, wird nichts verbucht — das Fenster zeigt den neuen Stand, dann nochmals «Jetzt buchen».</p>
</section>
