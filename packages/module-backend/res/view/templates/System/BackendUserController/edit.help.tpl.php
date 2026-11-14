<?php
/**
 * Help for the backend user window (ADR-048) — new and edit; attached by
 * `BackendUserController::edit()`, opened with «Hilfe» in the window's title bar. A general part
 * plus one section per field (`data-help-field` = the field's name without `[…]`). Static text,
 * no context: the index renders it without variables. Facts: backend.md «user management»,
 * login.md «second factor», BackendUserValidator.
 */
?>
<h3>Benutzer anlegen und bearbeiten</h3>
<p>Ein Backend-Konto einrichten oder ändern: Benutzername, Passwort setzen oder ändern, Adresse für «Passwort vergessen», Absender für E-Mails (Name und Antwortadresse), Rollen und damit die Rechte.</p>
<p><strong>Speichern</strong> steht oben im Fenster; Fehler sind am Feld markiert, «n Fehler» springt zum ersten. Ändert sich das Passwort oder eine Rolle, enden alle Anmeldungen dieses Kontos (bei deinem eigenen bleibt die aktuelle bestehen); die Person meldet sich neu an.</p>

<section data-help-field="username">
<h3>Benutzername</h3>
<p>Der Name für die Anmeldung, z.B. «max». Er muss eindeutig sein.</p>
</section>

<section data-help-field="password">
<h3>Passwort</h3>
<p>Bei einem neuen Benutzer Pflicht, mit der angezeigten Mindestlänge. Beim Bearbeiten heisst leer: das Passwort bleibt. Die Anzeige unter dem Feld schätzt die Stärke; ein schwaches Passwort wird je nach Einstellung der Installation trotzdem angenommen, die Person wird dann bei jeder Anmeldung daran erinnert. Ein neues Passwort beendet auch einen offenen Link aus «Passwort vergessen».</p>
</section>

<section data-help-field="initials">
<h3>Initialen</h3>
<p>Zwei bis drei Zeichen für den runden Knopf oben rechts. Leer: die ersten zwei Buchstaben des Benutzernamens.</p>
</section>

<section data-help-field="name">
<h3>Name</h3>
<p>Vor- und Nachname, erscheint auf der Übersicht («Angemeldet als …»). Freiwillig.</p>
</section>

<section data-help-field="email">
<h3>E-Mail für Passwort-Reset</h3>
<p>Dahin schickt «Passwort vergessen?» auf der Anmeldeseite den Link, und dahin gehen die Hinweise zum Zwei-Faktor-Schutz. Ohne Adresse kann die Person ihr Passwort nicht selbst zurücksetzen — dann setzt ein Admin hier ein neues. Jede Adresse nur bei einem Konto.</p>
</section>

<section data-help-field="identity_name">
<h3>Absender für E-Mails</h3>
<p>In wessen Namen du E-Mails auslöst, z.B. eine Rechnung per E-Mail: pro Zeile <strong>Anzeigename</strong>, <strong>E-Mail</strong> (die Antwortadresse) und freiwillig <strong>Telefon</strong> (steht im Abschluss der Beleg-Mails). Die erste Zeile ist der Standard. <strong>+ Absender</strong> fügt eine Zeile an, × entfernt sie; höchstens 10, keine Adresse doppelt. Ohne Absender kannst du keine E-Mails auslösen. Das ist keine Anmeldeadresse.</p>
</section>

<section data-help-field="roles">
<h3>Rollen</h3>
<p>Mindestens eine; die höchste bestimmt, was das Konto darf. Angeboten werden nur Rollen bis zu deiner eigenen.</p>
<ul>
    <li><strong>Super-User</strong> — alles, dazu Backup, Jobs, Import und das Zurücksetzen des Zwei-Faktor-Schutzes anderer.</li>
    <li><strong>Admin</strong> — das ganze Backend ausser diesen Service-Werkzeugen.</li>
    <li><strong>Redaktor</strong> — nur die Texte der Webseite (Webseiten › Inhalte, Bilder wählen), die Übersicht und das eigene Konto.</li>
</ul>
<p>Die übrigen Rollen (Cron-Job, Mitglied, Besucher, Gast) sind für Abläufe und die Webseite gedacht; ein Backend-Konto mit nur einer davon kommt nicht ins Backend.</p>
</section>

<h3>Zwei-Faktor-Schutz</h3>
<p>Nur im eigenen Konto sichtbar: <strong>Einrichten …</strong> bzw. <strong>Entfernen …</strong> öffnet den Dialog (dasselbe wie oben rechts im Benutzermenü › Zwei-Faktor-Schutz). Ungespeicherte Änderungen im Fenster gehen dabei verloren — vorher speichern.</p>
