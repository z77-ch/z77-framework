<?php
/**
 * Help for the URL alias window (ADR-048) — new and edit, attached by
 * `NavigationAliasController::edit()`, opened with «Hilfe» in the window's title bar. A section
 * per field (`data-help-field` = the field's `name`); the rules are `NavigationAliasValidator`'s
 * (navigation.md «NavigationAlias entity»). Static text, no context.
 */
?>
<h3>Adresse (Alias)</h3>
<p>Die Adresse einer Seite festlegen: welcher Pfad (z.B. <code>/kontakt</code>) zu welchem Menüeintrag führt, ob er die Hauptadresse ist und ob er Detailseiten mit einem Rest dahinter annimmt.</p>
<p><strong>Speichern</strong> gilt sofort. Eine geänderte Hauptadresse ändert alle Links der Webseite auf diese Seite; wer die alte Adresse gespeichert hat, landet auf «nicht gefunden» — die alte Adresse bei Bedarf als weiteren Alias ohne «Canonical» behalten.</p>

<section data-help-field="active">
<h3>Aktiv</h3>
<p>Aus = der Pfad ist nicht erreichbar und wird nicht für Links verwendet.</p>
</section>

<section data-help-field="navigation_id">
<h3>Navigations-Ziel</h3>
<p>Der Menüeintrag, dessen Seite unter diesem Pfad erscheint. Angeboten werden nur Einträge mit eigener Seite (keine Öffner, keine Verweise).</p>
</section>

<section data-help-field="path">
<h3>Pfad</h3>
<p>Die Adresse ohne Domain, mit <code>/</code> beginnend, z.B. <code>/kontakt</code> oder <code>/schweiz/stadt</code>, in der Standardsprache. Jeder Pfad darf nur einmal vorkommen und nicht mit einem Modulnamen beginnen (<code>/frontend/…</code>, <code>/backend/…</code> sind technische Adressen).</p>
</section>

<section data-help-field="is_canonical">
<h3>Canonical</h3>
<p>Die Hauptadresse der Seite: Links auf der Webseite und die Angabe für Suchmaschinen verwenden sie. Pro Menüeintrag nur ein Alias mit Canonical; weitere Aliase zeigen dieselbe Seite unter einer zweiten Adresse.</p>
</section>

<section data-help-field="accepts_slugs">
<h3>Nimmt einen Rest an</h3>
<p>Ein: auch <code>/pfad/etwas</code> öffnet die Seite, «etwas» geht als Angabe an sie (für Detailseiten, z.B. ein Projekt). Aus: nur der genaue Pfad gilt, alles dahinter ist «nicht gefunden». Nur einschalten, wenn die Seite dafür gebaut ist. Alle Aliase eines Menüeintrags müssen gleich eingestellt sein.</p>
</section>
