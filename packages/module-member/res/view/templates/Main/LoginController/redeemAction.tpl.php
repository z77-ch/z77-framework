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
        Das Gerät, an dem die Anmeldung angefordert wurde, meldet sich jetzt an
        — das dauert wenige Sekunden. Sie können dieses Fenster schliessen.
    </p>
<?php else: ?>
    <h1 class="me-card__title">Anmeldung bestätigen</h1>

    <?php if ($pending !== null): ?>
    <p class="me-card__lead">
        Diese Anmeldung wurde an einem anderen Bildschirm angefordert. Dort
        steht dieselbe Prüfzahl:
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
        Stimmen die Zahlen überein, bestätigen Sie hier — dann wird das andere
        Gerät angemeldet, dieses nicht. Stimmen sie <strong>nicht</strong>
        überein, hat jemand anderes die Anmeldung gestartet: Schliessen Sie
        diese Seite, ohne etwas anzuklicken.
    </p>

    <?php else: ?>
    <p class="me-card__lead">
        Zu diesem Link wartet kein Gerät mehr auf eine Bestätigung — die
        Anfrage ist abgelaufen oder wurde bereits beantwortet. Sie können sich
        aber hier anmelden.
    </p>
    <?php endif; ?>

    <?php /* The two ways out stand in one track: same width, one gap between
             them. Two buttons whose width follows their label read as «the
             main one and an afterthought» — and here the longer label belongs
             to the QUIETER choice, so the wrong one looked bigger. Weight is
             carried by colour alone, which is the decision from the security
             review; geometry must not argue with it. */ ?>
    <div class="me-decision">
        <?php if ($pending !== null): ?>
        <form method="post" action="/member/main/login/redeem" class="fe-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="decision" value="confirm">
            <?php /* Deliberately the QUIET button (security review 2026-08-07,
                    decision Peter): pressing it hands the session to another
                    device — the only action on this page that can help a
                    stranger. Signing in here is the harmless one and carries the
                    visual weight. */ ?>
            <button class="fe-form__submit fe-form__submit--quiet" type="submit">
                Anmeldung auf «<?= e($pending->getLabel()) ?>» bestätigen
            </button>
        </form>
        <?php endif; ?>

        <form method="post" action="/member/main/login/redeem" class="fe-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="decision" value="here">
            <button class="fe-form__submit" type="submit">
                <?= $pending !== null ? 'Stattdessen auf diesem Gerät anmelden' : 'Auf diesem Gerät anmelden' ?>
            </button>
        </form>
    </div>
<?php endif; ?>
</div>
