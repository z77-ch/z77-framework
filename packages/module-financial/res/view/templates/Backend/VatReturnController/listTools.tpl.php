<?php
/**
 * MWST-Abrechnungen — the list's TOOLBAR (hc2, board S7-1): the one tool «Jahresübersicht»,
 * secondary (a ghost button, never primary — the action cell is «Neue Abrechnung»). It opens the
 * yearly overview of the tax year whose 31.12. lies in the selected fiscal year (owner B-3 — the
 * same rule as the link in «Jahr abschliessen», `VatYearLayout::linkFor()`). Added by the trait's
 * `listAction()` only when that tax year has a VAT period; the help trigger stays at the right end.
 * No JavaScript.
 *
 * @var string $yearOverviewUrl
 */
?>
<a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($yearOverviewUrl) ?>" data-vat-year-overview>
    <span class="be-btn__label">Jahresübersicht</span>
</a>
