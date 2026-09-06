<?php
/**
 * Operator notification BODY (B7, decision 8): a registration was confirmed —
 * activation happens in the backend. Wired via the emailConfig form key
 * 'memberConfirmed':
 *
 *   'memberConfirmed' => [
 *       'to'       => 'operator@example.ch',
 *       'subject'  => 'Neue bestätigte Registrierung',
 *       'template' => ['emails/confirmed-notify', 'Z77\\Module\\Member'],
 *   ],
 *
 * No key configured → no mail (the flow swallows the unknown-key throw).
 *
 * Two cases, one body. An INVITATION redeemed (B7 v1.1.0) reaches the same
 * operator through the same key — but it is a different thing to act on: no
 * new customer, no company (the form has no such field), and the account
 * attaches to a reference that already exists. Told apart by `$invite`, which
 * only the invitation path hands in. Without this the mail read «Firma —» and
 * the operator could not see what he was about to activate.
 *
 * The PROJECT may add lines (memberConfig `notifyRowsHook`) — label => value,
 * printed and escaped, never interpreted. That is what keeps a second mail
 * from being necessary: whatever a project has to say about this account says
 * it HERE, in the one mail that arrives when the operator can act.
 *
 * A GRANT (ADR-037, `$invite['grant']`) is the third case: the address had an
 * account already, nothing was created at all — an EXISTING account may
 * additionally work for the tenant once we activate the grant.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var array{tenantRef?:string, tenantName?:string, inviter?:string, grant?:bool}|null $invite
 * @var array<string,string>|null $notifyRows  extra rows from the project
 */

$invite ??= null;
$notifyRows ??= [];
$isGrant = $invite !== null && (bool)($invite['grant'] ?? false);
?>
<?php if ($isGrant): ?>
<?php /* ⚠️ Beide Sätze bleiben je auf EINER Zeile: die Text-Fassung der Mail
         wird aus diesem Markup abgeleitet, und ein Zeilenumbruch mitten im Satz
         klebt dort die Wörter zusammen («Zugangwurde»). */ ?>
<p><strong>Ein bestehendes Konto möchte zusätzlich für einen weiteren Mandanten arbeiten und wartet auf die Freischaltung.</strong></p>
<p>Es entsteht weder ein Konto noch ein Mandant — der Zugang wird nur angehängt:</p>
<?php elseif ($invite !== null): ?>
<p><strong>Ein eingeladener Zugang wurde soeben angenommen und wartet auf die Freischaltung.</strong></p>
<p>Es entsteht dabei kein neuer Mandant — das Konto hängt sich an einen bestehenden:</p>
<?php else: ?>
<p>Eine Registrierung wurde soeben bestätigt und wartet auf die Freischaltung:</p>
<?php endif; ?>

<table>
    <tr data-str="new-line">
        <td>E-Mail</td>
        <td><?= e($account->getEmail()) ?></td>
    </tr>
    <?php if ($invite !== null): ?>
    <tr data-str="new-line">
        <td>Mandant</td>
        <td><?= e((string)($invite['tenantName'] ?? '') ?: '—') ?></td>
    </tr>
    <tr data-str="new-line">
        <td>Eingeladen von</td>
        <td><?= e((string)($invite['inviter'] ?? '') ?: '—') ?></td>
    </tr>
    <?php else: ?>
    <tr data-str="new-line">
        <td>Firma / Verwaltung</td>
        <td><?= e($account->getCompany() ?? '—') ?></td>
    </tr>
    <?php /* Über welchen Knopf sie kam. Nur wenn es einen gab — die
             gewöhnliche Registrierung trägt keinen, und eine Zeile «—» wäre
             eine Frage, die niemand gestellt hat. */ ?>
    <?php if ($account->getOrigin() !== null): ?>
    <tr data-str="new-line">
        <td>Gekommen über</td>
        <td><?= e($account->getOrigin()) ?></td>
    </tr>
    <?php endif; ?>
    <?php endif; ?>
    <tr data-str="new-line">
        <td>Name</td>
        <td><?= e(trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? '')) ?: '—') ?></td>
    </tr>
    <?php if (!$isGrant): ?>
    <tr data-str="new-line">
        <td>Bestätigt am</td>
        <td><?= e($account->getConfirmedAt() ?? '—') ?></td>
    </tr>
    <?php else: ?>
    <tr data-str="new-line">
        <td>Heimat des Kontos</td>
        <td><?= e((string)($invite['homeName'] ?? '') ?: '—') ?></td>
    </tr>
    <?php endif; ?>
    <?php /* Was das Projekt zu diesem Konto zu sagen hat — zuletzt, damit die
             Angaben des Moduls ihre feste Reihenfolge behalten. */ ?>
    <?php foreach ($notifyRows as $label => $value): ?>
    <tr data-str="new-line">
        <td><?= e((string)$label) ?></td>
        <td><?= e((string)$value) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<p>Freischalten oder ablehnen: im Backend unter den Member-Konten.</p>
