<?php
/**
 * One VAT return — the action cell (hc1) of an OPEN period whose plan has no blocking finding:
 * «MWST buchen …» (owner decisions 4 and 5 of 2026-11-25). Green (`be-btn--confirm` — something is
 * written) but WITHOUT the check mark the recipe gives a confirm (the owner: a check mark suggests
 * it is posted already); the three dots — it opens the window `confirm-post`. Not postable → the
 * trait leaves the cell empty and the reason is the first row of «Offene Punkte»; never a disabled
 * button, never a tooltip.
 *
 * @var string $base
 * @var \Z77\Module\Financial\VatReturn\VatPeriod $period
 */
?>
<button type="button" class="be-btn be-btn--confirm" data-fetch-get="<?= e($base) ?>/confirm-post?from=<?= e($period->from->format('Y-m-d')) ?>" data-vat-return-post>
    <span class="be-btn__label">MWST buchen …</span>
</button>
