<?php
/**
 * Help for the «Kreditorenjournal» (ADR-048, HELP-003: at the right end of the toolbar; also in the
 * title bar of the windows «Bezahlt am …» and «Journal verbuchen …») — a general part, then one
 * section per search field of the list (`data-help-field` = the field's key): F1 in a field jumps to
 * its section. Also the KI-Assistent's knowledge (ADR-055) — buttons and fields named as on screen.
 * Stage 2: the recognition (in the job only — «Erkennung ausstehend», «in Arbeit …» / «KI liest …»
 * and the list that updates itself, «Jetzt erkennen»), the header
 * labels, the intake folder and the tab «Nicht eingelesen», the opt-in AI with the privacy
 * sentence — word for word {@see \Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader::PRIVACY_NOTE}
 * (static text, the harness compares it).
 */
?>
<h3>Kreditorenjournal</h3>
<p>Hier landen die Rechnungen deiner Lieferanten als PDF. Du prüfst und ergänzt sie, setzt «bezahlt am» und bringst die bezahlten mit «Journal verbuchen …» in die Buchhaltung.</p>
<h4>Rechnungen hochladen</h4>
<p><strong>PDF hochladen</strong> (in der Werkzeugleiste) nimmt eine oder mehrere PDF-Rechnungen, du kannst sie auch einfach auf die Seite ziehen. Jede Datei wird unverändert unter «Kreditorenrechnungen» in den Dokumenten abgelegt und ergibt eine Zeile. Dieselbe Datei wird nie zweimal eingelesen: lädst du sie nochmals hoch, steht dort «bereits eingelesen am …», und nichts passiert. Nur PDF, höchstens 20 MB pro Datei.</p>
<h4>Automatisch erkannt</h4>
<p>Eine neue Rechnung steht zuerst mit <strong>Erkennung ausstehend</strong> im Journal. Die Erkennung läuft im Hintergrund, im Job «Kreditoren-Eingang» — meist innert einer Minute, mit KI einige Minuten. Bis dahin kannst du die Rechnung schon öffnen und bearbeiten.</p>
<h4>Während die Erkennung läuft</h4>
<p>Die Spalte «Erkannt» zeigt <strong>in Arbeit …</strong>, solange der Job die Rechnung liest, und <strong>KI liest …</strong>, während die KI sie gerade liest. So lange <strong>aktualisiert sich die Liste selbst</strong>, etwa alle 15 Sekunden, und hört auf, sobald keine Rechnung mehr in Arbeit ist. Kreuzt du gerade Zeilen an, tippst in einer Suche oder ist ein Dialog offen, wartet sie, bis du fertig bist. Steht eine Rechnung nach zehn Minuten unverändert auf «in Arbeit …», fragt die Liste nicht mehr nach — dann unter Service › Jobs nachsehen, ob der Job «Kreditoren-Eingang» läuft.</p>
<p>Das System liest den <strong>QR-Zahlteil</strong> aus dem Text des PDF: IBAN, Betrag, Referenz, Mitteilung und den Lieferanten; steht die Swico-Zeile («//S1/…») darauf, auch Rechnungs-Nr., Rechnungsdatum und Fälligkeit. Fehlen diese, sucht es sie im Briefkopf über der Zahlteil-Linie neben Bezeichnungen wie «Rechnungsnummer», «Rechnungsdatum», «Zahlbar bis» oder «zahlbar innert 30 Tagen» (auch französisch, italienisch, englisch) — eine Kundennummer nimmt es nie. Die Mitteilung aus dem Zahlteil (z.B. «Wingo, Januar 2026») wird der Text der Zeile. Es füllt nur <strong>leere</strong> Felder — was du eingetragen hast, bleibt. Kennt es den Kreditor (über eine seiner IBANs, sonst über die UID), setzt es ihn mit seinen Standardwerten (Konto, MWST, «Bezahlt mit»). Ist er unbekannt, steht «Vorschlag: …» — «Kreditor übernehmen …» im Fenster legt ihn an.</p>
<p>Gehört die IBAN <strong>mehreren Kreditoren</strong> (z.B. eine Firmengruppe, die für mehrere Marken kassiert), wählt das System keinen: die Zeile nennt die Kandidaten, und du wählst im Fenster. Ein Hinweis bei der IBAN im Kreditor (z.B. der Markenname, wie er auf der Rechnung steht) entscheidet, wenn er nur bei einem Kandidaten im Text vorkommt.</p>
<p>Die Spalte <strong>Erkannt</strong> sagt, woher die Werte stammen: «Text» (QR-Zahlteil), «KI» oder «von Hand». Ein «!» bedeutet Hinweise — sie stehen im Fenster oben. Ein Scan ohne Text, eine ausländische Rechnung ohne QR-Zahlteil oder eine Quittung wird nicht erkannt: «nicht erkannt — von Hand erfassen». Ist die Texterkennung auf dem Server nicht vorhanden, steht das ebenfalls dort; nichts ist blockiert. Mit <strong>Jetzt erkennen</strong> im Fenster der Rechnung lässt du die Erkennung nochmals laufen, z.B. nachdem die Texterkennung eingerichtet ist — sie füllt nur leere Felder. Auch eine erkannte Rechnung prüfst du, bevor du sie verbuchst. Mögliche Doppel (gleiche IBAN und Referenz, gleicher Kreditor und Rechnungs-Nr.) werden schon beim Einlesen gesperrt.</p>
<h4>Eingangsordner</h4>
<p>Rechnungen kommen auch ohne Hochladen herein: was im Eingangsordner auf dem Server liegt (<code>data/framework/creditor/intake</code>, z.B. von einem Skript auf deinem PC dorthin kopiert), liest der Job «Kreditoren-Eingang» jede Minute ein und erkennt es wie oben. Liegt die Rechnung sicher in den Dokumenten, wird die Datei im Ordner gelöscht. Eine Datei, die nicht eingelesen werden kann (kein PDF, leer, zu gross), kommt in den Unterordner <code>failed</code> und erscheint im Tab <strong>Nicht eingelesen</strong> mit dem Grund — dort «Erneut versuchen» (zurück in den Ordner) oder «Entfernen». Eine Datei, die schon eingelesen ist, wird einfach gelöscht. Den Ordner legt der Job selbst an (auch beim Öffnen des Tabs «Nicht eingelesen»).</p>
<h4>KI-Erkennung (wenn eingeschaltet)</h4>
<p>Kann der Text eine Rechnung nicht auflösen (Scan, kein QR-Zahlteil, unbekannter Kreditor), kann die KI sie lesen — nur wenn sie für diese Installation eingeschaltet ist (ausgeschaltet ist der Normalfall). Auch ihre Werte kommen nur in leere Felder, die Spalte zeigt «KI», und ein Konto rät sie nie: das kommt vom Kreditor oder von dir. Ist sie aus, fehlt der Schlüssel oder schlägt sie fehl, erfasst du die Rechnung von Hand.</p>
<p>Ist die KI-Erkennung der Kreditorenrechnungen eingeschaltet, wird das PDF einer Lieferantenrechnung, die das System nicht selbst lesen kann (Scan ohne Text, ausländische Rechnung ohne QR-Zahlteil, unbekanntes Konto), zur Auswertung an Anthropic PBC in den USA übermittelt. Übermittelt wird nur dieses PDF — keine Kontakte, keine Buchungen und keine anderen Rechnungen.</p>
<h4>Die vier Tabs</h4>
<ul>
    <li><strong>Im Journal</strong> — die noch nicht verbuchten Rechnungen. Oben steht, wie viele es sind, wie viele davon unbezahlt und wie viele bezahlt, und was sie zusammen ausmachen. Eine neue Rechnung ohne Datum steht zuoberst.</li>
    <li><strong>Gesperrt</strong> — Rechnungen, die ein <strong>mögliches Doppel</strong> einer anderen sind: gleicher Kreditor und gleiche Rechnungs-Nr., oder gleiche IBAN und gleiche Referenz (oft eine Mahnung zur selben Rechnung). Im Fenster entscheidest du: «Kein Doppel — übernehmen» oder «Verwerfen …».</li>
    <li><strong>Verbuchte Journale</strong> — jedes «Journal verbuchen» mit Datum, wer, wie viele Rechnungen und Total.</li>
    <li><strong>Nicht eingelesen</strong> — Dateien aus dem Eingangsordner, die nicht eingelesen werden konnten, mit dem Grund. Solange eine wartet, steht oben «n Dateien nicht eingelesen».</li>
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
<p>Der Rechnungsbetrag in CHF inklusive MWST. Lautet die Rechnung auf eine andere Währung, steht der Betrag der Rechnung klein darunter, z.B. «9.00 USD»; fehlt der CHF-Betrag noch, steht «—». Suche: der genaue Betrag, in CHF oder in der Währung der Rechnung.</p>
</section>
