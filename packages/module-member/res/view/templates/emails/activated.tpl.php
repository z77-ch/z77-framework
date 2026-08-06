<?php
/**
 * Activation mail BODY (B7): sent by the backend activation step («Sie sind
 * freigeschaltet»). Rejection sends NO automatic mail by spec — what we
 * write, we write ourselves.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $loginUrl absolute URL of the member entry point
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p>Guten Tag<?= $name !== '' ? ' ' . e($name) : '' ?></p>

<p>
    Ihr Zugang ist freigeschaltet — Sie können sich ab sofort anmelden:
</p>

<p>
    <a href="<?= e($loginUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        Zum Login
    </a>
</p>

<p>
    Falls die Schaltfläche nicht funktioniert, öffnen Sie diese Adresse in
    Ihrem Browser:<br>
    <a href="<?= e($loginUrl) ?>"><?= e($loginUrl) ?></a>
</p>
