<?php
/**
 * Help for the «Kreditorenjournal» (ADR-048, HELP-003: at the right end of the toolbar; also in the
 * title bar of the windows «Bezahlt am …», «Löschen …» and «Journal verbuchen …») — a general part,
 * then one section per search field of the list (`data-help-field` = the field's key): F1 in a
 * field jumps to its section. Also the KI-Assistent's knowledge (ADR-055) — buttons and fields
 * named as on screen.
 *
 * Written for people (owner 2026-11-16: «die Hilfe ist für menschliches Lesen viel zu
 * kompliziert»): everyday words, short sentences, what the user does and sees first; no internal
 * terms. The AI paragraph carries the privacy sentence word for word —
 * {@see \Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader::PRIVACY_NOTE} (static text, the
 * harness compares it).
 */
?>
<h3>Kreditorenjournal</h3>
<p>Hier sammelst du die Rechnungen deiner Lieferanten als PDF. Du prüfst sie, setzt «bezahlt am» und buchst die bezahlten mit «Journal verbuchen …».</p>

<h4>Rechnungen hochladen</h4>
<p>Zieh die PDF-Dateien auf <strong>PDF hochladen</strong> oder irgendwo auf die Liste. Oder klick auf den Knopf und wähl die Dateien aus. Mehrere auf einmal gehen, bis 20 MB pro Datei.</p>
<p>Jede Datei wird eine Zeile in der Liste. Das PDF liegt danach unverändert unter «Kreditorenrechnungen» in den Dokumenten. Lädst du dieselbe Datei nochmals hoch, steht «bereits eingelesen», und es passiert nichts.</p>

<h4>Was das System selbst liest</h4>
<p>Nach dem Hochladen steht bei der Datei <strong>Erkennung ausstehend</strong> und in der Spalte «Erkannt» <strong>in Arbeit …</strong>. Meist nach einer Minute stehen Betrag, IBAN, Referenz und Lieferant in der Rechnung, oft auch Rechnungsnummer und Datum. Die Liste aktualisiert sich dabei selbst.</p>
<p>Das System füllt nur leere Felder — was du eingetragen hast, bleibt. Prüf jede Rechnung trotzdem, bevor du sie verbuchst.</p>
<ul>
    <li><strong>Bekannter Lieferant:</strong> Er wird gesetzt, mit seinem Konto, seiner MWST und «Bezahlt mit».</li>
    <li><strong>Neuer Lieferant:</strong> In der Liste steht «Vorschlag: …». Öffne die Rechnung und klick «Kreditor übernehmen …».</li>
    <li>Gehört die IBAN <strong>mehreren Kreditoren</strong> (z.B. einer Firmengruppe), wählst du den richtigen in der Rechnung.</li>
    <li><strong>Nicht erkannt</strong> (z.B. ein Scan): Du trägst die Werte von Hand ein.</li>
</ul>
<p>Die Spalte «Erkannt» zeigt, woher die Werte stammen: «Text», «KI» oder «von Hand». Ein «!» heisst: Es gibt einen Hinweis, er steht oben in der Rechnung. <strong>Jetzt erkennen</strong> in der Rechnung lässt das System nochmals lesen. Bleibt eine Rechnung lange auf «in Arbeit …», unter Service › Jobs nachsehen, ob «Kreditoren-Eingang» läuft.</p>
<p>Rechnungen ohne QR-Einzahlungsschein, z.B. aus dem Ausland, erkennt das System nach dem ersten Mal selbst: Speicherst du eine solche Rechnung mit Kreditor, merkt es sich, woran dieser Lieferant zu erkennen ist und wo Nummer, Datum und Betrag stehen. Passt das Gelernte zu mehreren Kreditoren, steht «Kreditor wählen (passt zu: …)».</p>

<h4>Die Tabs</h4>
<ul>
    <li><strong>Im Journal</strong> — die Rechnungen, die noch nicht verbucht sind.</li>
    <li><strong>Gesperrt</strong> — Rechnungen, die vielleicht doppelt sind: gleicher Lieferant und gleiche Rechnungsnummer, oder gleiche IBAN und Referenz (oft eine Mahnung). Öffne sie und entscheide: «Kein Doppel — übernehmen», «Verwerfen …» oder «Löschen …».</li>
    <li><strong>Verbuchte Journale</strong> — jedes Verbuchen mit Datum, wer, Anzahl und Total.</li>
    <li><strong>Nicht eingelesen</strong> — Dateien aus dem Eingangsordner, die nicht gelesen werden konnten, mit dem Grund. Dort «Erneut versuchen» oder «Entfernen». Mehrere Dateien kreuzt du an und klickst «Entfernen …» — das Kästchen im Kopf kreuzt alle an.</li>
</ul>

<h4>Was eine Zeile zeigt</h4>
<p>Das Zeichen vorne öffnet die Rechnung: links das PDF, rechts die Felder. Hinten steht der Zustand:</p>
<ul>
    <li><strong>vollständig</strong> — alles ist da. Ist sie bezahlt, kann sie verbucht werden.</li>
    <li><strong>zu prüfen</strong> — es fehlt noch etwas. In der Rechnung ist es mit «— fehlt» markiert.</li>
    <li><strong>Doppel?</strong> — gesperrt, siehe oben.</li>
</ul>

