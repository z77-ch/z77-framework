<?php
/**
 * Mandant — the toolbar (hc2): the form's three sections as tabs (owner 2026-10-08), in the
 * standard tab look `.be-viewtabs`. Each tab is a `<label for>` of a radio inside the form
 * (`edit.tpl.php`, `.be-radiotabs__radio`) — a click switches the panel without a request and
 * without JavaScript, and «Speichern» still writes the ONE form with all sections.
 *
 * The label carries no state of its own: the active underline and the focus ring follow the
 * radio through `.be-shell:has(… :checked)` (`.be-viewtabs__tab--for`, module-backend
 * components/_radiotabs.scss). `data-tab` is the tab's position, the same number the radio
 * and the panel carry. Not a `<nav>` — the tabs switch sections of one form, not pages.
 *
 * @var array<string,string> $mandatorTabs  tab key → label, in form order
 */
if (empty($mandatorTabs)) {
    return;
}
?>
<div class="be-viewtabs" role="group" aria-label="Abschnitte">
    <?php $n = 0; foreach ($mandatorTabs as $key => $label): $n++; ?>
    <label class="be-viewtabs__tab be-viewtabs__tab--for" for="mandator-tab-<?= e($key) ?>" data-tab="<?= $n ?>"><?= e($label) ?></label>
    <?php endforeach; ?>
</div>
