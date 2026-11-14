<?php
/**
 * Help for the navigation list «Webseiten › Navigation» (ADR-048, HELP-003) — attached by
 * `NavigationController::listAction()` with `toolbar: true` (page request only). The tree grouped
 * view area → render-slot (ADR-022), drag & drop (`navigation/list.js`), the ⋮ menu, and what a
 * delete leaves behind (navigation.md NAV-DELETE-001). The edit window has its own help
 * (`edit.help`). Static text, no context.
 */
?>
<h3>Navigation (Menü)</h3>
<p>Das Menü der Webseite ändern: Menüeintrag hinzufügen, verschieben, ein- oder ausblenden, löschen, ein Untermenü bilden, eine Seite ins Hauptmenü oder in die Fusszeile setzen.</p>
<p>Alle Einträge als Baum, nach <strong>Bereich</strong> (Webseite, Backend …) und darin nach <strong>Slot</strong> — dem Ort des Menüs, z.B. Hauptnavigation, Fusszeile oder «Ohne Menü» (Seite mit Adresse, aber ohne Menüplatz). Die Einträge im Bereich Backend sind das Menü dieses Backends.</p>
<ul>
    <li><strong>+ Eintrag</strong> legt einen Eintrag an; das <strong>+</strong> im Kopf eines Slots legt ihn direkt dort an.</li>
    <li><strong>Schalter</strong>: aus = der Eintrag samt Untereinträgen erscheint nicht im Menü der Webseite; die Adresse bleibt erreichbar.</li>
    <li><strong>⋮</strong> — <strong>Geschwister hinzufügen</strong> (neuer Eintrag auf derselben Ebene), <strong>Bearbeiten</strong>, <strong>Löschen</strong>.</li>
    <li><strong>Verschieben</strong>: die Zeile mit der Maus ziehen — oberes Drittel einer Zeile = davor, unteres = danach, Mitte = als Untereintrag. Nur innerhalb desselben Slots, nicht unter einen Verweis.</li>
    <li>Werkzeugleiste: Reiter je Bereich, <strong>Filtern …</strong> blendet Zeilen ohne den Text aus, <strong>Drucken</strong>.</li>
</ul>
<p>Der Menüname auf der Webseite kommt aus Webseiten › Übersetzungen (Schlüssel <code>nav.</code> + Action), die Adresse aus Navigation › Nav Alias.</p>
<p><strong>Löschen</strong> entfernt nur diesen Eintrag: zuerst seine Untereinträge verschieben oder löschen, sonst verschwinden sie aus der Liste. Seine Aliase und Metadaten bleiben und sind dort zu löschen.</p>
<p><strong>Nicht zugeordnet</strong> (falls vorhanden): Einträge ohne gültigen Slot — bearbeiten und einen Slot wählen, oder löschen.</p>
<p>Eine neue Seite mit eigenem Text braucht heute eine Programmänderung; ein Menüeintrag allein macht noch keine Seite. Nur für Administratoren.</p>
