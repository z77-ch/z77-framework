<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): how the website is maintained across the screens
 * of «Webseiten» (Inhalte, Metadaten, Übersetzungen, Navigation, Nav Alias) and the page editor on
 * the website itself («Seite bearbeiten», ADR-045 §4) — change a text, prepare and publish a
 * variant set, restore a version, menu, address, SEO title, who may do what. Not attached to a
 * screen: the help index finds every `*.help.tpl.php` under the module's templates. Static text,
 * no context. Facts: content.md, navigation.md, backendConfig `controllers.content`.
 */
?>
<h3>Webseite pflegen</h3>
<p>Die Webseite ändern: Text auf der Webseite ändern, Seite bearbeiten, Bild austauschen, Änderung vorbereiten und veröffentlichen, alten Text zurückholen, Menü und Menünamen ändern, Adresse einer Seite, Seitentitel für Google.</p>

<h4>Einen Text ändern</h4>
<ol>
    <li><strong>Direkt auf der Seite</strong> — auf der Webseite angemeldet die Werkzeugleiste am Rand öffnen, unter «Inhalt» den Schalter <strong>Seite bearbeiten</strong> einschalten. Jeder bearbeitbare Abschnitt bekommt einen Stift; ein Klick öffnet nur diesen Abschnitt zum Bearbeiten. Nach dem Speichern lädt die Seite neu.</li>
    <li><strong>Im Backend</strong> — Webseiten › Inhalte, beim Inhalt ⋮ › <strong>Bearbeiten</strong>. Hier stehen alle Abschnitte einer Seite, dazu Titel und der Schalter «Aktiv».</li>
</ol>
<p>Speichern gilt sofort für alle Besucher. Der Stand davor bleibt als <strong>Version</strong>: Webseiten › Inhalte, bei der Version ⋮ › <strong>Wiederherstellen</strong> holt ihn zurück.</p>

<h4>Änderungen vorbereiten: Variante, Vorschau, Veröffentlichen</h4>
<ol>
    <li>Webseiten › Inhalte, ⋮ › <strong>Variante anlegen</strong> — eine Kopie in einem <strong>Satz</strong> (z.B. «herbst»). Für jede betroffene Seite und Sprache wiederholen, immer im selben Satz.</li>
    <li>Die Varianten bearbeiten; Besucher sehen nichts davon.</li>
    <li>⋮ › <strong>Vorschau öffnen</strong> zeigt die ganze Webseite mit dem Satz. Auf der Seite selbst bearbeiten geht in der Vorschau auch — die Änderung landet im Satz.</li>
    <li>⋮ › <strong>Satz veröffentlichen</strong> macht alle Varianten des Satzes zugleich live; die bisherigen Texte bleiben als Versionen.</li>
</ol>

<h4>Bilder</h4>
<p>Ein Bildblock holt sein Bild aus dem <strong>Drive</strong> (Ablage «Inhalte»): im Block <strong>Auswählen …</strong>. Nur öffentliche Bilder sind wählbar und erscheinen auf der Webseite.</p>

<h4>Menü, Adresse, Google</h4>
<ul>
    <li><strong>Menünamen ändern oder übersetzen</strong> — Webseiten › Übersetzungen, Schlüssel <code>nav.</code> + Action (z.B. <code>nav.contact</code>).</li>
    <li><strong>Menü ordnen, Eintrag aus- oder einblenden</strong> — Webseiten › Navigation: ziehen, Schalter.</li>
    <li><strong>Adresse einer Seite</strong> — Webseiten › Navigation › Nav Alias. Die Wörter der Adresse in anderen Sprachen: Übersetzungen › Routen-Slugs.</li>
    <li><strong>Seitentitel und Beschreibung für Google</strong> — Webseiten › Metadaten, pro Seite und Sprache.</li>
</ul>

<h4>Sprachen</h4>
<p>Inhalte und Metadaten gibt es pro Sprache. Die Listen zeigen eine <strong>Bearbeitungssprache</strong> (Wahl in der Werkzeugleiste); Neues entsteht in ihr. Fehlt ein Inhalt in einer Sprache, zeigt die Webseite den der Standardsprache.</p>

<h4>Wer darf was</h4>
<p>Die Rolle <strong>Redaktor</strong> bearbeitet Inhalte im Backend und auf der Seite, legt Varianten an, veröffentlicht und stellt Versionen wieder her. Inhalte, Varianten und Versionen <strong>löschen</strong> sowie Metadaten, Übersetzungen, Navigation und Nav Alias sind der Rolle <strong>Admin</strong> (und höher) vorbehalten.</p>

<h4>Was es noch nicht gibt</h4>
<p>Eine ganz neue Seite mit eigener Adresse und eigenem Text braucht heute eine Programmänderung. Noch nicht verfügbar: Suche in den Inhalten, eigene Metadaten pro Detailseite — folgt.</p>
