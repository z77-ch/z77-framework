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
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 */
?>
<p>Eine Registrierung wurde soeben bestätigt und wartet auf die Freischaltung:</p>

<table>
    <tr data-str="new-line">
        <td>E-Mail</td>
        <td><?= e($account->getEmail()) ?></td>
    </tr>
    <tr data-str="new-line">
        <td>Firma / Verwaltung</td>
        <td><?= e($account->getCompany() ?? '—') ?></td>
    </tr>
    <tr data-str="new-line">
        <td>Name</td>
        <td><?= e(trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? '')) ?: '—') ?></td>
    </tr>
    <tr data-str="new-line">
        <td>Bestätigt am</td>
        <td><?= e($account->getConfirmedAt() ?? '—') ?></td>
    </tr>
</table>

<p>Freischalten oder ablehnen: im Backend unter den Member-Konten.</p>
