<?php
/**
 * Magic-link login mail BODY (B8, spec 1.1.0): the one-time link (15 min) —
 * and the check digits plus the context of the request, so the reader can
 * decide BEFORE clicking whether this login is theirs.
 *
 * Wording rule for this mail: say where the number comes from (the screen
 * where the login was requested), say what to do, and say what to do when the
 * numbers differ. No sentence may leave open which of the two the reader
 * should compare.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $loginUrl
 * @var string $digits  four digits, also in the subject
 * @var string $device  browser/system of the requesting device
 * @var string $when    when it was requested, formatted
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p>Guten Tag<?= $name !== '' ? ' ' . e($name) : '' ?></p>

<p>Für Ihr Konto wurde eine Anmeldung angefordert:</p>

<p>
    <strong style="font-size:20px;letter-spacing:3px;">Prüfzahl <?= e($digits) ?></strong><br>
    <?= e($when) ?> Uhr · <?= e($device) ?>
</p>

<p>
    <strong>Vergleichen Sie zuerst:</strong> Auf dem Bildschirm, an dem Sie die
    Anmeldung angefordert haben, steht dieselbe Prüfzahl. Stimmen die beiden
    Zahlen überein, ist es Ihre Anmeldung.
</p>

<p>
    <a href="<?= e($loginUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        Anmeldung fortsetzen
    </a>
</p>

<p>
    Auf der Seite, die sich dann öffnet, wählen Sie, welches Gerät angemeldet
    wird: dasjenige, an dem Sie die Anmeldung angefordert haben, oder das
    Gerät, auf dem Sie diese E-Mail gerade lesen.
</p>

<p>
    Falls die Schaltfläche nicht funktioniert, öffnen Sie diese Adresse in
    Ihrem Browser:<br>
    <a href="<?= e($loginUrl) ?>"><?= e($loginUrl) ?></a>
</p>

<p>
    <strong>Kennen Sie diese Prüfzahl nicht?</strong> Dann hat jemand anderes
    eine Anmeldung mit Ihrer E-Mail-Adresse gestartet. Löschen Sie diese
    E-Mail einfach — solange Sie den Link nicht öffnen und bestätigen,
    passiert nichts.
</p>

<p>
    Der Link ist 15 Minuten gültig und funktioniert genau einmal.
</p>
