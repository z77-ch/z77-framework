<?php
/**
 * Notice to the OLD address after the app e-mail of an app user was changed (ADR-058 §4
 * «E-mail change»): the new address is not named; the signed-in devices stay signed in.
 * Deliberately WITHOUT a link.
 *
 * @var string $greetingName the linked person's name ('' when it is gone)
 * @var string $when         when it was changed, formatted
 */
?>
<p>Hallo<?= $greetingName !== '' ? ' ' . e($greetingName) : '' ?></p>

<p>
    Am <?= e($when) ?> wurde die E-Mail-Adresse deines Zugangs zur z77 App geändert. Anmeldecodes gehen
    ab jetzt nicht mehr an diese Adresse. Deine angemeldeten Geräte bleiben angemeldet.
</p>

<p>Warst du nicht darüber informiert, wende dich an den Administrator dieser Website.</p>
