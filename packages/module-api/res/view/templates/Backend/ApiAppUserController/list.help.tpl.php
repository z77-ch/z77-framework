<?php
/**
 * Help of Stammdaten › System › App-Benutzer (ADR-048, HELP-003: attached with `toolbar: true`) —
 * what the list is for, the tabs, the main actions. Also prompt material of the KI-Assistent (ADR-055).
 */
?>
<h3>App-Benutzer</h3>
<p>Wer die <strong>z77 App</strong> auf dem Handy benutzen darf: jemandem den App-Zugang geben (z.B. Belege mit dem Handy fotografieren und senden), Funktionen ein- oder ausschalten, ein verlorenes Handy sperren, einen App-Benutzer deaktivieren.</p>
<p>Ein App-Benutzer ist mit genau einer Person verknüpft — heute einem Backend-Benutzer, später auch einem Mitglied — und darf nur die Funktionen, die hier bei ihm eingeschaltet sind, zum Beispiel Belege erfassen. Ins Backend kommt ein App-Benutzer nie.</p>
<p>Angemeldet wird ohne Passwort: das Handy öffnet <strong>/app</strong>, gibt die App-E-Mail ein und bekommt einen Code per E-Mail. Jedes so angemeldete Gerät erscheint im Fenster des App-Benutzers.</p>
<p>App-Benutzer verwaltet nur ein <strong>Admin</strong>. Ist die verknüpfte Person in einer höheren Rolle als du, siehst du den App-Benutzer, kannst ihn aber nicht ändern.</p>
<h3>Reiter: aktive und alle App-Benutzer</h3>
<p><strong>Aktiv</strong> zeigt die App-Benutzer, die sich anmelden können. <strong>Alle</strong> zeigt auch die deaktivierten: sie bleiben sichtbar, damit nachvollziehbar bleibt, wer was erfasst hat.</p>
<h3>App-Benutzer anlegen, öffnen, deaktivieren</h3>
<ul>
    <li><strong>«+ App-Benutzer»</strong> legt einen neuen an: zuerst die Person wählen, dann die App-E-Mail und die Funktionen.</li>
    <li>Das <strong>Symbol</strong> am Anfang einer Zeile öffnet den App-Benutzer: Telefon = aktiv, Auge = nur ansehen (die Person steht über deiner Rolle), Schloss = deaktiviert.</li>
    <li><strong>⋮</strong> öffnet «Öffnen» und «Deaktivieren …» bzw. «Aktivieren …» direkt an der Zeile.</li>
</ul>
<p><strong>Geräte</strong> zählt die Geräte, die sich heute anmelden können — gesperrte und abgelaufene (180 Tage nicht benutzt) nicht. <strong>Zuletzt angemeldet</strong> ist die letzte Anmeldung per Code. Eine grau dargestellte Funktion gilt nicht: die Rolle der Person reicht dafür nicht (mehr). Die Lupe bei <strong>Verknüpfte Person</strong> und <strong>App-E-Mail</strong> sucht in der Spalte.</p>
