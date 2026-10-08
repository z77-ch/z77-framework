<?php
/**
 * Action cell (hc1, `{action}.act`) of `service/import/list` — ADR-033 rev. 2026-10-08: the most
 * frequent action of the entry, «Plan berechnen» for the framework's shipped defaults (the source
 * every install has), exactly one button, rendered inset by the shell. Shown only while no plan
 * exists and no stale plan blocks the screen — then the toolbar carries «Übernehmen» / «Verwerfen»
 * and the cell stays empty. An inbox file is planned from its own row (it needs its entity select).
 *
 * The button submits through the HTML `form` attribute (no script): the empty form beside it
 * carries the fetch contract, the button stays the cell's only visible element.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 *
 * @var array|null  $planView
 * @var string|null $staleError
 */

if ($planView !== null || ($staleError ?? null) !== null) {
    return;
}
?>
<form id="import-start-vendor" data-fetch-post="/backend/service/import/start-vendor"></form>
<button type="submit" form="import-start-vendor" class="be-btn be-btn--primary">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Plan berechnen</span>
</button>
