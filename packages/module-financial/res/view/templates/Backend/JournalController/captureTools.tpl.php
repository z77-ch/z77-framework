<?php
/**
 * Journal — the toolbar (hc2) of the capture area (FIN-JOURNAL-CAPTURE-001,
 * owner 2026-09-28; ADR-033 rev. 2026-10-08): «Einzel | Sammel» — tabs
 * (`.be-viewtabs`, two links): the mode is a page state (`?mode=`), the date
 * travels along. «Buchen» moved to the action cell (`captureAct`, rev.
 * 2026-10-08); «MwSt» is a switch IN the one-line row since 2026-10-08 (owner:
 * right after Betrag, `oneLine`, `.be-switch--for`) — no longer here.
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var string $mode        'einzel' | 'sammel'
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm|\Z77\Module\Financial\Ui\ManualEntryForm $form
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$modeLink   = static fn(string $m): string => $actionBase . '/list?' . http_build_query(array_filter([
    'mode' => $m === 'sammel' ? 'sammel' : '',
    'date' => $form->date(),
]));
?>
<nav class="be-viewtabs" aria-label="Erfassung">
    <?php foreach (['einzel' => 'Einzel', 'sammel' => 'Sammel'] as $key => $label): ?>
    <a class="be-viewtabs__tab<?= $mode === $key ? ' is-active' : '' ?>" href="<?= e($modeLink($key)) ?>"<?= $mode === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
