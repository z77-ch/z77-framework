<?php
/**
 * Member accounts — hc2 (middle slot): how many registrations wait for a decision.
 *
 * Why this and not an add action: accounts arrive from the public registration form, so
 * there is nothing to add here — every action is per row. What IS a property of the whole
 * screen is the operator's queue length, which the list itself only shows by sort order.
 *
 * Lives in module-backend (not module-member) because `loadHeaderSlots()` resolves header
 * partials against the backend namespace for the mounting controller — same arrangement as
 * the DMS Drive slots. See Service/MemberAccountsController.
 *
 * @var list<\Z77\Module\Member\Entities\MemberAccount> $accounts
 */

$waiting = 0;
foreach ($accounts as $account) {
    if ($account->isConfirmed()) {
        $waiting++;
    }
}
?>
<span class="be-shell-status<?= $waiting > 0 ? ' be-shell-status--ok' : '' ?>">
    <span class="be-shell-status__dot" aria-hidden="true"></span>
    <span class="be-shell-status__text">
        <?php if ($waiting === 0): ?>
        Keine Freischaltung offen
        <?php else: ?>
        <?= $waiting ?> <?= $waiting === 1 ? 'Konto wartet' : 'Konten warten' ?> auf Freischaltung
        <?php endif; ?>
    </span>
</span>
