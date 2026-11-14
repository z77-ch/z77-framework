<?php
/**
 * Help for the URL alias list «Webseiten › Navigation › Nav Alias» (ADR-048, HELP-003) — attached
 * by `NavigationAliasController::listAction()` with `toolbar: true` (page request only). What an
 * alias is (ADR-015), the row cells, canonical and «/…». The edit window has its own help
 * (`edit.help`). Static text, no context.
 */
?>
<h3>Adressen der Seiten (Nav Alias)</h3>
<p>Die Adresse (URL) einer Seite festlegen oder ändern, eine zweite Adresse für dieselbe Seite einrichten (z.B. eine alte Adresse weiter erreichbar halten), eine Adresse ausschalten oder löschen.</p>
<p>Ein <strong>Alias</strong> verbindet einen Pfad wie <code>/kontakt</code> mit einem Menüeintrag aus Webseiten › Navigation. Jede Zeile: der Pfad, dahinter «→ Eintrag (technische Adresse)», rechts die Marken:</p>
<ul>
    <li><strong>canonical</strong> — die öffentliche Hauptadresse der Seite: Links auf der Webseite und die Angabe für Suchmaschinen verwenden sie. Pro Menüeintrag höchstens eine.</li>
    <li><strong>/…</strong> — der Alias nimmt einen Rest an: <code>/referenzen/projekt-a</code> öffnet die Seite von <code>/referenzen</code> mit «projekt-a» (für Detailseiten). Ohne diese Marke gilt nur der genaue Pfad.</li>
    <li>«— fehlt» statt eines Eintrags: der Menüeintrag wurde gelöscht; den Alias löschen oder einem anderen Eintrag zuordnen.</li>
</ul>
<ul>
    <li><strong>+ Alias</strong> legt einen Alias an.</li>
    <li><strong>Schalter</strong>: aus = der Pfad ist nicht mehr erreichbar.</li>
    <li><strong>⋮</strong> — <strong>Bearbeiten</strong>, <strong>Löschen</strong> (der Pfad ist danach nicht mehr erreichbar).</li>
</ul>
<p>Eine weitere Adresse ohne «canonical» zeigt dieselbe Seite; eine Weiterleitung (301) auf die Hauptadresse gibt es nicht. Der Pfad gilt in der Standardsprache — in anderen Sprachen übersetzt Webseiten › Übersetzungen › Routen-Slugs die Wörter. Nur für Administratoren.</p>
