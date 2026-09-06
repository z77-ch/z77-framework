<?php
/**
 * Grant activation mail BODY (ADR-037): the operator activated a grant — an
 * EXISTING account may now work for an additional tenant. Same event from the
 * customer's chair as «Sie sind freigeschaltet», with one difference worth a
 * sentence: he signs in as always and CHOOSES the tenant in the header.
 * Rejection sends NO automatic mail, as with accounts.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $tenantName readable name of the added tenant
 * @var string $loginUrl   absolute URL of the member entry point
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p>Guten Tag<?= $name !== '' ? ' ' . e($name) : '' ?></p>

<p>
    Ihr Zugang zu <strong><?= e($tenantName) ?></strong> ist freigeschaltet.
    Melden Sie sich wie gewohnt an und wählen Sie oben im Kopf der Seite,
    für welche Verwaltung Sie gerade arbeiten — ein Wechsel braucht keine
    neue Anmeldung.
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
