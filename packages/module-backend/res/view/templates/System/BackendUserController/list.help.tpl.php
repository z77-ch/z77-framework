<?php
/**
 * Help for the backend user list (ADR-048, HELP-003) — attached by
 * `BackendUserController::listAction()` with `toolbar: true`, opened with «Hilfe» at the right
 * end of the toolbar. What the list is for, the row ⋮, the order, who may change whom. The user
 * window has its own help (`edit.help`); roles in depth: `Backend/Guide/usersAndRoles.help`.
 * Static text, no context.
 */
?>
<h3>Benutzer</h3>
<p>Wer sich im Backend anmelden darf: Benutzer anlegen, Passwort zurücksetzen, Rolle ändern, Benutzer löschen, Zwei-Faktor-Schutz (2FA) eines verlorenen Handys zurücksetzen.</p>
<p>Eine Zeile pro Konto: Benutzername (dein eigenes mit «(du)») und seine Rollen. <strong>+ Benutzer</strong> öffnet ein leeres Benutzerfenster. Die Reihenfolge ist frei: eine Zeile mit der Maus an ihren Platz ziehen — das ändert nichts am Konto.</p>

<h4>Das Menü ⋮ einer Zeile</h4>
<ul>
    <li><strong>Bearbeiten</strong> — öffnet das Benutzerfenster: Name, Passwort, Adresse für «Passwort vergessen», Absender für E-Mails, Rollen.</li>
    <li><strong>2FA zurücksetzen</strong> — nur für einen Super-User, nur bei einem anderen Konto mit eingeschaltetem Zwei-Faktor-Schutz (z.B. Handy verloren). Alle Anmeldungen dieses Kontos enden, danach genügt das Passwort; die Person wird per E-Mail informiert, wenn eine Adresse hinterlegt ist.</li>
    <li><strong>Löschen</strong> — entfernt das Konto dauerhaft, nach einer Rückfrage. Dein eigenes Konto kannst du nicht löschen, und das letzte Konto mit Admin-Rechten bleibt.</li>
</ul>

<h4>Wer wen ändern darf</h4>
<p>Du kannst nur Konten bearbeiten und löschen, deren Rollen nicht über deiner eigenen liegen; bei einem solchen Konto sagt das Menü ⋮ «Dieses Konto hat eine höhere Rolle als du.» Der letzte Super-User bleibt immer Super-User. Neue Benutzer, Rollen und Rechte im Überblick: Hilfe «Benutzer, Rollen und Rechte».</p>
