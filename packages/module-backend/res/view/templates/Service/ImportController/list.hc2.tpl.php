<?php
/**
 * Import list — hc2 (middle slot): the two GLOBAL plan actions, shown only while a plan
 * exists. Per-row decisions stay in the body — those are per record, not per screen.
 *
 * Why no hc1: "Plan berechnen" is per source (vendor defaults + n inbox files, each with
 * its own entity select), so there is no single primary action to put in the dark slot.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots(); renders nothing when no plan
 * is loaded — the band itself still renders (shell skeleton) so the screen stays aligned.
 *
 * @var array|null $planView   {sourceLabel, createdAt, summary, groups, acceptedCount}
 * @var int        $jobThreshold
 */

if ($planView === null) {
    return;
}
$accepted = (int) $planView['acceptedCount'];
?>
<span class="be-shell-status<?= $accepted > 0 ? ' be-shell-status--ok' : '' ?>">
    <span class="be-shell-status__dot" aria-hidden="true"></span>
    <span class="be-shell-status__text">
        Plan: <?= e($planView['sourceLabel']) ?> — <?= $accepted ?> markiert
    </span>
</span>
<form data-fetch-post="/backend/service/import/apply">
    <button type="submit" class="be-btn be-btn--primary" <?= $accepted === 0 ? 'disabled' : '' ?>>
        Übernehmen<?= $accepted > $jobThreshold ? ' (als Job)' : '' ?>
    </button>
</form>
<form data-fetch-post="/backend/service/import/discard">
    <button type="submit" class="be-btn be-btn--ghost">Verwerfen</button>
</form>
