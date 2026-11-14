<?php
/**
 * Help for the metadata list «Webseiten › Metadaten» (ADR-048, HELP-003) — attached by
 * `MetaDataController::listAction()` with `toolbar: true` (page request only). One row per
 * routable page of a public view area, the status of its SEO metadata in the editing language.
 * The edit window has its own help (`edit.help`). Static text, no context.
 */
?>
<h3>Metadaten für Google</h3>
<p>Seitentitel und Beschreibung für Google und den Browser-Tab festlegen: pro Seite der Webseite und pro Sprache, dazu Theme-Color und strukturierte Daten (JSON-LD). Zeigt, welchen Seiten die Metadaten noch fehlen.</p>
<p>Jede Zeile ist eine <strong>Seite</strong> der öffentlichen Webseite (aus dem Menü, Webseiten › Navigation), gruppiert nach Bereich. Rechts steht der Stand in der <strong>Bearbeitungssprache</strong>: «✓ vorhanden · Titel» oder blass «✗ fehlt».</p>
<ul>
    <li><strong>⋮</strong> — <strong>Metadaten anlegen</strong>, wo sie fehlen; sonst <strong>Bearbeiten</strong> und <strong>Löschen</strong>.</li>
    <li>Werkzeugleiste: die <strong>Bearbeitungssprache</strong> (bei mehreren Sprachen) und die Reiter <strong>Alle</strong> / je Bereich (bei mehreren öffentlichen Bereichen).</li>
</ul>
<p>Ohne Metadaten zeigt die Seite als Titel den Namen der Webseite und keine Beschreibung. Metadaten fallen nicht auf eine andere Sprache zurück: jede Sprache braucht ihre eigenen.</p>
<p>Alle Seiten mit derselben Adresse und einem Rest dahinter (z.B. Detailseiten) teilen sich die Metadaten ihres Menüeintrags. Noch nicht verfügbar: eigene Metadaten pro Detailseite — folgt.</p>
<p>Nur für Administratoren.</p>
