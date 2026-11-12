<?php
/**
 * The sign-in code of the z77 App (ADR-058) — mail BODY inside the shared `emails/layout`. Sent
 * at once (EmailService::send(), never the outbox), only to the app e-mail of a usable app user.
 *
 * Anti-relay wording (ADR-058 §4 «The mails»): never pass the code on, z77 never calls and never
 * asks for it; the device and time of the request let the reader tell an own request from a
 * foreign one. German, as the app (explicit language `de`, the stateless path sets none).
 *
 * @var string $greetingName the linked person's name
 * @var string $code         six digits, also in the subject
 * @var string $device       browser/system of the requesting device
 * @var string $when         when it was requested, formatted
 * @var int    $minutes      how long the code lives
 */
?>
<p>Hallo <?= e($greetingName) ?></p>

<p>Mit diesem Code meldest du dich in der z77 App an:</p>

<p><strong style="font-size:24px;letter-spacing:4px;"><?= e($code) ?></strong></p>

<p>Angefordert am <?= e($when) ?> von: <?= e($device) ?>. Der Code gilt <?= (int) $minutes ?> Minuten und nur einmal.</p>

<p>
    <strong>Gib diesen Code niemandem weiter.</strong> z77 ruft nie an und fragt nie nach diesem Code
    — auch nicht der Administrator. Wer ihn hat, kann mit deinem App-Zugang Daten erfassen.
</p>

<p>Hast du keinen Code angefordert, musst du nichts tun: ohne den Code meldet sich niemand an.</p>
