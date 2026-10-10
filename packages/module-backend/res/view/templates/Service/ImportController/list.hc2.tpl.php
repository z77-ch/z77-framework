<?php
/**
 * Import list — hc2 (middle slot): the GLOBAL plan actions. Per-row decisions stay in the body —
 * those are per record, not per screen.
 *
 * The action cell (`list.act.tpl.php`) carries «Plan berechnen» for the vendor defaults while no
 * plan exists; once one exists the cell is empty and the plan's actions stand here (ADR-033 rev.
 * 2026-10-08): «Übernehmen» writes → `.be-btn--confirm`; «Verwerfen» throws the plan away →
 * `.be-btn--danger`.
 *
 * A STALE plan (source or target moved, `$staleError`) cannot be applied, only thrown away: the
 * status says so and «Plan verwerfen» stands here as the one toolbar form — it used to sit inside
 * the body's alert (ADR-049 rev. 2026-10-10: a screen action stands in the fixed row, never in
 * the page body). The alert keeps the explanation.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots(); renders nothing when neither a
 * plan nor a stale plan is loaded — the band itself still renders (shell skeleton) so the screen
 * stays aligned.
 *
 * @var array|null  $planView   {sourceLabel, createdAt, summary, groups, acceptedCount}
 * @var string|null $staleError
 * @var int         $jobThreshold
 */

if (($staleError ?? null) !== null): ?>
<span class="be-shell-status be-shell-status--bad">
    <span class="be-shell-status__dot" aria-hidden="true"></span>
    <span class="be-shell-status__text">Plan nicht mehr gültig</span>
</span>
<form data-fetch-post="/backend/service/import/discard">
    <button type="submit" class="be-btn be-btn--danger">Plan verwerfen</button>
</form>
<?php return; endif;

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
    <button type="submit" class="be-btn be-btn--confirm" <?= $accepted === 0 ? 'disabled' : '' ?>>
        Übernehmen<?= $accepted > $jobThreshold ? ' (als Job)' : '' ?>
    </button>
</form>
<form data-fetch-post="/backend/service/import/discard">
    <button type="submit" class="be-btn be-btn--danger">Verwerfen</button>
</form>
