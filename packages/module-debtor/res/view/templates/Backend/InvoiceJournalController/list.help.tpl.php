<?php
/**
 * Help for the Faktura-Journal (ADR-048, HELP-003: «Hilfe» at the right end of the toolbar; in the
 * window «Journal verbuchen» its title bar — the window has no field of its own) — a general part,
 * then one section per search field of the list (`data-help-field` = the field's key): F1 in a
 * search field jumps to its section.
 */
?>
<h3>Faktura-Journal</h3>
<p>Das <strong>Faktura-Journal</strong> sammelt die <strong>fakturierten</strong> Rechnungen und Gutschriften: sie haben ihre Nummer, ihr PDF ist unveränderlich abgelegt und der Kunde hat sie bekommen — in der Buchhaltung stehen sie aber <strong>noch nicht</strong>. Verbucht werden sie erst hier, gesammelt, nachdem du sie geprüft hast.</p>
<ul>
    <li><strong>Im Journal</strong> — die fakturierten, noch nicht verbuchten Dokumente, nach Nummer. Oben steht, wie viele es sind und was sie zusammen ausmachen; unten die Summen je MWST-Code und das Total (Gutschriften zählen negativ). Das Schloss am Zeilenanfang öffnet das Dokument im Fenster.</li>
    <li><strong>Verbuchte Journale</strong> — jedes «Journal verbuchen» mit Datum, wer, wie viele Dokumente, Nummern und Total; das Drucksymbol öffnet seine Journalliste.</li>
</ul>
<p><strong>Journal drucken</strong> öffnet die Journalliste als PDF: alle Dokumente mit Netto, MWST, Total und ID, die Summen je MWST-Code und die Buchungen, die das Verbuchen erzeugt (summiert je Konto).</p>
<p><strong>Journal verbuchen …</strong> öffnet ein Fenster: kurz, was verbucht wird (Anzahl, Total, MWST je Code), und <strong>«Jetzt buchen»</strong> — erst das verbucht, immer das <strong>ganze</strong> Journal, alles oder nichts; danach schliesst sich das Fenster und die Liste lädt neu. Kann das Journal nicht verbucht werden, zeigt das Fenster <strong>nur die Fehler</strong>, jedes Dokument mit einem Link, der es öffnet. Kam seit dem Öffnen ein Dokument dazu oder wurde eines geändert, wird nichts verbucht — das Fenster zeigt den neuen Stand. Gebucht wird jedes Dokument mit seinem Rechnungsdatum. Danach ist es <strong>verbucht</strong> (unveränderlich, Korrektur = Gutschrift), und Zahlungen lassen sich zuordnen. Vorher nimmt eine fakturierte Rechnung keine Zahlung an: ein Zahlungseingang dafür wartet in den Zahlungseingängen mit «Rechnung noch nicht verbucht».</p>
<p><strong>Gesperrt</strong> ist das Verbuchen, solange ein Dokument mit <strong>«Neu fakturieren nötig»</strong> markiert ist — seine Quelle (der Auftrag) passt nicht mehr dazu; erst neu fakturieren, dann verbuchen. Der Jahresabschluss bleibt gesperrt, solange Rechnungen des Jahres hier stehen.</p>
<section data-help-field="f_nr">
<h3>Nr.</h3>
<p>Die Belegnummer (JJMMnnnn), beim Fakturieren vergeben. «GS» markiert eine Gutschrift. Suche: die ganze Nummer.</p>
</section>
<section data-help-field="f_date">
<h3>Datum</h3>
<p>Das Rechnungsdatum — an diesem Datum wird das Dokument verbucht. Suche: Tag (<code>15.11.2026</code>), Monat (<code>11.2026</code>) oder Jahr.</p>
</section>
<section data-help-field="f_name">
<h3>Kunde</h3>
<p>Die Rechnungsadresse, wie sie fakturiert wurde. Suche: ein Teil des Namens.</p>
</section>
<section data-help-field="f_amount">
<h3>Total</h3>
<p>Der Rechnungsbetrag inkl. MWST, Gutschriften negativ. Suche: der genaue Betrag. Die <strong>ID</strong> daneben ist die eindeutige Kennung dieser Fassung des Dokuments — sie steht klein neben der Nummer auf dem PDF.</p>
</section>
