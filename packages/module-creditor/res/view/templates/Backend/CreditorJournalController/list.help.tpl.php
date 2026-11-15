<?php
/**
 * Help for the «Kreditorenjournal» (ADR-048, HELP-003: at the right end of the toolbar; also in the
 * title bar of the windows «Bezahlt am …» and «Journal verbuchen …») — a general part, then one
 * section per search field of the list (`data-help-field` = the field's key): F1 in a field jumps to
 * its section. Also the KI-Assistent's knowledge (ADR-055) — buttons and fields named as on screen.
 */
?>
<h3>Kreditorenjournal</h3>
<p>Hier landen die Rechnungen deiner Lieferanten als PDF. Du prüfst und ergänzt sie, setzt «bezahlt am» und bringst die bezahlten mit «Journal verbuchen …» in die Buchhaltung.</p>
<h4>Rechnungen hochladen</h4>
<p><strong>PDF hochladen</strong> (in der Werkzeugleiste) nimmt eine oder mehrere PDF-Rechnungen, du kannst sie auch einfach auf die Seite ziehen. Jede Datei wird unverändert unter «Kreditorenrechnungen» in den Dokumenten abgelegt und ergibt eine Zeile «zu prüfen». Dieselbe Datei wird nie zweimal eingelesen: lädst du sie nochmals hoch, steht dort «bereits eingelesen am …», und nichts passiert. Nur PDF, höchstens 20 MB pro Datei.</p>
<h4>Die drei Tabs</h4>
<ul>
    <li><strong>Im Journal</strong> — die noch nicht verbuchten Rechnungen. Oben steht, wie viele es sind, wie viele davon unbezahlt und wie viele bezahlt, und was sie zusammen ausmachen. Eine neue Rechnung ohne Datum steht zuoberst.</li>
    <li><strong>Gesperrt</strong> — Rechnungen, die ein <strong>mögliches Doppel</strong> einer anderen sind: gleicher Kreditor und gleiche Rechnungs-Nr., oder gleiche IBAN und gleiche Referenz (oft eine Mahnung zur selben Rechnung). Im Fenster entscheidest du: «Kein Doppel — übernehmen» oder «Verwerfen …».</li>
    <li><strong>Verbuchte Journale</strong> — jedes «Journal verbuchen» mit Datum, wer, wie viele Rechnungen und Total.</li>
</ul>
<p>Das Zeichen am Zeilenanfang öffnet die Rechnung im Fenster: links das PDF, rechts die Felder. <strong>vollständig</strong> heisst: Kreditor, Betrag, «Bezahlt mit» und in jeder Zeile Konto und MWST sind gesetzt, und die Zeilen ergeben zusammen den Betrag. <strong>zu prüfen</strong> heisst: etwas davon fehlt noch — im Fenster ist es mit «— fehlt» markiert. <strong>Doppel?</strong> heisst: gesperrt.</p>
<h4>Bezahlt am …</h4>
<p>Kreuz in «Im Journal» die Rechnungen an, die du bezahlt hast, und klick <strong>Bezahlt am …</strong> über der Liste. Im Fenster wählst du das Zahlungsdatum (vorgeschlagen: heute, nie in der Zukunft) und, wenn nötig, «Bezahlt mit» für alle. Eine einzelne Rechnung kannst du auch in ihrem Fenster im Feld «Bezahlt am» setzen oder wieder leeren. Wurde eine Rechnung inzwischen geändert, wird sie nicht gesetzt — die Meldung nennt sie.</p>
<section data-help-field="cutoff">
<h4>Journal verbuchen …</h4>
<p>Der grüne Knopf oben links bucht alle Rechnungen, die bis und mit <strong>Stichtag</strong> bezahlt sind: eine Buchung pro Rechnung, datiert mit «bezahlt am» — Aufwand (und Vorsteuer) an das Konto von «Bezahlt mit». Der Stichtag ist heute vorgeschlagen; ändern zeigt das Fenster neu. Unbezahlte Rechnungen bleiben im Journal und stören nicht. Ist eine bezahlte Rechnung gesperrt oder unvollständig, zeigt das Fenster nur die Fehler mit einem Link zur Rechnung — dann wird nichts gebucht. Gebucht wird alles oder nichts; danach ist die Rechnung unveränderlich und ihr PDF wird 10 Jahre aufbewahrt (bis Ende des Geschäftsjahrs der Zahlung plus 10 Jahre).</p>
<p>Gebucht wird nach dem Zahlungsdatum (Ist-Prinzip): eine Rechnung vom Dezember, die du im Januar bezahlst, ist Aufwand des neuen Jahres. Unbezahlte Rechnungen stehen am Jahresende nicht als Schulden in der Buchhaltung. Ist der Mandant nicht MWST-pflichtig, geht nur «ohne MWST».</p>
</section>
<section data-help-field="f_creditor">
<h3>Kreditor</h3>
<p>Der Lieferant der Rechnung, oder «Vorschlag: …», solange er nur vorgeschlagen ist. Suche: ein Teil des Namens.</p>
</section>
<section data-help-field="f_number">
<h3>Rechnungs-Nr.</h3>
<p>Die Nummer, die der Lieferant seiner Rechnung gegeben hat. Suche: ein Teil der Nummer.</p>
</section>
<section data-help-field="f_date">
<h3>Datum</h3>
<p>Das Rechnungsdatum. Eine Rechnung, die noch niemand geprüft hat, hat keines und steht zuoberst. Gebucht wird nicht mit diesem Datum, sondern mit «bezahlt am». Suche: Tag (<code>15.11.2026</code>), Monat (<code>11.2026</code>) oder Jahr.</p>
</section>
<section data-help-field="f_amount">
<h3>Betrag</h3>
<p>Der Rechnungsbetrag in CHF inklusive MWST. Suche: der genaue Betrag.</p>
</section>
