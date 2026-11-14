<?php
/**
 * Help of the backend member-accounts list — Service › Member-Konten (ADR-048, HELP-003:
 * attached by `AccountsControllerTrait::listAction()` with `toolbar: true`, beside the
 * «n Konten warten auf Freischaltung» status). The states, the three row buttons and what each
 * does to the account and its mails. A host may narrow the list (`memberListRows()`) and rename
 * it (`memberListTitle()`); the text stays general. Also prompt material of the KI-Assistent
 * (ADR-055).
 */
?>
<h3>Member-Konten</h3>
<p>Die Konten des <strong>Mitgliederbereichs</strong> (Login der Kunden auf der Website): eine <strong>Registrierung freischalten</strong> oder ablehnen, den <strong>Zwei-Faktor-Schutz</strong> (2FA) zurücksetzen, wenn jemand sein Handy verloren hat.</p>
<p>Oben steht, wie viele Konten auf die Freischaltung warten. Die Liste zeigt die wartenden zuerst.</p>

<h3>Status eines Kontos</h3>
<ul>
    <li><strong>registriert</strong> (grau) — hat sich angemeldet, die E-Mail-Adresse aber noch nicht über den Link in der Bestätigungs-E-Mail bestätigt.</li>
    <li><strong>bestätigt — wartet</strong> (gelb) — die Adresse ist bestätigt; das Konto wartet auf deine Freischaltung. Bis dahin sieht es im Mitgliederbereich nichts.</li>
    <li><strong>aktiv</strong> (grün) — freigeschaltet; die Person kann sich anmelden und arbeiten.</li>
</ul>
<p>Eine Zeile zeigt E-Mail, Name und Firma, wann registriert und bestätigt wurde und für welchen Mandanten das Konto arbeitet. Bei einem wartenden Konto steht, was die Freischaltung tut: «erzeugt einen neuen Mandanten» (eine freie Registrierung) oder «hängt an «…» an» (eine Einladung zu einem bestehenden).</p>

<h3>Freischalten</h3>
<p><strong>Freischalten</strong> (nur bei «bestätigt — wartet») fragt nach und nennt, ob ein neuer Mandant entsteht oder das Konto an einen bestehenden angehängt wird. Danach ist das Konto <strong>aktiv</strong>, und die Person bekommt die E-Mail «Ihr Zugang ist freigeschaltet» mit dem Link zum Mitgliederbereich, in ihrer Sprache. Scheitert dabei der Schritt des Projekts (z.B. das Anlegen des Mandanten), bleibt das Konto wartend und die Meldung sagt warum.</p>

<h3>Ablehnen</h3>
<p><strong>Ablehnen</strong> (bei jedem Konto, das noch nicht aktiv ist) <strong>löscht</strong> das Konto nach einer Bestätigung. Es geht <strong>keine</strong> E-Mail hinaus — willst du der Person absagen, schreib ihr selbst. Ein aktives Konto lässt sich hier nicht löschen; die Person löscht es selbst in ihrem Profil.</p>

<h3>2FA zurücksetzen</h3>
<p><strong>2FA-Reset</strong> (nur bei einem Konto mit eingerichtetem Zwei-Faktor-Schutz) entfernt den Schutz nach einer Bestätigung. Das Konto bleibt, wie es ist; die Person meldet sich danach nur mit dem Link per E-Mail an, bis sie im Profil wieder einen Zwei-Faktor-Schutz einrichtet. Es geht keine E-Mail hinaus — prüfe vorher, dass wirklich die Person selbst darum bittet.</p>

<h3>Wie man in den Mitgliederbereich kommt</h3>
<p>Angemeldet wird ohne Passwort: die Person gibt ihre E-Mail ein und bekommt einen Anmelde-Link per E-Mail. Neue Konten entstehen über die Registrierung auf der Website (danach Freischaltung hier) oder über eine Einladung.</p>
