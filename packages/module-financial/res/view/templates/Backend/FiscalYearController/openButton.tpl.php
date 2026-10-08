<?php
/**
 * Geschäftsjahre list — the action cell (hc1, ADR-033 rev. 2026-10-08; the toolbar from
 * 2026-09-28): «+ Geschäftsjahr», open the next fiscal year — the entry's most frequent action.
 * Create = `.be-btn--primary` with the plus glyph; the word in `.be-btn__label` (on a phone the
 * cell shows the glyph alone, the word stays the accessible name). It opens the open dialog
 * (`data-fetch-get`).
 * Part of the fragment: the trait's `listAction()` adds it to the shell slot
 * with `addPartials()` — the fragment owns its header slots, so they come
 * along wherever it is mounted (ADR-018, Rule 8; financial.md).
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/fiscal-year') . '/open') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Geschäftsjahr</span>
</button>
