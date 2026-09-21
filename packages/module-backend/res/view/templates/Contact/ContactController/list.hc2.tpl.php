<?php
/**
 * Contacts list — hc2 (middle slot): the search. A plain GET form — the
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
