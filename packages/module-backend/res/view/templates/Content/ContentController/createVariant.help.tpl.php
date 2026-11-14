<?php
/**
 * Help for «Variante anlegen» (ADR-048, ADR-044 addendum «Variants») — attached by
 * `ContentController::createVariantAction()` (GET and a failed POST), opened with «Hilfe» in the
 * window's title bar. What a variant and a set are, a section per field (`set`, `name`).
 * Static text, no context.
 */
?>
<h3>Variante anlegen</h3>
<p>Textänderungen vorbereiten, ohne dass Besucher sie sehen: eine Kopie (Variante) eines Inhalts anlegen, in Ruhe ändern, mit der Vorschau prüfen und später gemeinsam veröffentlichen.</p>
<p><strong>Anlegen</strong> kopiert die Live-Fassung in einen <strong>Satz</strong>. Ein Satz fasst Varianten zusammen, die zusammengehören — z.B. die Texte mehrerer Seiten für eine Aktion, auch in mehreren Sprachen. Danach in der Liste:</p>
<ul>
    <li>⋮ › <strong>Bearbeiten</strong> ändert die Variante; die Live-Fassung bleibt, wie sie ist.</li>
    <li>⋮ › <strong>Vorschau öffnen</strong> zeigt die Webseite mit allen Varianten des Satzes (oben «Vorschau «…»» mit <strong>Live ansehen</strong>). Den Vorschau-Link kannst du weitergeben.</li>
    <li>⋮ › <strong>Satz veröffentlichen</strong> macht alle Varianten des Satzes auf einmal live; die bisherigen Fassungen bleiben als Versionen.</li>
</ul>
<p>Ändert jemand die Live-Fassung, nachdem die Variante angelegt ist, übernimmt die Variante das nicht — beim Veröffentlichen gilt der Text der Variante.</p>

<section data-help-field="set">
<h3>Satz</h3>
<p><strong>Neuer Satz</strong> beginnt einen Satz mit dem Namen darunter. Ein bestehender Satz in der Auswahl nimmt diese Variante dazu; angeboten werden nur Sätze, die diesen Inhalt in dieser Sprache noch nicht enthalten.</p>
</section>

<section data-help-field="name">
<h3>Name des neuen Satzes</h3>
<p>Nur für «Neuer Satz»: ein kurzes Wort, z.B. «herbst» — Kleinbuchstaben, Ziffern und Bindestrich; leer gibt «satz». Eine zufällige Endung wird angehängt (herbst-a7f3k2), damit niemand den Vorschau-Link erraten kann.</p>
</section>
