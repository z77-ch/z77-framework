<?php
/**
 * Confirmation page (B8 stage D, spec 1.1.0 decision 5): the link does not
 * sign anybody in by itself — the human decides WHICH device gets the
 * session. Time, device and check digits are the only thing that tells an
 * own request apart from one a stranger started on this address; they are
 * shown, never typed.
 *
 * When the waiting record is gone (window closed, or the request was
 * answered elsewhere), only «hier anmelden» remains.
 *
 * @var string $pageTitle
 * @var bool   $confirmed  true = the release just happened
 * @var ?\Z77\Module\Member\Entities\MemberPendingLogin $pending
 * @var string $token
 */
?>
<div class="me-card">
<?php if ($confirmed): ?>
    <h1 class="me-card__title">Anmeldung bestätigt</h1>
    <p class="me-card__lead">
        Das andere Gerät wird jetzt angemeldet. Sie können dieses Fenster
        schliessen.
    </p>
<?php else: ?>
    <h1 class="me-card__title">Anmeldung bestätigen</h1>

    <?php if ($pending !== null): ?>
    <dl class="me-profile">
        <dt>Angefordert</dt>
        <dd><?= e(date('d.m.Y, H:i', (int)strtotime($pending->getRequestedAt()))) ?> Uhr</dd>
        <dt>Gerät</dt>
        <dd><?= e($pending->getLabel()) ?></dd>
        <dt>Prüfzahl</dt>
        <dd><strong class="me-check__digits"><?= e($pending->getCheckDigits()) ?></strong></dd>
    </dl>
    <p class="me-card__note">
        Bestätigen Sie nur, wenn Sie diese Anmeldung selbst angefordert haben
        oder jemandem den Zugang bewusst freigeben. Stimmt die Prüfzahl nicht
        mit Ihrem Bildschirm überein, schliessen Sie diese Seite.
    </p>

    <form method="post" action="/member/main/login/redeem" class="fe-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="decision" value="confirm">
        <button class="fe-form__submit" type="submit">
            Anmeldung auf «<?= e($pending->getLabel()) ?>» bestätigen
        </button>
    </form>
    <?php else: ?>
    <p class="me-card__lead">
        Zu diesem Link wartet kein Gerät mehr auf eine Bestätigung. Sie können
        sich hier anmelden.
    </p>
    <?php endif; ?>

    <form method="post" action="/member/main/login/redeem" class="fe-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="decision" value="here">
        <button class="fe-form__submit fe-form__submit--quiet" type="submit">
            Jetzt auf diesem Gerät anmelden
        </button>
    </form>
<?php endif; ?>
</div>
