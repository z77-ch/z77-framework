<?php
/**
 * Contacts list — hc2 (toolbar): the search, then the add action. Toolbar (ADR-033 rev. 2026-09-28): the action acts on the list in the work area, so it stands there — not in the action cell over the rail.
 * The search: A plain GET form — the
 * browser submits it, the list re-renders with `?q=`; no JavaScript (Rule 7:
 * nothing here needs more than a form). One line, `.be-input--sm`
 * (BE-INPUT-001) so the fixed-height band keeps its height.
 *
 * @var string $query
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/contact/contact';
?>
<form method="get" action="<?= e($actionBase) ?>/list" role="search">
    <input type="search" name="q" class="be-input be-input--sm" value="<?= e($query ?? '') ?>" placeholder="Name, Firma oder E-Mail suchen …" aria-label="Kontakte suchen" autocomplete="off">
</form>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/contact/contact') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Kontakt</span>
</button>
