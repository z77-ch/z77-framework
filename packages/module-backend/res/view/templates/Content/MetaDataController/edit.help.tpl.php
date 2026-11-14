<?php
/**
 * Help for the metadata window (ADR-048) — create and edit, attached by `MetaDataController::edit()`,
 * opened with «Hilfe» in the window's title bar. A section per field (`data-help-field` = the
 * field's `name`); where each value lands in the page head: frontend `partials/head/seo`,
 * `social`, `meta`, `structured-data`. Static text, no context.
 */
?>
<h3>Metadaten einer Seite</h3>
<p>Seitentitel für Google und den Browser-Tab, Beschreibung im Suchergebnis, Theme-Color und strukturierte Daten einer Seite in einer Sprache festlegen oder ändern.</p>
<p><strong>Seite</strong> und <strong>Sprache</strong> stehen fest: die Seite kommt aus der Liste, die Sprache ist die Bearbeitungssprache. Für eine andere Sprache in der Liste die Sprache wechseln. <strong>Speichern</strong> gilt sofort auf der Webseite.</p>

<section data-help-field="title">
<h3>Titel</h3>
<p>Der Seitentitel im Browser-Tab, als Überschrift im Google-Suchergebnis und beim Teilen eines Links (z.B. in Messengern). Pflicht, höchstens 120 Zeichen; gut sind etwa 50–60 Zeichen, das Wichtigste zuerst.</p>
</section>

<section data-help-field="description">
<h3>Beschreibung</h3>
<p>Der kurze Text unter dem Titel im Suchergebnis und beim Teilen eines Links. Freiwillig, höchstens 320 Zeichen; gut sind ein bis zwei Sätze (etwa 150 Zeichen). Ob Google ihn zeigt, entscheidet Google.</p>
</section>

<section data-help-field="theme_color">
<h3>Theme-Color</h3>
<p>Die Farbe, mit der manche Handy-Browser die Adressleiste einfärben, als Hex-Wert wie <code>#ffffff</code>. Freiwillig; leer = Weiss.</p>
</section>

<section data-help-field="application_ld">
<h3>JSON-LD (strukturierte Daten)</h3>
<p>Für Fortgeschrittene: Angaben für Suchmaschinen nach schema.org, z.B. Firma, Adresse, Öffnungszeiten. Ein JSON-Objekt (beginnt mit <code>{</code>); ungültiges JSON wird nicht gespeichert. Leer lassen, wenn du es nicht brauchst.</p>
</section>
