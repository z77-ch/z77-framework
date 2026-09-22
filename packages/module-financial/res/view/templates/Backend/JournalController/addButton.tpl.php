<?php
/**
 * Journal list — hc1 (dark left slot): post a manual entry into the shown
 * fiscal year. A plain link to the entry PAGE (the form has n lines and a
 * server-side «Weitere Zeilen» — a page, not a modal; no JavaScript).
 * Part of the fragment: the trait's `listAction()` adds it to the shell slot
 * with `addPartials()` — the fragment owns its header slots, so they come
 * along wherever it is mounted (ADR-018, Rule 8; financial.md).
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var string $actionBase
 */
if (($year ?? null) === null) { return; }
?>
<a class="be-btn be-btn--primary" href="<?= e(($actionBase ?? '/backend/finance/journal') . '/add?year=' . rawurlencode($year->getCode())) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Buchung erfassen</span>
</a>
