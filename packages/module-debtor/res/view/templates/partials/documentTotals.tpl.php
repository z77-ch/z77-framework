<?php
/**
 * The TOTALS under the positions of a document mask — a shared building block (Rule 9): debtor's
 * invoice editor and module-order's order window render it at the foot of their positions. It
 * shows the STORED totals of a debtor document (an invoice's snapshot, the preview of an order —
 * both an `Invoice`) and is the scope's output of core.js «line sums»: while rows are typed, the
 * script rewrites `[data-sum-net]`, `[data-sum-gross]` and the VAT rows, hides `[data-sum-stored]`
 * (the rounding, the stored note) and shows `[data-sum-live]` (css-backend.md «rows, autosize,
 * line sums»). Display only — the save decides (Rule 7).
 *
 * Inside the `[data-sum]` scope of the positions.
 *
 * Rendered with `$this->partial('partials/documentTotals', [...], 'Z77\Module\Debtor')`.
 *
 * @var \Z77\Module\Debtor\Entities\Invoice|null $document  the stored numbers; null = none yet
 * @var string   $currency  «Total CHF» while there is no document
 * @var bool     $vat       the document carries VAT — without, no VAT rows and no VAT template
 * @var string   $none      what the totals show without a document (`0.00` for a new, empty one; else «–»)
 * @var string   $stored    the note under the stored total ('' = none)
 * @var string   $live      the note once the sum is live
 * @var callable $fmt       Money → «1'234.50»
 */
use Z77\Module\Vat\Entities\TaxRate;

$none = (string) ($none ?? '–');
?>
<div class="be-form__totals" aria-live="polite">
    <div><span>Total netto</span><span data-sum-net><?= $document !== null ? e($fmt($document->getNetTotal())) : e($none) ?></span></div>
    <?php if (!empty($vat)): ?>
    <div class="be-form__totals-group" data-sum-taxes>
        <template data-sum-tax><div class="be-form__totals-sub"><span>MWST <span data-sum-tax-rate></span> % auf <span data-sum-tax-base></span></span><span data-sum-tax-amount></span></div></template>
        <?php foreach ($document?->getTaxes() ?? [] as $tax): ?>
        <div class="be-form__totals-sub"><span>MWST <?= e(TaxRate::formatPercent($tax->getTaxRate())) ?> % auf <?= e($fmt($tax->getBase())) ?></span><span><?= e($fmt($tax->getTax())) ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($document !== null && !$document->getRounding()->isZero()): ?>
    <div class="be-form__totals-sub" data-sum-stored><span>Rundung</span><span><?= e($fmt($document->getRounding())) ?></span></div>
    <?php endif; ?>
    <div class="be-form__totals-grand"><span>Total <?= e($document?->getCurrency() ?? $currency) ?></span><span data-sum-gross><?= $document !== null ? e($fmt($document->getGrossTotal())) : e($none) ?></span></div>
    <?php if ($document !== null && ($stored ?? '') !== ''): ?>
    <div class="be-form__totals-note" data-sum-stored><?= e($stored) ?></div>
    <?php endif; ?>
    <div class="be-form__totals-note" data-sum-live hidden><?= e((string) ($live ?? '')) ?></div>
</div>
