<?php
/**
 * Notice after a user switched 2FA off: deliberately WITHOUT a link. Rendered inside the shared
 * emails/layout; plain text derived per HtmlToText.
 *
 * @var string $greetingName
 */
?>
<p>Hallo <?= e($greetingName) ?></p>

<p>
    Für dein Backend-Konto wurde soeben der Zwei-Faktor-Schutz (2FA) entfernt. Ab jetzt genügt für
    die Anmeldung das Passwort.
</p>

<p>
    Falls du das nicht warst, wende dich sofort an den Administrator dieser Website.
</p>
