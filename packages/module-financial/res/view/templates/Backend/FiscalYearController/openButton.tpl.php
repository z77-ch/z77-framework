<?php
/**
 * Geschäftsjahre list — hc1 (dark left slot): open the next fiscal year.
 * Part of the fragment: the trait's `listAction()` adds it to the shell slot
 * with `addPartials()` — the fragment owns its header slots, so they come
 * along wherever it is mounted (ADR-018, Rule 8; financial.md).
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/fiscal-year') . '/open') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Geschäftsjahr eröffnen</span>
</button>
