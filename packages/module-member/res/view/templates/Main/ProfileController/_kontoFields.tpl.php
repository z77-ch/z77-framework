<?php
/**
 * The account's values on the Konto pane (`dl.me-field`). One partial for two
 * callers (MEMBER-FORM-ACTIONS-001, 2026-10-10): the page renders it, and the
 * Konto dialog's fetch save answers with it (`replace-html` of
 * `[data-konto-fields]`) — so the values after a save are the same markup the
 * page shows, never a second copy that drifts.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var list<array{ref:string,label:string,usable:bool,state:string,note:string}> $memberships
 */
$name   = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
$closed = array_values(array_filter($memberships ?? [], static fn(array $m): bool => empty($m['usable'])));
$open   = array_filter($memberships ?? [], static fn(array $m): bool => !empty($m['usable']));
?>
<dl class="me-field" data-konto-fields>
    <?php if ($name !== ''): ?>
    <dt><?= e(t('member.profile.name')) ?></dt>
    <dd><?= e($name) ?></dd>
    <?php endif; ?>
    <dt><?= e(t('member.form.email')) ?></dt>
    <dd><?= e($account->getEmail()) ?> <span class="me-quiet">— <?= e(t('member.profile.yourAccess')) ?></span></dd>
    <?php if ($account->getCompany() !== null): ?>
    <dt><?= e(t('member.form.company', \Z77\Module\Member\Services\TenantNoun::current())) ?></dt>
    <dd><?= e($account->getCompany()) ?></dd>
    <?php endif; ?>
    <?php /* ADR-056: only where the account's language decides the pages. */ ?>
    <?php if (\Z77\Module\Member\Services\MemberLanguage::offered() !== []): ?>
    <?php $language = \Z77\Module\Member\Services\MemberLanguage::of($account); ?>
    <dt><?= e(t('member.lang.label')) ?></dt>
    <dd lang="<?= e($language) ?>"><?= e(t('lang.' . $language, [], $language)) ?></dd>
    <?php endif; ?>
    <dt><?= e(t('member.profile.status')) ?></dt>
    <?php /* The status the PERSON experiences, not the record's field: an
             active account whose every access is paused reads «pausiert»
             — «aktiv» there was true of the login and false of everything
             the person came for (Peter, 2026-09-14). The band above says
             which access and whom to ask. */ ?>
    <?php
    $paused = array_filter($closed, static fn(array $m): bool => ($m['state'] ?? '') === 'paused');
    if (!$account->isActive()) {
        $statusText = t('member.profile.stateWaiting');
        $statusNote = '';
    } elseif ($closed !== [] && $open === [] && $paused !== []) {
        $statusText = t('member.profile.statePaused');
        $statusNote = t('member.profile.statePausedNote');
    } elseif ($closed !== [] && $open === []) {
        $statusText = t('member.profile.stateWaiting');
        $statusNote = '';
    } else {
        $statusText = t('member.profile.stateActive');
        $statusNote = '';
    }
    ?>
    <dd>
        <?= e($statusText) ?>
        <?php if ($statusNote !== ''): ?>
        <span class="me-quiet">— <?= e($statusNote) ?></span>
        <?php endif; ?>
    </dd>
</dl>
