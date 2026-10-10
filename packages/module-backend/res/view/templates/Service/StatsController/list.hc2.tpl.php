<?php
/**
 * Statistik — hc2 (toolbar): «Bericht <Monat> senden», the one screen action. It used to stand in
 * the middle of the page body, under the mail paragraph (forms-actions review 2026-10-10, row 30);
 * a screen action stands in the fixed row (ADR-049 rev. 2026-10-10, backend-screen.md §1). A
 * secondary tool (`be-btn--ghost`), not the action cell: the most frequent thing done here is
 * reading the report, not sending it.
 *
 * The form carries the fetch contract and stands in the toolbar itself, as the Import screen's
 * plan forms do. The answer is a flash only — nothing on the page changes (StatsController::sendAction).
 * Shown only when there is something to send: a month with a report and a link.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 *
 * @var ?string $month
 * @var ?array  $report
 * @var ?array  $link
 */
use Z77\Shared\Stats\StatsReport;

if (($link ?? null) === null || ($report ?? null) === null || ($month ?? null) === null) {
    return;
}
$label = StatsReport::monthLabel((string) $month);
?>
<form id="stats-send" data-fetch-post="/backend/service/stats/send">
    <input type="hidden" name="month" value="<?= e((string) $month) ?>">
    <button type="submit" class="be-btn be-btn--ghost"
            title="Bericht <?= e($label) ?> jetzt senden — geht sofort hinaus, an die Empfänger unter «Monatsmail»">Bericht <?= e($label) ?> senden</button>
</form>
