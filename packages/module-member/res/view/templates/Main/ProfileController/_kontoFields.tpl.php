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
    <dt>Name</dt>
    <dd><?= e($name) ?></dd>
    <?php endif; ?>
    <dt>E-Mail</dt>
    <dd><?= e($account->getEmail()) ?> <span class="me-quiet">— Ihr Zugang</span></dd>
    <?php if ($account->getCompany() !== null): ?>
    <dt>Firma / Verwaltung</dt>
    <dd><?= e($account->getCompany()) ?></dd>
    <?php endif; ?>
    <dt>Status</dt>
    <?php /* The status the PERSON experiences, not the record's field: an
             active account whose every access is paused reads «pausiert»
             — «aktiv» there was true of the login and false of everything
             the person came for (Peter, 2026-09-14). The band above says
             which access and whom to ask. */ ?>
    <?php
    $paused = array_filter($closed, static fn(array $m): bool => ($m['state'] ?? '') === 'paused');
    if (!$account->isActive()) {
        $statusText = 'wartet auf Freischaltung';
        $statusNote = '';
    } elseif ($closed !== [] && $open === [] && $paused !== []) {
        $statusText = 'pausiert';
        $statusNote = 'Konto und Anmeldung bleiben bestehen';
    } elseif ($closed !== [] && $open === []) {
        $statusText = 'wartet auf Freischaltung';
        $statusNote = '';
    } else {
        $statusText = 'aktiv';
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
