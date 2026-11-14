<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): the member area («Mitgliederbereich») seen from
 * the backend — how an account comes to be (registration, e-mail confirmation, activation in
 * Service › Member-Konten, or an invitation), how members sign in (link by e-mail, optional
 * 2FA), what they do in their profile, and what the operator can and cannot do. The member pages
 * themselves carry no help (they are multilingual, help texts are German — member.md). Not
 * attached to a screen: the help index finds every `*.help.tpl.php` under the module's
 * templates. Static text, no context. Facts: member.md.
 */
?>
<h3>Mitgliederbereich: Registrierung, Anmeldung, Freischaltung</h3>
<p>Mitgliederbereich der Website: Registrierung freischalten, jemanden einladen, Anmeldung ohne Passwort, Anmelde-Link kommt nicht an, Zwei-Faktor-Schutz zurücksetzen, Konto löschen.</p>

<h4>1. Registrierung und Freischaltung</h4>
<p>Jemand registriert sich auf der Website mit E-Mail und Namen und bekommt eine E-Mail mit einem Bestätigungs-Link (Status <strong>registriert</strong>). Klickt er den Link, ist die Adresse <strong>bestätigt</strong>, und das Konto wartet auf die Freischaltung; ist es so eingerichtet, bekommt das Büro dazu eine E-Mail («Neue bestätigte Registrierung», Formular-Mail unter Service › E-Mail). Im Backend unter Service › <strong>Member-Konten</strong> steht oben, wie viele Konten warten; <strong>Freischalten</strong> macht das Konto <strong>aktiv</strong> und schickt der Person die E-Mail «Ihr Zugang ist freigeschaltet» (je nach Projekt entsteht dabei ein neuer Mandant), <strong>Ablehnen</strong> löscht es ohne E-Mail. Kam der Bestätigungs-Link nicht an, kann die Person ihn auf der Website erneut anfordern («Link erneut anfordern»).</p>

<h4>2. Einladung</h4>
<p>Statt sich frei zu registrieren, kann eine Person <strong>eingeladen</strong> werden — von einem Mitglied, das schon Zugang hat, oder je nach Projekt von einem Backend-Benutzer. Sie bekommt eine E-Mail mit einem Link, nimmt die Einladung an und arbeitet danach für denselben Mandanten. Wie und wo eingeladen wird, bestimmt das Projekt.</p>

<h4>3. Anmelden ohne Passwort</h4>
<p>Es gibt kein Passwort. Die Person gibt auf der Website ihre E-Mail ein und bekommt einen <strong>Anmelde-Link</strong> per E-Mail. Öffnet sie ihn auf einem anderen Gerät als dem, auf dem sie die Anmeldung angefordert hat, muss sie dort bestätigen. Wer den Zwei-Faktor-Schutz eingerichtet hat, gibt danach noch den Code aus seiner Authenticator-App ein. Kommt kein Link: Spam-Ordner prüfen; aus Sicherheitsgründen sagt die Website nie, ob es zu einer Adresse ein Konto gibt, und zu viele Anfragen hintereinander werden eine Weile gebremst.</p>

<h4>4. Profil</h4>
<p>Im Profil ändert die Person ihre Kontodaten (Name, Firma, Sprache), richtet den <strong>Zwei-Faktor-Schutz</strong> ein oder entfernt ihn (mit einem Code), meldet angemeldete <strong>Geräte</strong> ab und kann ihr <strong>Konto löschen</strong>. Arbeitet sie für mehrere Mandanten, wählt sie oben, für welchen. E-Mails an die Person gehen in der Sprache ihres Kontos hinaus.</p>

<h4>5. Was das Backend tun kann</h4>
<ul>
    <li><strong>Freischalten</strong> und <strong>Ablehnen</strong> wartender Konten (Service › Member-Konten).</li>
    <li><strong>2FA-Reset</strong>, wenn jemand sein Handy mit der Authenticator-App verloren hat — danach meldet er sich nur mit dem Link an, bis er den Schutz neu einrichtet. Nur auf Bitte der Person selbst; es geht keine E-Mail hinaus.</li>
    <li>Ein <strong>aktives</strong> Konto löscht nur die Person selbst in ihrem Profil.</li>
</ul>

<h4>Noch nicht verfügbar</h4>
<p>Mitglieder als Benutzer der z77 App (Belege mit dem Handy) und eine Hilfe direkt auf den Seiten des Mitgliederbereichs gibt es noch nicht — folgt.</p>
