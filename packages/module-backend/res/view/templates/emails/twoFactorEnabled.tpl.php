<?php
/**
 * Notice after a user switched 2FA on: deliberately WITHOUT a link. Rendered inside the shared
 * emails/layout; plain text derived per HtmlToText.
 *
 * @var string $greetingName
 */
?>
<p>Hallo <?= e($greetingName) ?></p>

<p>
    Für dein Backend-Konto wurde soeben der Zwei-Faktor-Schutz (2FA) eingerichtet. Ab jetzt fragt die
    Anmeldung zusätzlich nach einem Code aus der Authenticator-App. Alle anderen Anmeldungen dieses
    Kontos wurden beendet.
</p>

<p>
    Falls du das nicht warst, wende dich sofort an den Administrator dieser Website.
</p>
