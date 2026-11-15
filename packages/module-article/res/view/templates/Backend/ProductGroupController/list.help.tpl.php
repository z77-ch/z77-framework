<?php
/**
 * Help for Stammdaten › Auftragswesen › Warengruppen (ADR-048, HELP-003) — attached by
 * `listAction()` with `toolbar: true`. A quick help for the tree; the group window has its own
 * help with the fields (`edit.help`). Static text.
 */
?>
<h3>Warengruppen</h3>
<p>Der Baum der Warengruppen, in die jeder Artikel eingeordnet ist: Warengruppe anlegen, Untergruppe anlegen, Gruppe verschieben oder deaktivieren, Artikel einer Gruppe anzeigen.</p>
<ul>
    <li><strong>+ Warengruppe</strong> legt eine Gruppe auf der obersten Ebene an; das Ordner-Symbol rechts in einer Zeile legt darunter eine <strong>Untergruppe</strong> an.</li>
    <li><strong>⋮</strong> öffnet die Gruppe: Name, übergeordnete Gruppe (eine andere Wahl verschiebt sie samt Untergruppen und Artikeln), Vorgaben für MWST-Code und Ertragskonto neuer Artikel, <strong>Löschen …</strong> einer leeren Gruppe.</li>
    <li>Der Schalter <strong>Aktiv</strong> nimmt eine Gruppe aus der Auswahl für Artikel; Artikel, die darin hängen, bleiben.</li>
    <li><strong>Vorgabe …</strong> in einer Zeile nennt MWST-Code und Ertragskonto, die neue Artikel dieser Gruppe übernehmen.</li>
    <li><strong>N Artikel</strong> zählt die Artikel der Gruppe samt Untergruppen; ein Klick zeigt sie in der Artikelliste.</li>
</ul>
<p>Eine neue oder verschobene Gruppe steht am Ende ihrer Ebene; die Gruppen einer Ebene lassen sich noch nicht umsortieren.</p>
