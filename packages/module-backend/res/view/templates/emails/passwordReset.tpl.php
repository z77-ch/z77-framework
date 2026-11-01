<?php
/**
 * «Passwort vergessen» mail BODY: carries the one-time link, the only place the token plaintext
 * ever exists. Rendered inside the shared emails/layout; plain text derived per HtmlToText.
 *
 * @var string $greetingName
 * @var string $resetUrl
 * @var int    $validMinutes
 */
?>
<p>Hallo <?= e($greetingName) ?></p>

<p>
    Für dein Backend-Konto wurde ein neues Passwort angefordert. Über diesen Link
    setzt du es:
</p>

<p>
    <a href="<?= e($resetUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        Neues Passwort setzen
    </a>
</p>

<p>
    Falls die Schaltfläche nicht funktioniert, öffne diese Adresse in deinem Browser:<br>
    <a href="<?= e($resetUrl) ?>"><?= e($resetUrl) ?></a>
</p>

<p>
    Der Link ist <?= e($validMinutes) ?> Minuten gültig und funktioniert nur einmal.
</p>

<p>
    Falls du das nicht warst, ignoriere diese E-Mail. Dein Passwort bleibt, wie es ist.
</p>
