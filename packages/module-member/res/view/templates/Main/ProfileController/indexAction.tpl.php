<?php
/**
 * Profile page (B8, stage A): account data + state. A confirmed account sees
 * «wartet auf Freischaltung» (B7 decision 4 — sign-in works, access is this
 * page only); an active one is a full member. 2FA and the device list join
 * in the next stages.
 *
 * @var string $pageTitle
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 */
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

    <p class="me-card__aside">
        <a href="/member/main/logout">Abmelden</a>
    </p>
</div>
