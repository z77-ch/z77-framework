<?php
/**
 * Help for the dialog «Zwei-Faktor-Schutz» of the signed-in user's own account (login.md
 * BACKEND-2FA-001) — attached by `BackendUserController::twoFactorAction()`, opened with «Hilfe»
 * in the dialog's title bar. Both shapes (set up / remove) in one static text; field sections by
 * the dialog's names `tf_password` / `tf_code`.
 */
?>
<h3>Zwei-Faktor-Schutz</h3>
<p>Die Anmeldung zusätzlich mit einem Code vom Handy schützen: 2FA einrichten, Authenticator-App verbinden, QR-Code scannen, 2FA ausschalten, Handy verloren.</p>
<p>Mit eingeschaltetem Schutz fragt die Anmeldung nach dem Passwort zusätzlich nach einem sechsstelligen Code aus einer Authenticator-App (z.B. Google Authenticator, Aegis, 2FAS). Der Code wechselt alle 30 Sekunden.</p>

<h4>Einrichten</h4>
<ol>
    <li>In der App ein neues Konto hinzufügen und den <strong>QR-Code</strong> scannen. Ohne Kamera den angezeigten Schlüssel von Hand eingeben.</li>
    <li><strong>Dein Passwort</strong> und den aktuellen <strong>Code aus der App</strong> eingeben, dann <strong>Aktivieren</strong>.</li>
</ol>
<p>Bis dahin ändert sich nichts. Wird der Dialog geschlossen und neu geöffnet, gibt es einen neuen QR-Code — dann den neuen scannen. Nach dem Aktivieren enden deine Anmeldungen in anderen Browsern; an deine Adresse für «Passwort vergessen» geht eine Bestätigung.</p>

<h4>Entfernen</h4>
<p>Einen gültigen <strong>Code aus der App</strong> eingeben, dann <strong>Entfernen</strong>. Danach genügt wieder das Passwort.</p>

<h4>Handy verloren</h4>
<p>Ohne App kein Code: ein <strong>Super-User</strong> setzt den Schutz zurück (Stammdaten › System › Benutzer, ⋮ › <strong>2FA zurücksetzen</strong>). Danach mit dem Passwort anmelden und neu einrichten. Fünf falsche Codes sperren die Code-Eingabe für 15 Minuten.</p>

<section data-help-field="tf_password">
<h3>Dein Passwort</h3>
<p>Das Passwort, mit dem du dich anmeldest — so kann niemand mit einer fremden, offenen Sitzung den Schutz einrichten.</p>
</section>

<section data-help-field="tf_code">
<h3>Code aus der App</h3>
<p>Die sechs Ziffern, die die App gerade zeigt. Jeder Code gilt nur einmal: wer gleich danach noch einmal einen braucht, wartet auf den nächsten.</p>
</section>
