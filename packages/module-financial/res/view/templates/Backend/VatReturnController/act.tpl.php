<?php
/**
 * MWST-Abrechnungen — the action cell (hc1): «Neue Abrechnung», the most frequent action of the
 * entry (owner decision 3 of 2026-11-25, «Nächste Abrechnung» of stage S1 renamed): it OPENS the
 * first elapsed period not settled yet as its return page — a period already computed, nothing is
 * created, so no «+»; the accent colour. The leading glyph is the arrow: on a phone the cell is a
 * square icon (the label stays the accessible name). Added by the trait's `listAction()` only when
 * there is such a period; else the cell stays empty.
 *
 * @var string $nextUrl
 * @var string $nextLabel
 */
?>
<a class="be-btn be-btn--primary" href="<?= e($nextUrl) ?>" title="<?= e($nextLabel) ?> öffnen" data-vat-return-next>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-arrow-right"/></svg>
    <span class="be-btn__label">Neue Abrechnung</span>
</a>
