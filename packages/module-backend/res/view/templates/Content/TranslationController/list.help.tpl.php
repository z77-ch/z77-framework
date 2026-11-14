<?php
/**
 * Help for the translation list «Webseiten › Übersetzungen» (ADR-048, HELP-003) — attached by
 * `TranslationController::listAction()` with `toolbar: true` (page request only). The two i18n
 * catalog families (`TranslationCatalog`, translation.md): UI texts and route slugs. The edit
 * window has its own help (`edit.help`). Static text, no context.
 */
?>
<h3>Übersetzungen</h3>
<p>Feste Texte und Adressen der Webseite übersetzen: Menünamen, Knöpfe und kurze Texte in jeder Sprache ändern, Übersetzung ergänzen, und die Wörter in den Adressen pro Sprache festlegen (z.B. /fr/contact statt /fr/kontakt).</p>
<p>Der Text einer Seite selbst steht nicht hier, sondern unter Webseiten › Inhalte.</p>
<h4>UI-Texte</h4>
<p>Ein Text hat einen <strong>Schlüssel</strong> (z.B. <code>nav.home</code> für den Menünamen der Startseite) und einen Wert pro Sprache, nebeneinander in der Zeile. Die Standardsprache ist der Ausgangstext; fehlt eine Übersetzung («fehlt»), zeigt die Webseite den Text der Standardsprache.</p>
<h4>Routen-Slugs</h4>
<p>Die Wörter der Adresse in den anderen Sprachen: links das Wort der Standardsprache, rechts seine Form je Sprache. «nicht lokalisiert» heisst: die Adresse behält das Wort der Standardsprache. Pro Sprache darf eine Form nur einmal vorkommen und kein anderes Adresswort verdecken.</p>
<ul>
    <li><strong>+ Texteintrag</strong> legt einen UI-Text an; der Pfeil daneben bietet auch den <strong>Slug-Eintrag</strong>.</li>
    <li><strong>⋮</strong> — <strong>Text bearbeiten</strong> / <strong>Slug bearbeiten</strong> und das Löschen. Ein gelöschter UI-Text erscheint danach als Schlüsselname auf der Webseite; ein gelöschter Slug macht die Adresse wieder zum Wort der Standardsprache.</li>
</ul>
<p>Änderungen gelten sofort auf der Webseite. Nur für Administratoren.</p>
