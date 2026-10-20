<?php
/**
 * Help for the open-item list «Debitoren» (ADR-048, HELP-003) — attached by `listAction()` with
 * `toolbar: true`, opened with «Hilfe» at the right end of the toolbar. A quick help: what the
 * list shows, the views (`OPEN_ITEM_VIEWS`), the status, «Zahlung».
 */
?>
<h3>Debitoren — offene Posten</h3>
<p>Eine Zeile pro verbuchter Rechnung oder Mahngebühr («Geb.» bei der Nummer), sortiert nach Fälligkeit, die älteste zuerst. Hier wird nichts erfasst ausser Zahlungen; die Debitoren selbst (Adresse, Preise, Konditionen, Mahnsperre) stehen unter Stammdaten › Aufträge › Debitoren.</p>
<ul>
    <li><strong>Offen</strong> — alles, was noch nicht bezahlt ist.</li>
    <li><strong>Überfällig</strong> — offen und das Fälligkeitsdatum ist vorbei.</li>
    <li><strong>Alle</strong> — auch die bezahlten.</li>
</ul>
<p><strong>OP-Total</strong> und <strong>davon überfällig</strong> rechnen über alle offenen Belege, unabhängig von Reiter und Suche.</p>
<p><strong>Status:</strong> <em>offen</em>, <em>teilbezahlt</em> (etwas bezahlt, der Rest noch nicht fällig), <em>überfällig</em>, <em>bezahlt</em>. «M1», «M2» … daneben ist die höchste Mahnstufe des Belegs.</p>
<p>Das Schloss am Anfang einer Zeile öffnet den Beleg im Fenster. <strong>Zahlung</strong> öffnet das Zahlungsformular des Belegs im Fenster; nach dem Speichern zeigt die Liste den neuen offenen Betrag. Zahlungen aus dem Bankauszug kommen über <strong>Zahlungseingänge</strong>.</p>
<p><strong>Suchen und sortieren:</strong> ein Spaltentitel sortiert, die Lupe daneben sucht in der Spalte; ein Klick auf eine Zelle öffnet die Suche ihrer Spalte. <strong>Zurücksetzen</strong> hebt Suche und Sortierung auf, der Reiter bleibt.</p>
