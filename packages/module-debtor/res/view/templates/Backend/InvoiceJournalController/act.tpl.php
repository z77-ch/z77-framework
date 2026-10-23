<?php
/**
 * Faktura-Journal — the action cell (hc1): «Journal verbuchen …», the most frequent action of
 * «Auftragswesen › Faktura-Journal» (backend-screen.md §2: it writes into the books → the green
 * confirm). It opens the confirmation page; the server re-judges everything there and at the
 * POST. While the journal is empty or blocked the button is DISABLED with its reason as title —
 * the button stays, so the user sees why (owner 2026-10-23: always the whole journal).
 *
 * Part of the fragment: added by the trait.
 *
 * @var array<string, mixed> $totals  JournalPostingService::totals()
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice-journal';
$reason     = match (true) {
    ($totals['count'] ?? 0) === 0  => 'Das Faktura-Journal ist leer — nichts zu verbuchen.',
    ($totals['blocked'] ?? 0) > 0  => 'Verbuchen gesperrt — ' . $totals['blocked'] . ' Dokument' . ($totals['blocked'] === 1 ? ' muss' : 'e müssen') . ' zuerst neu fakturiert werden.',
    default                        => '',
};
?>
<?php if ($reason === ''): ?>
<a class="be-btn be-btn--confirm" href="<?= e($actionBase . '/confirm-post') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</a>
<?php else: ?>
<button type="button" class="be-btn be-btn--confirm" disabled title="<?= e($reason) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Journal verbuchen …</span>
</button>
<?php endif; ?>
