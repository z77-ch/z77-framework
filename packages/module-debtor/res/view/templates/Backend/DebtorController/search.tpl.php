<?php
/**
 * Debtors list — hc2 (middle slot): the search over the CONTACTS, the same
 * plain GET form the contact list uses (Rule 7: nothing here needs more
 * than a form). Added by the fragment's `listAction()` (financial.md,
 * «fragment slots»).
 *
 * @var string $query
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/debtor';
?>
<form method="get" action="<?= e($actionBase) ?>/list" role="search">
    <input type="search" name="q" class="be-input be-input--sm" value="<?= e($query ?? '') ?>" placeholder="Name, Firma oder E-Mail suchen …" aria-label="Debitoren suchen" autocomplete="off">
</form>
