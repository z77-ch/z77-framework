<?php
/**
 * Help for the window «Firmenlogo» (ADR-048; «Hilfe» in the window's title bar, F1 in a field) —
 * a general part, then one section per field (`data-help-field` = the field's `name`, the switch
 * by its `data-help-key`). The window itself carries labels only (backend-screen.md §5). Also the
 * KI-Assistent's knowledge (ADR-055): «Logo hochladen», «Logo auf Rechnung», «Logo in E-Mail»,
 * «Logo auf der Website», «Logo entfernen».
 */
?>
<h3>Firmenlogo hochladen</h3>
<p>Das Logo deiner Firma erscheint an vier Orten: auf den <strong>Belegen (PDF)</strong> — Rechnung, Offerte, Mahnung —, oben in jeder <strong>E-Mail</strong>, auf der <strong>Anmeldeseite</strong> des Backends und im Kopf und Fuss der <strong>Website</strong>. Lade ein Bild hoch (PNG, JPG oder GIF, höchstens 5 MB) und klicke «Speichern» — die passenden Grössen macht das System selbst: für den Druck in der hochgeladenen Auflösung (höchstens 2000 Pixel), für E-Mail und Anmeldung klein und scharf, für die Website in Kopfgrösse. Um Pixel und Auflösung musst du dich nicht kümmern. Ein PNG mit durchsichtigem Hintergrund bleibt durchsichtig.</p>
<p>Die Liste «Heute» zeigt, was jede Verwendung jetzt zeigt: das hochgeladene Logo, <strong>kein Logo</strong> oder <strong>eigenes Design</strong>. Auf der Anmeldeseite erscheint ohne eigenes Anmelde-Logo das Logo der E-Mails.</p>
<p>Die Logos liegen in der Ablage: Belege, E-Mail und Anmeldung im geschützten Bereich «Firmenlogo», das Website-Logo im öffentlichen Bereich «Website-Logo» — nur dieses eine Bild ist dort öffentlich abrufbar. Ein ersetztes oder entferntes Logo kommt in den Papierkorb der Ablage und lässt sich von dort wiederherstellen.</p>

<section data-help-field="one_for_all">
<h4>Ein Logo für alle</h4>
<p>Eingeschaltet (Standard): ein einziges Bild für alle Verwendungen. Ausgeschaltet: für jede Verwendung ein eigenes Feld — z.B. ein ausführliches Logo für die Belege und ein schlichtes für E-Mail und Website. Ein leer gelassenes Feld ändert an seiner Verwendung nichts.</p>
</section>
<section data-help-field="logo_all">
<h4>Logo</h4>
<p>Das Bild für alle Verwendungen: PNG, JPG oder GIF, höchstens 5 MB und 4000 × 4000 Pixel. SVG wird nicht angenommen — viele E-Mail-Programme zeigen es nicht an. Am besten ein PNG mit durchsichtigem Hintergrund, mindestens etwa 600 Pixel breit, damit es gedruckt scharf ist.</p>
</section>
<section data-help-field="logo_print">
<h4>Belege (PDF)</h4>
<p>Das Logo oben rechts auf Rechnungen, Offerten, Auftragsbestätigungen und Mahnungen. Hier lohnt sich eine grosse Datei: das PDF bekommt die hochgeladene Auflösung (höchstens 2000 Pixel an der längeren Seite).</p>
</section>
<section data-help-field="logo_email">
<h4>E-Mail</h4>
<p>Das Logo oben in jeder E-Mail (Rechnungsversand, Anmeldecode, …). Das System macht daraus eine kleine Datei, etwa 100 Pixel breit angezeigt — auch Outlook zeigt sie in dieser Grösse.</p>
</section>
<section data-help-field="logo_login">
<h4>Anmeldung</h4>
<p>Das Logo auf den Anmeldeseiten des Backends (Anmelden, Passwort vergessen, Code, Abmelden), höchstens 56 Pixel hoch. Ohne Logo steht dort der Name der Website.</p>
</section>
<section data-help-field="logo_web">
<h4>Website</h4>
<p>Das Logo im Kopf und im Fuss der Website, statt des Namens. Es liegt öffentlich in der Ablage, damit der Browser es zwischenspeichern kann. Wird es dort auf «geschützt» gestellt, zeigt die Website wieder den Namen.</p>
</section>
<section data-help-field="own_design">
<h4>Eigenes Design</h4>
<p>«Eigenes Design» heisst: dein Projekt hat die Anmeldeseite oder den Kopf der Website selbst gestaltet. Dort entscheidet diese Gestaltung, was erscheint — das hier hochgeladene Logo wird dort <strong>nicht</strong> verwendet, auch nicht mit «Ein Logo für alle»; es gilt nur für die übrigen Verwendungen. Das Fenster nimmt für diese Verwendungen kein Bild an, und der Schritt «Firmenlogo … hinterlegen» auf der Übersicht zählt sie nicht.</p>
<p>Soll das Logo trotzdem auf der Anmeldeseite oder der Website erscheinen, gibt es zwei Wege (Sache des Entwicklers):</p>
<ul>
<li><strong>Die eigene Gestaltung entfernen</strong> — die eigene Vorlage im Ordner <code>override/</code> des Projekts löschen (<code>partials/brandMark</code> für Anmeldung und Website, <code>partials/header</code> für die Website). Dann gilt wieder die mitgelieferte Gestaltung mit diesem Logo.</li>
<li><strong>Das Logo in der eigenen Vorlage zeigen</strong> — dort das Markenzeichen <code>partials/brandMark</code> darstellen, mit <code>logo</code> für die Anmeldung, mit <code>siteLogo</code> für die Website. Das Fenster zeigt danach weiterhin «eigenes Design», weil es nicht sehen kann, was die eigene Vorlage darstellt.</li>
</ul>
</section>

<h3>Entfernen und «Später / kein Logo»</h3>
<p><strong>Entfernen</strong> (in der Liste) nimmt das hochgeladene Logo einer Verwendung weg; die Datei kommt in den Papierkorb der Ablage. Die Verwendung zeigt danach kein Logo (die Anmeldeseite das Logo der E-Mails, falls eines da ist).</p>
<p><strong>Später / kein Logo</strong> blendet den Schritt «Firmenlogo … hinterlegen» auf der Übersicht aus, auch wenn ein Logo fehlt. Das Fenster bleibt unter Stammdaten › Firma › Mandant, Reiter «Briefkopf», jederzeit erreichbar; sobald du dort ein Logo speicherst, meldet sich der Schritt wieder, falls noch eine Verwendung ohne Logo ist.</p>
