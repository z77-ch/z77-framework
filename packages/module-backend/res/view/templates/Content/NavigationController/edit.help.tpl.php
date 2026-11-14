<?php
/**
 * Help for the navigation entry window (ADR-048) — new entry, new child, edit; attached by
 * `NavigationController::edit()`, opened with «Hilfe» in the window's title bar. A section per
 * field (`data-help-field` = the field's `name`): the 4-tuple, `ref`, `param`, `slot`
 * (navigation.md, ADR-015, ADR-022). Static text, no context.
 */
?>
<h3>Menüeintrag</h3>
<p>Einen Menüeintrag anlegen oder ändern: Name, wohin er führt (eine Seite oder ein Verweis auf einen anderen Eintrag), ob er im Menü erscheint und in welchem Menü (Slot) er steht.</p>
<p>Ein Eintrag ist eines von drei Dingen: eine <strong>Seite</strong> (alle vier Routing-Felder gesetzt), ein <strong>Öffner</strong> für ein Untermenü (Routing leer, mit Untereinträgen) oder ein <strong>Verweis</strong> auf einen anderen Eintrag. Die öffentliche Adresse steht nicht hier, sondern unter Navigation › Nav Alias. Reihenfolge und Ebene änderst du in der Liste durch Ziehen.</p>
<p>Hat jemand den Eintrag inzwischen gespeichert, speichert die Maske nicht und zeigt <strong>Neu laden</strong>.</p>

<section data-help-field="active">
<h3>Aktiv</h3>
<p>Aus = der Eintrag und seine Untereinträge erscheinen nicht im Menü der Webseite. Die Adresse bleibt erreichbar — wer sie kennt, kommt weiterhin auf die Seite.</p>
</section>

<section data-help-field="name">
<h3>Name</h3>
<p>Der Name im Menü des Backends und in dieser Liste. Im Menü der Webseite steht der übersetzte Text <code>nav.</code> + Action (Webseiten › Übersetzungen).</p>
</section>

<section data-help-field="ref">
<h3>Verweis</h3>
<p>Zeigt auf einen bestehenden Eintrag, statt selbst eine Seite zu sein — dieselbe Seite erscheint so an einem zweiten Ort im Menü. Mit Verweis bleiben die Routing-Felder leer und werden ausgeblendet. Ein Verweis hat keine Untereinträge und zeigt nicht auf einen anderen Verweis.</p>
</section>

<section data-help-field="module">
<h3>Routing: Modul, Group, Controller, Action</h3>
<p>Die technische Adresse der Seite, z.B. <code>frontend</code> / <code>main</code> / <code>index</code> / <code>contact</code>. Für eine Seite alle vier setzen, für einen Öffner alle vier leer lassen. Die Werte nennt die Programmierung; ein falscher Wert führt auf «nicht gefunden».</p>
</section>
<section data-help-field="group"><p><strong>Group</strong> — Teil der technischen Adresse, siehe «Routing» oben.</p></section>
<section data-help-field="controller"><p><strong>Controller</strong> — Teil der technischen Adresse, siehe «Routing» oben.</p></section>
<section data-help-field="action"><p><strong>Action</strong> — Teil der technischen Adresse, siehe «Routing» oben. Bestimmt auch den Schlüssel des Menünamens auf der Webseite (<code>nav.</code> + Action).</p></section>

<section data-help-field="param">
<h3>UI-Parameter</h3>
<p>Selten gebraucht: wird an den Link gehängt und öffnet die Zielseite in einer bestimmten Ansicht, z.B. <code>key=front</code> öffnet den Drive auf der Ablage «front»; <code>key=</code> setzt auf die volle Ansicht zurück. Ändert nicht, welche Seite geöffnet wird.</p>
</section>

<section data-help-field="slot">
<h3>Slot</h3>
<p>In welchem Menü ein Eintrag der obersten Ebene steht, z.B. Hauptnavigation oder Fusszeile; «Ohne Menü» für eine Seite mit Adresse, aber ohne Menüplatz. Ein Untereintrag hat keinen Slot («— keiner»). Neue Untereinträge und Geschwister übernehmen ihn automatisch; die Auswahl erscheint dann nicht.</p>
</section>
