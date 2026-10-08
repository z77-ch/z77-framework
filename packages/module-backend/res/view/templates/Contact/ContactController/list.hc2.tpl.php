<?php
/**
 * Contacts list — hc2 (toolbar): the search. «+ Kontakt» is in the action cell
 * (`list.act.tpl.php`, ADR-033 rev. 2026-10-08). The search is a plain GET form — the
 * browser submits it, the list re-renders with `?q=`; no JavaScript (Rule 7:
 * nothing here needs more than a form). One line, `.be-input--sm`
 * (BE-INPUT-001) so the fixed-height band keeps its height. Interim: it becomes the
 * list's column search (`Z77\Shared\Listing`) later.
 *
 * @var string $query
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/contact/contact';
?>
<form method="get" action="<?= e($actionBase) ?>/list" role="search">
    <input type="search" name="q" class="be-input be-input--sm" value="<?= e($query ?? '') ?>" placeholder="Name, Firma oder E-Mail suchen …" aria-label="Kontakte suchen" autocomplete="off">
</form>
