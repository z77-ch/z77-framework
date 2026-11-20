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
<p>Die Liste «Heute» zeigt, was jede Verwendung jetzt zeigt: das hochgeladene Logo, einen älteren <strong>Pfad</strong> aus dem Mandanten, <strong>kein Logo</strong> oder <strong>eigenes Design</strong>. «Eigenes Design» heisst: dein Projekt hat die Anmeldeseite oder den Kopf der Website selbst gestaltet — dort entscheidet diese Gestaltung, was erscheint, und das Fenster nimmt dafür kein Bild an. Wer dort trotzdem dieses Logo zeigen will, lässt in der eigenen Vorlage das Markenzeichen mit dem Website-Logo darstellen (Entwickler: <code>partials/brandMark</code> mit <code>siteLogo</code>).</p>
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

<h3>Entfernen und «Später / kein Logo»</h3>
<p><strong>Entfernen</strong> (in der Liste) nimmt das hochgeladene Logo einer Verwendung weg; die Datei kommt in den Papierkorb der Ablage. Steht im Mandanten noch ein älterer Pfad, gilt danach wieder dieser.</p>
<p><strong>Später / kein Logo</strong> blendet den Schritt «Firmenlogo … hinterlegen» auf der Übersicht aus, auch wenn ein Logo fehlt. Das Fenster bleibt unter Stammdaten › Firma › Mandant, Reiter «Briefkopf», jederzeit erreichbar; sobald du dort ein Logo speicherst, meldet sich der Schritt wieder, falls noch eine Verwendung ohne Logo ist.</p>
