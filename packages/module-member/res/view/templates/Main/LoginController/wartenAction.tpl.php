<?php
/**
 * Waiting page (B8 stage D): the neutral answer of the request form — plus
 * the check digits this request is waiting under.
 *
 * Wording rule for this page: the number belongs to THIS screen. Say that the
 * same number is in the mail (subject line included), say what happens after
 * the confirmation, and say what a mismatch means. Nothing here may be
 * readable as «the number is only over there».
 *
 * ⚠️ `$repeated` is the ONLY thing on this page that may differ between two
 * visitors, and it says nothing about an account — only that THIS browser
 * asked again (see LoginController::askedBefore()). The neutral lead above it
 * is unchanged in both cases; what changes is the advice at the foot.
 *
 * Three states, three whole cards in one: `pending` (everything the page
 * shows while the request waits), `done` (the link signed this browser
 * in, in ANOTHER tab — poll state `elsewhere`) and `dead` (the request is
 * gone, or its 15-minute window passed). login-wait.js only flips `hidden`
 * between them and sets the one href it learns from the poll; every word
 * stands here. A heading that keeps saying «Anmeldung angefordert» next to a
 * small line saying the opposite reads as a hung page (Fund Peter, axo3.ch,
 * 2026-09-19) — so the whole card changes, never a line.
 *
 * ⚠️ `hidden` sits ONLY on the three bare containers. Any author rule that
 * sets `display` beats the `[hidden]` UA rule by origin, however specific it
 * is — the styled elements (`me-card__*`, `me-btn`) stay one level inside.
 *
 * @var string $pageTitle
 * @var string $digits    four digits, or '' when nothing is waiting here
 * @var bool   $repeated  this browser has asked for a link before, this hour
 */
?>
<div class="me-card" data-login-wait>
    <div data-login-wait-pending>
    <h1 class="me-card__title"><?= e(t('member.wait.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.wait.lead')) ?>
    </p>

    <?php if ($digits !== ''): ?>
    <p class="me-check">
        <?= e(t('member.wait.digits')) ?> <strong class="me-check__digits"><?= e($digits) ?></strong>
    </p>
    <p class="me-card__note">
        <?= e(t('member.wait.howto')) ?>
    </p>
    <p class="me-card__note">
        <?= raw(t('member.wait.otherHtml')) ?>
    </p>
    <p class="me-card__note"><?= e(t('member.wait.waiting')) ?></p>
    <?php endif; ?>

    <?php if ($repeated): ?>
    <p class="me-card__aside">
        <?= raw(t('member.wait.repeatedHtml')) ?>
    </p>
    <?php else: ?>
    <p class="me-card__aside">
        <?= e(t('member.wait.noMail')) ?> <a href="<?= e(localizedUrl('/member/main/login')) ?>"><?= e(t('member.wait.again')) ?></a>
    </p>
    <?php endif; ?>
    </div>

    <?php if ($digits !== ''): ?>
    <?php /* The link was opened in ANOTHER TAB of this browser: the login
             lives on there, this tab steps aside. «Weiter» is the fallback
             for a closed tab — login-wait.js sets its href from the poll
             answer (landing, or the code prompt when 2FA is on). */ ?>
    <div data-login-wait-done hidden>
        <h1 class="me-card__title"><?= e(t('member.wait.doneTitle')) ?></h1>
        <p class="me-card__lead">
            <?= e(t('member.wait.doneLead')) ?>
        </p>
        <a class="me-btn" href="<?= e(localizedUrl('/member/main/login')) ?>" data-login-wait-done-link><?= e(t('member.wait.doneButton')) ?></a>
    </div>

    <?php /* The request is gone: its window passed (poll 'dead', or the
             script's own 15-minute limit), or the link was used elsewhere. */ ?>
    <div data-login-wait-dead hidden>
        <h1 class="me-card__title"><?= e(t('member.wait.deadTitle')) ?></h1>
        <p class="me-card__lead">
            <?= e(t('member.wait.deadLead')) ?>
        </p>
        <a class="me-btn" href="<?= e(localizedUrl('/member/main/login')) ?>"><?= e(t('member.wait.deadButton')) ?></a>
    </div>
    <?php endif; ?>
</div>
