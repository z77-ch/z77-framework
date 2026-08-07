<?php
/**
 * Profile page (B8): account data + state, the 2FA switch, and the devices
 * that stay signed in. A confirmed account sees «wartet auf Freischaltung»
 * (B7 decision 4 — sign-in works, access is this page only); an active one
 * is a full member.
 *
 * @var string $pageTitle
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var array<int,array<string,mixed>> $devices device keys, newest use first
 */
$day = static fn(string $iso): string => $iso === '' ? '' : date('d.m.Y', (int)strtotime($iso));
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<div class="me-card">
    <h1 class="me-card__title">Profil</h1>

    <?php if ($account->isConfirmed()): ?>
    <p class="me-card__lead">
        Ihre Registrierung ist bestätigt und <strong>wartet auf die
        Freischaltung</strong>. Sie erhalten eine E-Mail, sobald Ihr Zugang
        aktiv ist.
    </p>
    <?php endif; ?>

    <dl class="me-profile">
        <dt>E-Mail</dt>
        <dd><?= e($account->getEmail()) ?></dd>
        <?php if ($name !== ''): ?>
        <dt>Name</dt>
        <dd><?= e($name) ?></dd>
        <?php endif; ?>
        <?php if ($account->getCompany() !== null): ?>
        <dt>Firma / Verwaltung</dt>
        <dd><?= e($account->getCompany()) ?></dd>
        <?php endif; ?>
        <dt>Status</dt>
        <dd><?= e($account->isActive() ? 'aktiv' : 'wartet auf Freischaltung') ?></dd>
    </dl>

    <h2 class="me-card__subtitle">Zwei-Faktor-Schutz</h2>
    <?php if ($account->hasTotp()): ?>
    <p>
        Aktiv seit <?= e(substr((string)$account->getTotpActivatedAt(), 0, 10)) ?> —
        die Anmeldung fragt zusätzlich nach dem App-Code.
    </p>
    <form method="post" action="/member/main/profile/totp-remove" class="fe-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="fe-form__row">
            <label for="totp-remove-code">Zum Entfernen: Code aus der App</label>
            <input id="totp-remove-code" type="text" name="code" inputmode="numeric"
                   autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        </div>
        <button class="fe-form__submit" type="submit">2FA entfernen</button>
    </form>
    <?php else: ?>
    <p>
        Nicht aktiv. Mit einer Authenticator-App fragt die Anmeldung zusätzlich
        zum Link einen 6-stelligen Code ab.
    </p>
    <p><a class="fe-form__submit" style="text-decoration:none" href="/member/main/profile/totp">2FA einrichten</a></p>
    <?php endif; ?>

    <h2 class="me-card__subtitle">Angemeldete Geräte</h2>
    <?php if ($devices === []): ?>
    <p>
        Kein Gerät bleibt angemeldet. Setzen Sie beim Anmelden das Häkchen
        «Auf diesem Gerät angemeldet bleiben», wenn Sie nicht jedes Mal einen
        neuen Link anfordern möchten.
    </p>
    <?php else: ?>
    <table class="me-devices">
        <thead>
            <tr><th>Gerät</th><th>Angemeldet seit</th><th>Zuletzt genutzt</th><th>Gültig bis</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $device): ?>
            <tr>
                <td>
                    <?= e((string)$device['label']) ?>
                    <?php if ($device['current']): ?><span class="me-devices__here">dieses Gerät</span><?php endif; ?>
                </td>
                <td><?= e($day((string)$device['created_at'])) ?></td>
                <td><?= e($day((string)$device['last_used_at'])) ?></td>
                <td><?= e($day((string)$device['valid_until'])) ?></td>
                <td>
                    <form method="post" action="/member/main/profile/device-remove">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="device" value="<?= e((string)$device['id']) ?>">
                        <button type="submit">Abmelden</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="me-card__note">
        Gezählt wird pro Browser, nicht pro Computer: Wer seine Cookies löscht,
        ein privates Fenster nutzt oder einen zweiten Browser verwendet,
        erscheint hier als weiteres Gerät — auch mit gleicher Bezeichnung. Alte
        Einträge können Sie einzeln abmelden; sonst laufen sie nach 90 Tagen
        von selbst ab.
    </p>

    <form method="post" action="/member/main/profile/device-remove-all" class="fe-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button class="fe-form__submit" type="submit">Alle Geräte abmelden</button>
    </form>
    <?php endif; ?>

    <p class="me-card__aside">
        <a href="/member/main/logout">Abmelden</a>
    </p>
</div>
