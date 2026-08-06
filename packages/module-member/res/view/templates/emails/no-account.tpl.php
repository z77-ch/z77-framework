<?php
/**
 * "No account here" mail BODY (B8 spec, Kontolage-Tabelle): a login was
 * requested for an address without an account. The web page stays neutral;
 * only the address owner learns there is nothing — with the way to change
 * that.
 *
 * @var string $registerUrl
 */
?>
<p>Guten Tag</p>

<p>
    Für diese E-Mail-Adresse wurde soeben eine Anmeldung angefordert — es
    besteht dazu aber kein Konto.
</p>

<p>
    Falls Sie ein Konto erstellen möchten, registrieren Sie sich hier:<br>
    <a href="<?= e($registerUrl) ?>"><?= e($registerUrl) ?></a>
</p>

<p>
    Falls Sie diese Anfrage nicht selbst ausgelöst haben, können Sie diese
    E-Mail ignorieren.
</p>
