<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): how the backend is operated across all screens —
 * sign-in, menu and areas, the regions of a screen (action cell, toolbar, crumb), standard lists
 * (search, sort, tabs, pages), the row ⋮ menu, windows and dialogs, help and KI-Assistent, the
 * avatar menu (appearance, 2FA, sign-out, the admin's quick settings incl. «Cache leeren»).
 * Not attached to a screen: the help index finds every `*.help.tpl.php` under the module's
 * templates. Static text, no context. Facts: docs/01-handbook/backend-screen.md, backend.md,
 * login.md, listing.md, fetch.md, partials/shell/topbar.tpl.php.
 */
?>
<h3>Arbeiten im Backend</h3>
<p>Wie das Backend bedient wird: anmelden, Passwort vergessen, Bereich wechseln, in einer Liste suchen und sortieren, das Menü ⋮, Fenster speichern, Hilfe und KI-Assistent, Dark Mode, Cache leeren, abmelden.</p>

<h4>Anmelden und abmelden</h4>
<p>Anmelden unter <code>/login</code> mit Benutzername und Passwort. Ist der Zwei-Faktor-Schutz eingeschaltet, folgt eine Seite für den Code aus der App. Nach der Anmeldung öffnet die <strong>Übersicht</strong>, oder die Seite, die du vorher aufrufen wolltest.</p>
<p><strong>Passwort vergessen?</strong> auf der Anmeldeseite: Benutzername oder E-Mail eingeben, ein Link kommt per E-Mail (30 Minuten gültig) — aber nur, wenn im Benutzerkonto eine «E-Mail für Passwort-Reset» steht. Sonst setzt ein Admin unter Stammdaten › System › Benutzer ein neues Passwort. Nach fünf falschen Passwörtern für denselben Benutzernamen vom selben Anschluss ist die Anmeldung dort 15 Minuten gesperrt (die Grenze kann pro Installation anders eingestellt sein).</p>
<p><strong>Abmelden</strong>: oben rechts der runde Knopf mit deinen Initialen › <strong>Abmelden</strong>.</p>

<h4>Menü und Bereiche</h4>
<ul>
    <li><strong>Bereich wechseln</strong> — oben links der Name des Bereichs (z.B. «Webseiten», «Service»); das Raster-Symbol daneben zeigt alle Bereiche als Kacheln.</li>
    <li><strong>Seiten des Bereichs</strong> — die Spalte links. Auf dem Handy öffnet das Menü-Symbol ☰ sie.</li>
    <li><strong>Umgebung</strong> — oben rechts, z.B. «Backend» oder «Frontend»: wechselt zwischen Backend und Webseite.</li>
    <li><strong>Dashboard</strong> — links in der Zeile unter dem Werkzeugband: zurück zur Übersicht.</li>
</ul>
<p>Du siehst nur Bereiche, Seiten und Knöpfe, die deine Rolle öffnen darf (siehe «Benutzer, Rollen und Rechte»).</p>

<h4>Aufbau einer Seite: Aktionszelle, Werkzeugzeile, Pfad</h4>
<ul>
    <li><strong>Aktionszelle</strong> (oben links, farbig) — die häufigste Aktion der Seite, z.B. <strong>+ Kontakt</strong>, <strong>Speichern</strong> oder ein grünes <strong>Buchen</strong>. Mit einem Pfeil ▾ daneben: weitere Arten derselben Aktion. Auf dem Handy ein quadratisches Symbol rechts.</li>
    <li><strong>Werkzeugzeile</strong> — zuerst die <strong>Reiter</strong> (z.B. Sprache, Status oder Ansicht einer Liste), dann Werkzeuge; ganz rechts in einer Liste <strong>Hilfe</strong> bzw. <strong>KI-Assistent</strong>.</li>
    <li><strong>Pfad</strong> — darunter, wo du bist: Bereich › Seite.</li>
</ul>

<h4>Listen: suchen, sortieren, blättern</h4>
<ul>
    <li>Ein Klick auf einen <strong>Spaltentitel</strong> sortiert, ein zweiter dreht die Richtung.</li>
    <li>Die <strong>Lupe</strong> neben dem Titel öffnet die Suche in dieser Spalte; ein Klick auf eine Zelle öffnet die Suche ihrer Spalte. Ein Datum lässt sich als Tag, Monat (<code>11.2026</code>) oder Jahr suchen, ein Betrag als Zahl.</li>
    <li><strong>Zurücksetzen</strong> hebt Suche und Sortierung auf; der gewählte Reiter bleibt.</li>
    <li>Lange Listen zeigen 50 Zeilen pro Seite, darunter die Seitenzahlen.</li>
    <li>Das <strong>Symbol am Zeilenanfang</strong> öffnet den Eintrag im Fenster (Stift = bearbeitbar, Schloss = nur ansehen). Ein <strong>Schalter</strong> in der Spalte «Aktiv» schaltet den Eintrag sofort ein oder aus.</li>
</ul>

<h4>Das Menü ⋮ einer Zeile</h4>
<p>Die drei Punkte ⋮ öffnen die Aktionen dieses Eintrags als kleines Menü direkt daneben, z.B. Bearbeiten, Löschen …, Herunterladen. Esc oder ein Klick daneben schliesst es. Ein Eintrag mit «…» fragt zuerst nach; rote Einträge löschen oder sperren etwas.</p>

<h4>Fenster und Rückfragen</h4>
<p>Ein Eintrag öffnet sich in einem <strong>Fenster</strong> über der Seite. Seine Knöpfe stehen in einer Zeile direkt unter dem Titel: zuerst <strong>Speichern</strong> (bzw. die Hauptaktion), dann die Aktionen des Eintrags, rechts <strong>Schliessen</strong>. Oben rechts: Vollbild und ×. Fehlerhafte Eingaben sind am Feld markiert; «n Fehler» in der Knopfzeile springt zum ersten. Nach dem Speichern aktualisiert sich nur die betroffene Zeile, die Seite bleibt, wo sie war. Eine Rückfrage («wirklich löschen?») hat ihre Knöpfe unten.</p>

<h4>Hilfe und KI-Assistent</h4>
<ul>
    <li><strong>KI-Assistent</strong> (✦, oben rechts oder rechts in der Werkzeugzeile einer Liste) — beantwortet Fragen zur Bedienung aus den Hilfetexten aller Bildschirme, zuerst des aktuellen. Er sieht keine Daten: keine Kunden, keine Beträge, nur deine Frage und die Hilfetexte. <strong>Hilfetext anzeigen</strong> im Assistenten öffnet die Hilfe des Bildschirms.</li>
    <li><strong>Hilfe</strong> — wo kein Assistent eingerichtet ist, steht dort «Hilfe». Das Hilfefenster bleibt rechts offen, während du arbeitest; es lässt sich verschieben, vergrössern und schliessen.</li>
    <li><strong>F1</strong> in einem Eingabefeld öffnet die Hilfe direkt beim Abschnitt dieses Feldes. In einem Fenster steht «Hilfe» in der Titelzeile.</li>
</ul>
<p>Nicht jeder Bildschirm hat Hilfe — ohne Hilfetext gibt es keinen Knopf.</p>

<h4>Benutzermenü: Aussehen, Zwei-Faktor-Schutz, Abmelden</h4>
<p>Der runde Knopf mit deinen Initialen oben rechts:</p>
<ul>
    <li><strong>Aussehen</strong> — <strong>Dark Mode</strong>, die <strong>Farbpalette</strong> und die <strong>Schriftgrösse</strong> (100–140 %). Gilt für dein Konto, in jedem Browser.</li>
    <li><strong>Zwei-Faktor-Schutz</strong> — Anmeldung zusätzlich mit Code aus einer App einrichten oder entfernen.</li>
    <li><strong>Info</strong> — technische Angaben zur Seite (Modul, Controller, Vorlage) für Rückfragen beim Entwickler.</li>
    <li>Ganz unten der Stand der installierten Software.</li>
</ul>

<h4>Schnell-Einstellungen: Cache leeren, Entwickler-Modus, Suchmaschinen-Sperre</h4>
<p>Nur für Admins, im Benutzermenü:</p>
<ul>
    <li><strong>Cache leeren</strong> — wirft alle zwischengespeicherten Seiten und Daten weg; die nächsten Aufrufe bauen sie neu. Hilft, wenn nach einer Änderung oder einem Update noch der alte Stand erscheint. Es geht nichts verloren, die ersten Aufrufe sind nur etwas langsamer.</li>
    <li><strong>Entwickler-Modus</strong> — für Fehlersuche durch den Entwickler: zeigt Fehlerdetails und lädt unkomprimierte Dateien. Im Alltag ausgeschaltet lassen.</li>
    <li><strong>Suchmaschinen-Sperre</strong> — eingeschaltet bittet die ganze Webseite Suchmaschinen, sie nicht aufzunehmen (noindex); ein Hinweisband im Backend erinnert daran. Für Test-Installationen; auf der echten Webseite ausgeschaltet lassen.</li>
</ul>

<h4>Noch nicht verfügbar</h4>
<p>Die <strong>Glocke</strong> oben rechts (Benachrichtigungen) hat noch keine Funktion — offene Arbeit zeigt die Übersicht. Eine Suche über das ganze Backend gibt es noch nicht. Beides folgt.</p>
