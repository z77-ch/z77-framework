<?php
/**
 * Registration page (B7): intro + the declared form. The form markup comes
 * from the frontend module's generic publicForm partial (one source of truth
 * for the data-* contract the framework JS binds to) — member.css styles its
 * fe-* classes for this view-area.
 *
 * Four shapes in one file, because they are the same page with different
 * premises and a second template would drift from this one:
 *   $invite === null    the open registration
 *   $invite['dead']     a link that cannot be redeemed any more
 *   $invite['join']     the invited address HAS an account — «add this tenant
 *                       to it?», a yes/no POST (ADR-037)
 *   otherwise           redeeming an invitation into a NEW account (name form)
 *
 * @var string $pageTitle
 * @var ?array $invite    null | ['dead'=>true]
 *                        | ['dead'=>false,'join'=>false,'email'=>…,'outcome'=>?string]
 *                        | ['dead'=>false,'join'=>true,'email'=>…,'tenantName'=>…,'outcome'=>?string]
 * @var ?\Z77\Shared\Forms\PublicForm $form
 * @var array<string,array>  $fields
 * @var array<string,string> $errors
 * @var string $formError
 * @var string $checkUrl
 * @var string $csrfToken
 * @var ?string $originNote  Satz zum Angebot, über das jemand hergekommen ist
 */

use Z77\Module\Member\Services\InvitationFlow;
use Z77\Module\Member\Services\TenantNoun;

$noun     = TenantNoun::current(); // the word for the tenant (memberConfig tenantNoun), as placeholders of this language
$isInvite = is_array($invite ?? null);
$isDead   = $isInvite && ($invite['dead'] ?? false);
$isJoin   = $isInvite && !$isDead && ($invite['join'] ?? false);
$taken    = $isInvite && (($invite['outcome'] ?? null) === InvitationFlow::ALREADY_TAKEN);
?>
<div class="me-card">
<?php if ($isDead): ?>
    <h1 class="me-card__title"><?= e(t('member.invite.deadTitle')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.invite.deadLead')) ?>
    </p>
    <p class="me-card__aside">
        <?php /* Deliberately no resend: only the person who invited may renew
                 an invitation — otherwise the recipient keeps his own access
                 to the tenant alive (B7 v1.1.0). */ ?>
        <?= e(t('member.invite.deadAside')) ?>
    </p>

<?php elseif ($taken): ?>
    <h1 class="me-card__title"><?= e(t('member.invite.title')) ?></h1>
    <?php /* Whatever state — the address is on this tenant
             already. The link is consumed; nothing to do but sign in. */ ?>
    <p class="me-card__lead" role="alert">
        <?= e(t('member.invite.taken', $noun)) ?>
    </p>
    <p class="me-card__aside"><a href="<?= e(localizedUrl('/member/main/login')) ?>"><?= e(t('member.invite.toLogin')) ?></a></p>

<?php elseif ($isJoin): ?>
    <h1 class="me-card__title"><?= e(t('member.invite.joinTitle', $noun)) ?></h1>
    <p class="me-card__lead">
        <?= raw(t('member.invite.joinLeadHtml', array_map('e', $noun) + [
            'tenant' => '<strong>' . e((string)$invite['tenantName']) . '</strong>',
            'email'  => '<strong>' . e((string)$invite['email']) . '</strong>',
        ])) ?>
    </p>
    <p class="me-card__aside">
        <?= e(t('member.invite.joinAside', $noun)) ?>
    </p>

    <?php /* One form, two buttons. `decision` decides; there is nothing else
             to type. No JS — a POST with a name attribute is the whole of it. */ ?>
    <form method="post" class="fe-form me-decision" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button class="fe-form__submit" type="submit" name="decision" value="add"><?= e(t('member.invite.joinAdd', $noun)) ?></button>
        <button class="fe-form__submit fe-form__submit--quiet" type="submit" name="decision" value="decline"><?= e(t('member.invite.joinDecline')) ?></button>
    </form>

<?php elseif ($isInvite): ?>
    <h1 class="me-card__title"><?= e(t('member.invite.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.invite.lead', $noun)) ?>
    </p>

    <?php /* The address is TEXT, not a field: it comes from the token, and a
             pre-filled input would only look unchangeable (B7 v1.1.0). */ ?>
    <p class="me-card__aside">
        <?= e(t('member.invite.address')) ?> <strong><?= e((string)$invite['email']) ?></strong><br>
        <?= e(t('member.invite.addressFixed')) ?>
    </p>

    <?= $this->partial('partials/publicForm', [
        'form'      => $form,
        'fields'    => $fields,
        'errors'    => $errors,
        'formError' => $formError,
        'checkUrl'  => $checkUrl,
        'csrfToken' => $csrfToken,
    ], 'Z77\\Module\\Frontend') ?>

<?php else: ?>
    <h1 class="me-card__title"><?= e(t('member.register.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.register.lead')) ?>
    </p>

    <?php /* Der Satz zum Angebot, über das jemand hergekommen ist
             (memberConfig `originNotes`). Er steht ÜBER dem Formular: wer auf
             «Demo-Konto anlegen» geklickt hat und hier «Registrieren» liest,
             soll nicht erst raten, ob er richtig ist. Ohne passenden Eintrag
             steht hier nichts. */ ?>
    <?php if (trim((string)($originNote ?? '')) !== ''): ?>
    <p class="me-card__aside"><?= e((string)$originNote) ?></p>
    <?php endif; ?>

    <?= $this->partial('partials/publicForm', [
        'form'      => $form,
        'fields'    => $fields,
        'errors'    => $errors,
        'formError' => $formError,
        'checkUrl'  => $checkUrl,
        'csrfToken' => $csrfToken,
    ], 'Z77\\Module\\Frontend') ?>
<?php endif; ?>
</div>
