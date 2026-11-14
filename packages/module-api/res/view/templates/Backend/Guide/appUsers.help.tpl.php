<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): the z77 App across its screens — the backend
 * «App-Benutzer» (who may use the app, which functions), devices, the sign-in by code on the
 * phone (`/app`, its own shell without a help trigger), the receipt capture and its offline
 * queue. Not attached to a screen: the help index finds every `*.help.tpl.php` under the
 * module's templates. Static text, no context. Facts: api.md (ADR-057/058).
 */
?>
<h3>z77 App: App-Benutzer und Geräte</h3>
<p>Die z77 App auf dem Handy: App-Zugang freischalten, mit Code anmelden, Beleg fotografieren und senden, verlorenes Handy sperren, warum die App «keine Funktion freigeschaltet» sagt oder nicht sendet.</p>

<h4>1. App-Benutzer anlegen (Backend)</h4>
<p>Stammdaten › System › App-Benutzer › <strong>+ App-Benutzer</strong> (nur ein Admin): die Person wählen (heute ein Backend-Benutzer), die <strong>App-E-Mail</strong> (dorthin geht der Anmelde-Code) und die <strong>Funktionen</strong> einschalten, z.B. «Belege erfassen». Jede Funktion verlangt eine Mindestrolle der Person; reicht sie nicht, ist der Schalter grau und nennt den Grund. Ins Backend kommt man mit der App nie.</p>

<h4>2. Auf dem Handy anmelden</h4>
<p>Auf dem Handy die Adresse der Website mit <strong>/app</strong> öffnen; die App lässt sich zum Startbildschirm hinzufügen (kein App-Store). Anmelden ohne Passwort: App-E-Mail eingeben, <strong>Code anfordern</strong>, den 6-stelligen Code aus der E-Mail eingeben (er gilt 15 Minuten). Danach bekommt die App-E-Mail die Nachricht «Neues Gerät angemeldet». Kommt kein Code: ist die Adresse genau die App-E-Mail und der App-Benutzer aktiv? Aus Sicherheitsgründen sagt die App das nicht selbst.</p>

<h4>3. Belege erfassen</h4>
<p>Die Startseite zeigt nur die Funktionen, die für diesen App-Benutzer eingeschaltet sind. Bei «Belege erfassen»: eine Vorlage wählen («Was hast du bezahlt?», oder ohne Vorlage), <strong>Foto aufnehmen</strong>, Betrag, Datum, Text und «Bezahlt mit» prüfen, <strong>Senden</strong>. Der Beleg liegt danach im Belegjournal im Backend und wird dort verbucht.</p>
<p>Ohne Netz speichert die App den Beleg auf dem Handy und sendet ihn, sobald wieder Netz da ist (beim Start, wenn das Handy wieder online ist, und alle 30 Sekunden). Ein Hinweis zeigt, wie viele Belege warten. Weist der Server einen Beleg ab, nennt die App den Grund, mit <strong>Bearbeiten</strong> oder <strong>Verwerfen</strong>. Abmelden («Auf diesem Gerät abmelden») geht erst, wenn nichts mehr wartet.</p>

<h4>4. Geräte und Sperren</h4>
<p>Jedes angemeldete Handy steht im Fenster des App-Benutzers unter <strong>Geräte</strong>. Ein verlorenes Handy: dort <strong>Sperren …</strong> — ab sofort wird alles abgewiesen, was es sendet. <strong>Deaktivieren …</strong> sperrt alle Geräte des App-Benutzers auf einmal; Erfasstes bleibt erhalten. Ein Gerät, das 180 Tage nichts gesendet hat, läuft ab; pro App-Benutzer sind höchstens 10 Geräte aktiv. Wird die Rolle der Person tiefer gesetzt oder die Person gelöscht, gilt das für die App ab der nächsten Anfrage.</p>

<h4>Noch nicht verfügbar</h4>
<p>Mitglieder des Mitgliederbereichs als App-Benutzer und eine Übersicht der Belege pro App-Benutzer gibt es noch nicht — folgt. Die App hat keine eigene Hilfe; diese Seite und die Hilfe im Backend unter App-Benutzer erklären sie.</p>
