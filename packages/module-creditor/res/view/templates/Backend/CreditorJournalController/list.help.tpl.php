<?php
/**
 * Help for the «Kreditorenjournal» (ADR-048, HELP-003: at the right end of the toolbar) — a general
 * part, then one section per search field of the list (`data-help-field` = the field's key): F1 in
 * a field jumps to its section. Also the KI-Assistent's knowledge (ADR-055) — buttons and fields
 * named as on screen.
 */
?>
<h3>Kreditorenjournal</h3>
<p>Hier landen die Rechnungen deiner Lieferanten als PDF. Du prüfst und ergänzt sie hier. In der Buchhaltung stehen sie noch nicht — das Verbuchen mit dem Zahlungsdatum folgt in einem nächsten Schritt.</p>
<p><strong>PDF hochladen</strong> (oben links) nimmt eine oder mehrere PDF-Rechnungen, du kannst sie auch einfach auf die Seite ziehen. Jede Datei wird unverändert unter «Kreditorenrechnungen» in den Dokumenten abgelegt und ergibt eine Zeile «zu prüfen». Dieselbe Datei wird nie zweimal eingelesen: lädst du sie nochmals hoch, steht dort «bereits eingelesen am …», und nichts passiert. Nur PDF, höchstens 20 MB pro Datei.</p>
<ul>
    <li><strong>Im Journal</strong> — die offenen Rechnungen. Oben steht, wie viele es sind und was sie zusammen ausmachen. Eine neue Rechnung ohne Datum steht zuoberst.</li>
    <li><strong>Gesperrt</strong> — Rechnungen, die ein <strong>mögliches Doppel</strong> einer anderen sind: gleicher Kreditor und gleiche Rechnungs-Nr., oder gleiche IBAN und gleiche Referenz (oft eine Mahnung zur selben Rechnung). Im Fenster entscheidest du: «Kein Doppel — übernehmen» oder «Verwerfen …».</li>
    <li><strong>Verbuchte Journale</strong> — jedes spätere «Journal verbuchen» mit Datum, wer, wie viele Rechnungen und Total. Das Verbuchen folgt.</li>
</ul>
<p>Das Zeichen am Zeilenanfang öffnet die Rechnung im Fenster: links das PDF, rechts die Felder. <strong>vollständig</strong> heisst: Kreditor, Betrag, «Bezahlt mit» und in jeder Zeile Konto und MWST sind gesetzt, und die Zeilen ergeben zusammen den Betrag. <strong>zu prüfen</strong> heisst: etwas davon fehlt noch — im Fenster ist es mit «— fehlt» markiert. <strong>Doppel?</strong> heisst: gesperrt.</p>
<p>Automatisch erkennen, was auf der Rechnung steht (Kreditor, Betrag, Referenz aus dem QR-Einzahlungsschein), folgt ebenfalls — bis dahin trägst du es im Fenster ein.</p>
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
<p>Das Rechnungsdatum. Eine Rechnung, die noch niemand geprüft hat, hat keines und steht zuoberst. Suche: Tag (<code>15.11.2026</code>), Monat (<code>11.2026</code>) oder Jahr.</p>
</section>
<section data-help-field="f_amount">
<h3>Betrag</h3>
<p>Der Rechnungsbetrag in CHF inklusive MWST. Suche: der genaue Betrag.</p>
</section>
