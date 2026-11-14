<?php
/**
 * Help for Stammdaten › Auftragswesen › Artikel (ADR-048, HELP-003) — attached by `listAction()`
 * with `toolbar: true`, «Hilfe» at the right end of the toolbar beside the product-group filter.
 * A quick help: the list, the filter, the columns; the article window has its own help
 * (`edit.help`). Static text.
 */
?>
<h3>Artikel</h3>
<p>Alle Artikel mit Nummer, Bezeichnung, Warengruppe, Einheit, heutigem Preis, MWST-Code und Ertragskonto: Artikel suchen, nach Warengruppe filtern, Artikel anlegen, öffnen oder deaktivieren.</p>
<ul>
    <li><strong>+ Artikel</strong> öffnet einen neuen Artikel. Ist die Liste nach einer Warengruppe gefiltert, beginnt er in dieser Gruppe, mit deren Vorgaben für MWST-Code und Ertragskonto.</li>
    <li><strong>Warengruppe</strong> (links in der Werkzeugzeile) filtert die Liste nach einer Gruppe <strong>samt Untergruppen</strong>; das Suchfeld darin sucht auf allen Ebenen, «›» öffnet die Untergruppen, «×» hebt den Filter auf. Die Gruppen selbst: Stammdaten › Auftragswesen › Warengruppen.</li>
    <li>Das Stift-Symbol am Zeilenanfang öffnet den Artikel. Der Schalter <strong>Aktiv</strong> deaktiviert ihn: er wird für neue Positionen nicht mehr angeboten, bestehende behalten ihn.</li>
    <li><strong>Preis</strong> ist der Preis, der heute gilt («brutto», wenn er die MWST enthält; «ab …», wenn er erst später beginnt). Frühere und spätere Preise zeigt der Preisverlauf im Artikel.</li>
    <li><strong>Bestand</strong> sagt nur, ob der Artikel Bestand führt (ja / nein). <strong>Lager: noch nicht verfügbar — folgt.</strong> Einen Lagerbestand gibt es noch nicht.</li>
</ul>
<p><strong>Suchen und sortieren:</strong> Nr. und Bezeichnung suchen einen Teil und sortieren, MWST und Konto suchen genau; ein Klick auf eine Zelle öffnet die Suche ihrer Spalte.</p>
