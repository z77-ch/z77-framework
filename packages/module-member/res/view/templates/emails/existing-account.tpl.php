<?php
/**
 * "You already have an account" mail BODY (B7): sent instead of a second
 * confirmation when a registration or resend names an address that already
 * has a confirmed/active account. The web page stays neutral; this mail is
 * the only place the existing account is mentioned — visible only to the
 * address owner.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p>Guten Tag<?= $name !== '' ? ' ' . e($name) : '' ?></p>

<p>
    Zu dieser E-Mail-Adresse besteht bereits ein Konto — eine erneute
    Registrierung ist nicht nötig.
</p>

<p>
    Sie können sich wie gewohnt anmelden. Falls Sie diese Anfrage nicht selbst
    ausgelöst haben, können Sie diese E-Mail ignorieren; an Ihrem Konto wurde
    nichts geändert.
</p>
