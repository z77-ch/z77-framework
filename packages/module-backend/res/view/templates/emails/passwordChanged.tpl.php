<?php
/**
 * Notice after «Passwort vergessen»: deliberately WITHOUT a link. Rendered inside the shared
 * emails/layout; plain text derived per HtmlToText.
 *
 * @var string $greetingName
 */
?>
<p>Hallo <?= e($greetingName) ?></p>

<p>
    Das Passwort deines Backend-Kontos wurde soeben über «Passwort vergessen» geändert.
    Alle anderen Anmeldungen dieses Kontos wurden beendet.
</p>

<p>
    Falls du das nicht warst, wende dich sofort an den Administrator dieser Website.
</p>
