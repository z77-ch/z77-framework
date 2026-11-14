<?php
/**
 * Help for the content edit window (ADR-048) — new content, a live copy, a variant. Attached by
 * `ContentController::edit()` (add + edit; not by `slotAction()`, the page editor's iframe),
 * opened with «Hilfe» in the window's title bar. General part first, then a section per field
 * (`data-help-field` = the field's `name`). The block fields carry no `name` (the editor posts
 * one `blocks` JSON), so the blocks are explained in the `blocks` section. Static text: the index
 * of the KI-Assistent (ADR-055) renders it with an empty context.
 */
?>
<h3>Inhalt bearbeiten</h3>
<p>Text einer Seite bearbeiten: Titel und Abschnitte eines Inhalts ändern, Block hinzufügen, verschieben oder entfernen, Bild auswählen, Link setzen, den Inhalt ein- oder ausschalten und speichern.</p>
<p>Der Kopf nennt die <strong>Bearbeitungssprache</strong> (z.B. DE) und bei einer Variante «Variante …». <strong>Speichern</strong> steht direkt unter dem Kopf. Nach einem Fehler steht dort «1 Fehler» (oder mehr) — ein Klick springt zum Feld; ein Fehler in den Blöcken steht rot über den Blöcken. <strong>Schliessen</strong> verwirft, was nicht gespeichert ist.</p>
<ul>
    <li><strong>Live-Fassung</strong> — Speichern gilt sofort auf der Webseite. Der bisherige Stand bleibt als <strong>Version</strong> erhalten («zuletzt gespeichert …» im Kopf); in der Liste über ⋮ › <strong>Wiederherstellen</strong> zurückholen.</li>
    <li><strong>Variante</strong> — Speichern ändert nur die Variante, Besucher sehen nichts davon. Live wird sie erst mit ⋮ › <strong>Satz veröffentlichen</strong> in der Liste.</li>
</ul>
<p>Hat jemand anderes den Inhalt gespeichert, seit du ihn geöffnet hast, speichert die Maske nicht und zeigt <strong>Neu laden</strong>: neu laden und die Änderung nochmals eingeben — so überschreibt niemand die Arbeit eines anderen.</p>

<section data-help-field="active">
<h3>Aktiv</h3>
<p>Ein ausgeschalteter Inhalt bleibt hier bearbeitbar, wird auf der Webseite aber nicht gezeigt; eine Seite, die ihn braucht, ist dann nicht erreichbar. Fehlt ein Inhalt in einer Sprache ganz, zeigt die Webseite den Inhalt der Standardsprache — ein ausgeschalteter Inhalt fällt nicht darauf zurück.</p>
</section>

<section data-help-field="slug">
<h3>Slug (Kurzname)</h3>
<p>Der Name, unter dem die Webseite den Inhalt lädt, z.B. «home» für die Startseite oder «footer» für die Fusszeile. Nur beim Anlegen einzugeben, danach fest. Ein Slug darf es pro Sprache nur einmal geben.</p>
</section>

<section data-help-field="language">
<h3>Sprache</h3>
<p>Folgt der Bearbeitungssprache der Liste und ist nicht änderbar. Für eine andere Sprache in der Liste die Sprache wechseln und den Inhalt dort mit demselben Slug anlegen.</p>
</section>

<section data-help-field="title">
<h3>Titel</h3>
<p>Der Name des Inhalts in der Liste — Pflicht, höchstens 120 Zeichen. Den Titel im Browser-Tab und bei Google legst du unter Webseiten › Metadaten fest.</p>
</section>

<section data-help-field="blocks">
<h3>Blöcke und Abschnitte</h3>
<p>Der Text der Seite besteht aus <strong>Blöcken</strong> (Titel, Text, Karten, Bild …), jeder als Karte mit seinen Feldern.</p>
<ul>
    <li><strong>Freier Aufbau</strong> — Typ wählen und <strong>+ Block hinzufügen</strong>; ↑ ↓ verschieben, × entfernt den Block. Die Reihenfolge hier ist die Reihenfolge auf der Seite.</li>
    <li><strong>Feste Struktur</strong> (Hinweis «Feste Struktur …») — eine gestaltete Seite: die Abschnitte sind vorgegeben und tragen ihren Namen; du füllst sie nur aus.</li>
    <li><strong>Listen</strong> (Absätze, Karten, Bilder) — <strong>+ Eintrag</strong> fügt einen hinzu, × entfernt ihn, ↑ ↓ ordnen Einträge mit mehreren Feldern. Fehlt «+» oder «×», ist die Höchst- bzw. Mindestzahl erreicht.</li>
    <li><strong>Formatierung</strong> — nur, was unter dem Feld bei «Erlaubt:» steht: <code>**fett**</code>, <code>*kursiv*</code>, ein Link <code>[Text](/seite)</code>. Ohne Hinweis ist das Feld reiner Text.</li>
    <li><strong>Bild</strong> — <strong>Auswählen …</strong> öffnet die Bilder aus dem Drive (Ablage «Inhalte»); wählbar sind nur öffentliche Bilder. <strong>Entfernen</strong> nimmt das Bild aus dem Block, im Drive bleibt es.</li>
    <li>Ein Block mit dem Zusatz «(nicht in der Struktur — bleibt erhalten)» wird unverändert aufbewahrt. Ein Block «(unbekannt)» hat einen Typ, den die Webseite nicht mehr kennt: Speichern meldet ihn als Fehler — mit × entfernen.</li>
</ul>
<p><strong>JSON-Vorschau</strong> zeigt die Daten so, wie sie gespeichert werden — nur zum Nachschauen.</p>
</section>
