<?php
/**
 * Magic-link login mail BODY (B8): the one-time link, short-lived (15 min).
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $loginUrl
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p>Guten Tag<?= $name !== '' ? ' ' . e($name) : '' ?></p>

<p>Mit diesem Link melden Sie sich an:</p>

<p>
    <a href="<?= e($loginUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        Jetzt anmelden
    </a>
</p>

<p>
    Falls die Schaltfläche nicht funktioniert, öffnen Sie diese Adresse in
    Ihrem Browser:<br>
    <a href="<?= e($loginUrl) ?>"><?= e($loginUrl) ?></a>
</p>

<p>
    Der Link ist 15 Minuten gültig und funktioniert genau einmal. Falls Sie
    keine Anmeldung angefordert haben, ignorieren Sie diese E-Mail — ohne den
    Link meldet sich niemand an.
</p>
