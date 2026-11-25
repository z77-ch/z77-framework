<?php
/**
 * One VAT return — the action cell (hc1) of an OPEN period whose plan has no blocking finding:
 * «MWST buchen …» (owner decisions 4 and 5 of 2026-11-25). Green (`be-btn--confirm` — something is
 * written) but WITHOUT the bare check mark the recipe gives a confirm (the owner: a check mark
 * suggests it is posted already); its leading glyph is `icon-file-check` — a document with a check
 * mark (owner 2026-11-25, FIN-VAT-ACTCELL-001): on a phone the shell shows only the glyph, the
 * label stays the accessible name. The three dots — it opens the window `confirm-post` (whose
 * button «MWST jetzt buchen» carries no icon). Not postable → the trait leaves the cell empty and
 * the reason is the first row of «Offene Punkte»; never a disabled button, never a tooltip.
 *
 * @var string $base
 * @var \Z77\Module\Financial\VatReturn\VatPeriod $period
 */
?>
<button type="button" class="be-btn be-btn--confirm" data-fetch-get="<?= e($base) ?>/confirm-post?from=<?= e($period->from->format('Y-m-d')) ?>" data-vat-return-post>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-check"/></svg>
    <span class="be-btn__label">MWST buchen …</span>
</button>
