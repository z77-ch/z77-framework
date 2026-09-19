<?php
/**
 * Confirmation page (B8 stage D, spec 1.1.0 decision 5): shown only when the
 * link is opened OUTSIDE the browser that asked for it — the requesting
 * browser is signed in without this page (LoginController::redeemAction).
 * Here the human decides WHICH device gets the session; «hier» is the
 * default. Time, device and check digits are the only thing that tells an
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
        Das Gerät, an dem die Anmeldung angefordert wurde, meldet sich jetzt an
        — das dauert wenige Sekunden. Sie können dieses Fenster schliessen.
    </p>
<?php else: ?>
    <h1 class="me-card__title">Anmeldung bestätigen</h1>

    <?php if ($pending !== null): ?>
    <p class="me-card__lead">
        Diese Anmeldung wurde in einem anderen Browser oder auf einem anderen
        Gerät angefordert:
    </p>
    <dl class="me-profile">
        <dt>Prüfzahl</dt>
        <dd><strong class="me-check__digits"><?= e($pending->getCheckDigits()) ?></strong></dd>
        <dt>Angefordert</dt>
        <dd><?= e(date('d.m.Y, H:i', (int)strtotime($pending->getRequestedAt()))) ?> Uhr</dd>
        <dt>Gerät</dt>
        <dd><?= e($pending->getLabel()) ?></dd>
    </dl>
    <p class="me-card__note">
        Das andere Gerät lassen Sie nur zu, wenn dort dieselbe Prüfzahl steht.
        Steht dort <strong>keine</strong> oder eine andere, hat jemand anderes
        die Anmeldung gestartet: Lassen Sie sie nicht zu.
    </p>

    <?php else: ?>
    <p class="me-card__lead">
        Zu diesem Link wartet kein Gerät mehr auf eine Bestätigung — die
        Anfrage ist abgelaufen oder wurde bereits beantwortet. Sie können sich
        aber hier anmelden.
    </p>
    <?php endif; ?>

    <?php /* The harmless way out comes first and is the button; the other one
             is a small, quiet line below it (decision Peter, 2026-09-19).
             Pressing «zulassen» hands the session to another device — the
             only action on this page that can help a stranger — so an
             inattentive reader must reach for the big one by default. */ ?>
    <div class="me-decision">
        <form method="post" action="/member/main/login/redeem" class="fe-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="decision" value="here">
            <button class="fe-form__submit" type="submit">Jetzt hier anmelden</button>
        </form>

        <?php if ($pending !== null): ?>
        <form method="post" action="/member/main/login/redeem" class="fe-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="decision" value="confirm">
            <button class="me-decision__other" type="submit">
                Anmeldung auf dem anderen Gerät zulassen (<?= e($pending->getLabel()) ?>)
            </button>
        </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