<h4>Mehrere Rechnungen auf einmal</h4>
<p>Kreuz die Rechnungen vorne an. Das Kästchen im Kopf der Liste kreuzt alle Rechnungen der Seite an. Dann:</p>
<ul>
    <li><strong>Bezahlt am …</strong> — markiert sie als bezahlt. Du wählst das Datum (heute ist vorgeschlagen, nie in der Zukunft) und, wenn nötig, «Bezahlt mit». Einzeln geht das auch im Feld «Bezahlt am» der Rechnung. Gesperrte Rechnungen gehen nicht: Zuerst entscheidest du, ob es ein Doppel ist — im Tab «Gesperrt» gibt es darum nur «Löschen …».</li>
    <li><strong>Löschen …</strong> — löscht sie ganz, auch gesperrte. Du bestätigst zuerst. Die PDFs kommen in den Papierkorb der Dokumente, und du kannst dieselben Dateien wieder hochladen. Verbuchte Rechnungen lassen sich nicht löschen.</li>
</ul>
<p>Hat jemand eine angekreuzte Rechnung inzwischen geändert, lässt das System sie aus und nennt sie in der Meldung.</p>

<section data-help-field="cutoff">
<h4>Journal verbuchen …</h4>
<p>Der grüne Knopf oben links bucht alle Rechnungen, die bis zum <strong>Stichtag</strong> bezahlt sind. Vorgeschlagen ist heute. Jede Rechnung wird mit ihrem Zahlungsdatum gebucht: Aufwand und Vorsteuer an das Konto von «Bezahlt mit». Unbezahlte bleiben im Journal.</p>
<p>Ist eine bezahlte Rechnung gesperrt oder unvollständig, zeigt das Fenster nur diese Fehler, mit einem Link zur Rechnung — dann wird nichts gebucht. Eine verbuchte Rechnung lässt sich nicht mehr ändern, und ihr PDF wird 10 Jahre aufbewahrt.</p>
<p>Massgebend ist das Zahlungsdatum: Eine Rechnung vom Dezember, die du im Januar bezahlst, ist Aufwand des neuen Jahres. Ist dein Mandant nicht MWST-pflichtig, gibt es nur «ohne MWST».</p>
</section>

<h4>Eingangsordner</h4>
<p>Rechnungen kommen auch ohne Hochladen herein: Was im Eingangsordner auf dem Server liegt (<code>data/framework/creditor/intake</code>, z.B. von einem Skript auf deinem PC dorthin kopiert), liest das System jede Minute ein — wie eine hochgeladene Datei. Was nicht gelesen werden kann, steht im Tab «Nicht eingelesen». Neue Rechnungen erscheinen in der offenen Liste von selbst; ändert sich 10 Minuten lang nichts, fragt sie nicht mehr nach — dann die Seite neu laden.</p>

<h4>KI-Erkennung</h4>
<p>Die KI liest Rechnungen, die das System allein nicht lesen kann, z.B. einen Scan. Die Spalte zeigt dann <strong>KI liest …</strong>. Auch die KI füllt nur leere Felder und wählt nie ein Konto. Das kostet pro Rechnung ein paar Rappen.</p>
<p>Bei einem Lieferanten ohne QR-Einzahlungsschein liest die KI nur die ersten Rechnungen mit. Stimmt zweimal überein, was das System an den gelernten Stellen liest und was die KI liest, liest das System die Rechnungen dieses Lieferanten allein. Korrigierst du einen Wert, lernt es neu und die KI liest wieder mit.</p>
<p>Der Schalter <strong>KI-Erkennung</strong> neben «PDF hochladen» schaltet sie ein und aus. Ist er aus, liest nur das System; was es nicht erkennt, erfasst du von Hand. Das gilt für alle Rechnungen, die danach gelesen werden — auch für «Jetzt erkennen». Den Schalter gibt es nur, wenn die KI für diese Installation freigegeben ist.</p>
<p>Ist die KI-Erkennung der Kreditorenrechnungen eingeschaltet, wird das PDF einer Lieferantenrechnung, die das System nicht selbst lesen kann (Scan ohne Text, ausländische Rechnung ohne QR-Zahlteil, unbekanntes Konto), zur Auswertung an Anthropic PBC in den USA übermittelt. Übermittelt wird nur dieses PDF — keine Kontakte, keine Buchungen und keine anderen Rechnungen.</p>

<section data-help-field="f_creditor">
<h3>Kreditor</h3>
<p>Der Lieferant der Rechnung, oder «Vorschlag: …», solange er noch nicht übernommen ist. Suche: ein Teil des Namens.</p>
</section>
<section data-help-field="f_number">
<h3>Rechnungs-Nr.</h3>
<p>Die Nummer, die der Lieferant seiner Rechnung gegeben hat. Suche: ein Teil der Nummer.</p>
</section>
<section data-help-field="f_date">
<h3>Datum</h3>
<p>Das Rechnungsdatum. Eine neue Rechnung ohne Datum steht zuoberst. Gebucht wird mit «bezahlt am», nicht mit diesem Datum. Suche: Tag (<code>15.11.2026</code>), Monat (<code>11.2026</code>) oder Jahr.</p>
</section>
<section data-help-field="f_amount">
<h3>Betrag</h3>
<p>Der Betrag in CHF mit MWST. Lautet die Rechnung auf eine andere Währung, steht ihr Betrag klein darunter, z.B. «9.00 USD»; fehlt der CHF-Betrag noch, steht «—». Suche: der genaue Betrag, in CHF oder in der Währung der Rechnung.</p>
</section>
