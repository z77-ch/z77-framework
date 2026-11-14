<?php
/**
 * Help for the translation window (ADR-048) — UI text and route slug, new and edit; attached by
 * `TranslationController::edit()`, opened with «Hilfe» in the window's title bar. Sections per
 * field name: `key` (UI text), `canonical` (route slug), `value` (the per-language inputs
 * `value[<lang>]` — core.js strips the brackets). Static text, no context.
 */
?>
<h3>Übersetzung bearbeiten</h3>
<p>Einen festen Text der Webseite (Menüname, Knopf, kurzer Text) oder ein Wort der Adresse in den einzelnen Sprachen eintragen, ändern oder ergänzen.</p>
<p>Der Kopf sagt, was du bearbeitest: <strong>UI-Text</strong> (Schlüssel + Text pro Sprache) oder <strong>Routen-Slug</strong> (Adresswort + seine Form pro Sprache). <strong>Speichern</strong> gilt sofort auf der Webseite.</p>

<section data-help-field="key">
<h3>Schlüssel</h3>
<p>Der Name, unter dem die Webseite den Text abruft, mindestens zwei Teile mit Punkt (Buchstaben, Ziffern, Unterstrich), z.B. <code>nav.contact</code>. Die Menünamen der Webseite heissen <code>nav.</code> plus die Action ihres Menüeintrags (Webseiten › Navigation), z.B. <code>nav.home</code>, <code>nav.about</code>. Einen bestehenden Schlüssel umbenennen heisst: die Webseite findet den Text unter dem alten Namen nicht mehr — nur ändern, wenn du weisst, wo er gebraucht wird.</p>
</section>

<section data-help-field="canonical">
<h3>Kanonischer Slug</h3>
<p>Das Adresswort in der Standardsprache, so wie es im Pfad des Alias steht (z.B. <code>contact</code> aus <code>/contact</code>). Kleinbuchstaben, Ziffern und Bindestrich.</p>
</section>

<section data-help-field="value">
<h3>Wert pro Sprache</h3>
<p><strong>UI-Text:</strong> der Text in dieser Sprache. Die Standardsprache (Master / Fallback) ist der Ausgangstext; eine leere andere Sprache zeigt diesen.</p>
<p><strong>Routen-Slug:</strong> das Wort, das in dieser Sprache in der Adresse steht (z.B. <code>prestations</code> für <code>services</code> auf Französisch). Leer = die Adresse behält das Wort der Standardsprache. Kleinbuchstaben, Ziffern und Bindestrich; pro Sprache nur einmal und ohne ein anderes Adresswort zu verdecken.</p>
</section>
