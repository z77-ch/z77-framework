<?php
/**
 * Notice after an administrator's 2FA reset: deliberately WITHOUT a link. Rendered inside the
 * shared emails/layout; plain text derived per HtmlToText.
 *
 * @var string $greetingName
 */
?>
<p>Hallo <?= e($greetingName) ?></p>

<p>
    Der Zwei-Faktor-Schutz (2FA) deines Backend-Kontos wurde soeben von einem Administrator
    zurückgesetzt. Alle Anmeldungen dieses Kontos wurden beendet. Ab jetzt meldest du dich nur mit
    dem Passwort an; den Schutz kannst du danach neu einrichten.
</p>

<p>
    Falls du das nicht veranlasst hast, wende dich sofort an den Administrator dieser Website.
</p>
