<?php
/**
 * The alert slot of the window «Ablegen …» after a refused POST — the sentence of
 * `YearOverviewStore`'s refusal, escaped; the slot's marker stays, so a second refusal replaces the
 * first.
 *
 * @var string $message
 */
?>
<div data-vat-year-store-refusal>
    <div class="be-modal__alert be-modal__alert--error"><?= e($message) ?></div>
</div>
