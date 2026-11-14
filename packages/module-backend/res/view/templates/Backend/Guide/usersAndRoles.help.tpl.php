<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): backend accounts, roles and what each role may
 * open, who may change whom, password and second factor, sender identities. Not attached to a
 * screen (the user list and window have their own help). Static text, no context. Facts:
 * AuthRole, backendConfig `controllers` (the deviations from ADMIN), BackendUserController
 * (mayGrant, isAboveActor, last SUPER_USER, deleteBlockReason), login.md, ADR-045.
 */
?>
<h3>Benutzer, Rollen und Rechte</h3>
<p>Wer was im Backend darf: neuen Mitarbeiter anlegen, Rechte vergeben, Rolle Redaktor, Admin oder Super-User, warum ein Menüpunkt fehlt, Passwort vergessen, Handy für den Code verloren, Absender für E-Mails.</p>

<h4>Konten</h4>
<p>Jede Person hat ein eigenes Konto unter Stammdaten › System › <strong>Benutzer</strong> (nur Admins sehen diese Seite). Dort: <strong>+ Benutzer</strong> legt eines an, ⋮ › <strong>Bearbeiten</strong> ändert Passwort, Name, Adresse und Rollen, ⋮ › <strong>Löschen</strong> entfernt es. Kundenkonten der Webseite sind davon getrennt und kommen nicht ins Backend. Wo die App installiert ist, bekommt ein Benutzer unter Stammdaten › System › <strong>App-Benutzer</strong> zusätzlich einen Zugang für die App; er meldet sich damit nur in der App an.</p>

<h4>Die Rollen</h4>
<p>Ein Konto hat eine oder mehrere Rollen; die höchste zählt.</p>
<ul>
    <li><strong>Redaktor</strong> — pflegt die Texte der Webseite: Webseiten › Inhalte (bearbeiten, Varianten, veröffentlichen, alte Version zurückholen — nicht löschen), «Seite bearbeiten» direkt auf der Webseite, Bilder aus dem Drive wählen und hochladen. Dazu die Übersicht, der KI-Assistent und das eigene Konto (Aussehen, Zwei-Faktor-Schutz). Sonst nichts vom Backend.</li>
    <li><strong>Admin</strong> — das ganze Backend: alle Fachbereiche (z.B. Aufträge, Rechnungen, Buchhaltung, Kontakte, Drive), Webseiten mit Metadaten, Übersetzungen und Navigation, Benutzer, E-Mail-Einstellungen, Statistik, Postausgang, Formular-Protokoll, Cache leeren.</li>
    <li><strong>Super-User</strong> — zusätzlich die Werkzeuge, die die ganze Installation betreffen: <strong>Backup</strong>, <strong>Jobs</strong>, <strong>Import</strong>, und den Zwei-Faktor-Schutz eines anderen zurücksetzen. Das erste Konto einer Installation ist ein Super-User.</li>
</ul>
<p>Cron-Job, Mitglied, Besucher und Gast sind Rollen für Abläufe und die Webseite; ein Backend-Konto mit nur einer davon kommt nicht ins Backend.</p>

<h4>Warum fehlt ein Menüpunkt?</h4>
<p>Menü, Übersicht und Knöpfe zeigen nur, was die eigene Rolle öffnen darf. Fehlt etwas, reicht die Rolle nicht — ein Admin oder Super-User gibt eine höhere. Welche Rolle welchen Bildschirm öffnet, legt die Installation fest (Einstellung des Entwicklers); im Backend lässt sich das nicht pro Bildschirm ändern.</p>

<h4>Wer wen ändern darf</h4>
<ul>
    <li>Niemand vergibt eine Rolle über seiner eigenen; das Benutzerfenster bietet nur Rollen bis zur eigenen an.</li>
    <li>Ein Konto mit höherer Rolle als deine kannst du weder bearbeiten noch löschen.</li>
    <li>Das eigene Konto lässt sich nicht löschen; das letzte Konto mit Admin-Rechten bleibt, und der letzte Super-User bleibt Super-User.</li>
    <li>Ändern sich Passwort oder Rollen eines Kontos, enden seine Anmeldungen; die Person meldet sich neu an.</li>
</ul>

<h4>Passwort und Zwei-Faktor-Schutz</h4>
<ul>
    <li><strong>Passwort vergessen</strong> — auf der Anmeldeseite «Passwort vergessen?»; der Link kommt an die «E-Mail für Passwort-Reset» des Kontos. Ohne diese Adresse setzt ein Admin im Benutzerfenster ein neues Passwort.</li>
    <li><strong>Zwei-Faktor-Schutz</strong> — jede Person schaltet ihn selbst ein (Benutzermenü oben rechts › Zwei-Faktor-Schutz): danach fragt die Anmeldung zusätzlich nach einem Code aus einer Authenticator-App.</li>
    <li><strong>Handy verloren</strong> — ein Super-User setzt den Schutz zurück: Benutzer, ⋮ › <strong>2FA zurücksetzen</strong>. Hat der einzige Super-User sein Handy verloren, hilft nur der Betreiber auf dem Server.</li>
</ul>

<h4>Absender für E-Mails</h4>
<p>Wer aus dem Backend E-Mails auslöst (z.B. eine Rechnung per E-Mail), braucht im eigenen Konto mindestens einen <strong>Absender</strong>: Anzeigename, Antwortadresse und freiwillig Telefon. Die erste Zeile ist der Standard. Das ist unabhängig von der Adresse für «Passwort vergessen».</p>
