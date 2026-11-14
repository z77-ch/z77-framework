<?php
/**
 * Help for the content list «Webseiten › Inhalte» (ADR-048, HELP-003) — attached by
 * `ContentController::listAction()` with `toolbar: true` (page request only), opened with «Hilfe»
 * at the right end of the toolbar. What the list is for, live / variant / version (ADR-044
 * addendum, ADR-045), the row switch and the ⋮ menu. The edit window has its own help
 * (`edit.help`), «Variante anlegen» too (`createVariant.help`); the whole workflow is the
 * unattached base knowledge `Backend/Guide/website.help`. Static text, no context.
 */
?>
<h3>Inhalte der Webseite</h3>
<p>Hier änderst du die Texte der Webseite: Seite bearbeiten, Text oder Bild ändern, Inhalt ein- oder ausschalten, eine Variante mit Vorschau vorbereiten, Satz veröffentlichen, eine frühere Version wiederherstellen.</p>
<p>Jede Zeile ist ein <strong>Inhalt</strong> (Titel und Blöcke, z.B. die Startseite «home» oder die Fusszeile «footer») in der <strong>Bearbeitungssprache</strong>. Bei mehreren Sprachen wählst du sie in der Werkzeugleiste (DE, EN …); neue Inhalte entstehen in dieser Sprache. Unter einem Inhalt stehen eingerückt seine Varianten und Versionen.</p>
<ul>
    <li><strong>+ Inhalt</strong> legt einen neuen Inhalt an. Auf der Webseite erscheint er erst, wenn eine Seite ihn unter seinem Slug lädt — das ist heute eine Programmänderung.</li>
    <li><strong>Schalter</strong> am Zeilenanfang: aktiv oder aus. Ein ausgeschalteter Inhalt wird auf der Webseite nicht gezeigt; eine Seite, die ihn braucht, ist dann nicht erreichbar.</li>
    <li><strong>⋮</strong> öffnet die Aktionen der Zeile: <strong>Bearbeiten</strong>, <strong>Variante anlegen</strong>; bei einer Variante <strong>Vorschau öffnen</strong> und <strong>Satz veröffentlichen</strong>; bei einer Version <strong>Vorschau öffnen</strong> und <strong>Wiederherstellen</strong>. <strong>Löschen</strong> sehen nur Administratoren.</li>
</ul>

<h4>Live, Version, Variante</h4>
<p><strong>Live</strong> ist, was Besucher sehen; die Zeile nennt, wann und von wem zuletzt gespeichert. Jedes Speichern und jedes Umschalten sichert den bisherigen Stand als <strong>Version</strong> («Version 22.09.2026 15:30 · Name»). Eine Version lässt sich nicht bearbeiten: <strong>Wiederherstellen</strong> macht sie wieder live, der Stand davor wird selbst zur Version.</p>
<p>Eine <strong>Variante</strong> ist eine Kopie, die du änderst, ohne dass Besucher etwas sehen. Varianten mit demselben Namen bilden einen <strong>Satz</strong> (z.B. alle Texte für die Herbstaktion). <strong>Vorschau öffnen</strong> zeigt die Webseite mit dem Satz; <strong>Satz veröffentlichen</strong> macht alle Inhalte des Satzes in allen Sprachen auf einmal live — die bisherigen Fassungen bleiben als Versionen.</p>
<p>Einen Abschnitt direkt auf der Seite ändern: auf der Webseite in der Werkzeugleiste am Rand den Schalter <strong>Seite bearbeiten</strong> einschalten und den Stift beim Abschnitt anklicken.</p>
<p>Noch nicht verfügbar: Suche in den Inhalten und eine Markierung, welcher Inhalt zu welcher Seite gehört — folgt.</p>
