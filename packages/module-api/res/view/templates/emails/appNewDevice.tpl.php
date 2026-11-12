<?php
/**
 * «Neues Gerät angemeldet» (ADR-058): after every successful code sign-in, to the app e-mail —
 * the person learns at once when someone else used a relayed code. Deliberately WITHOUT a link.
 *
 * @var string $greetingName the linked person's name
 * @var string $device       browser/system of the new device
 * @var string $when         when it signed in, formatted
 */
?>
<p>Hallo <?= e($greetingName) ?></p>

<p>Am <?= e($when) ?> hat sich ein neues Gerät mit deinem App-Zugang in der z77 App angemeldet: <?= e($device) ?>.</p>

<p>Warst du das, ist alles in Ordnung.</p>

<p>
    <strong>Warst du es nicht?</strong> Dann hat jemand deinen Code erhalten. Melde dich sofort beim
    Administrator dieser Website — er sperrt das Gerät. Gib Codes aus der z77 App nie weiter.
</p>
