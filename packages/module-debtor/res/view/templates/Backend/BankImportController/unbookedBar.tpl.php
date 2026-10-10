<?php
/**
 * The bar «n zugeordnet · noch nicht verbucht» with «Verbuchen» — the step the office
 * missed in the live test (2026-10-08): matching is not booking. It stands at the TOP and
 * stays there while the transactions scroll (`.z77-form-actions` is sticky); «Verbuchen»
 * WRITES payments and journal entries → the green confirm, a page POST (every row changes).
 *
 * Always rendered as its slot `[data-bank-unbooked-slot]` (empty without a matched
 * transaction): a row action answers it with `replace-html`, so the bar appears, counts or
 * goes with the row that changed (ADR-047 addendum 2026-10-10). Its form is a plain page
 * form — nothing in it needs core.js wiring after the swap.
 *
 * @var int $matched
 * @var int $messageId
 * @var string $csrfToken
 * @var string $actionBase
 */
?>
<div data-bank-unbooked-slot>
    <?php if ($matched > 0): ?>
    <div class="z77-form-actions" data-bank-unbooked="<?= (int) $matched ?>">
        <strong><?= (int) $matched ?> zugeordnet · noch nicht verbucht</strong>
        <form method="post" action="<?= e($actionBase) ?>/book?id=<?= (int) $messageId ?>" style="display: inline">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <button type="submit" class="be-btn be-btn--confirm be-btn--sm">
                <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
                <span class="be-btn__label">Verbuchen</span>
            </button>
        </form>
        <small class="be-list__cell--muted">erst damit entstehen die Zahlungen auf den Rechnungen</small>
    </div>
    <?php endif; ?>
</div>
