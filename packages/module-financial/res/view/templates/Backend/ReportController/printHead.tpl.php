<?php
/**
 * The report's own header — on PAPER only (`be-printonly`). Until 2026-09-24 a
 * printed report carried nothing of its own: what stood at the top of the PDF
 * was the BROWSER's print header, which the viewer can switch off, which every
 * browser formats differently, and which names the page title rather than the
 * report (FIN-PRINT-001 point 1, measured in the P2 exit check). A trial
 * balance that goes to a fiduciary must say whose books it is and what period
 * it covers.
 *
 * Left the mandator's letterhead (the shared partial of module-mandator, which
 * the invoice PDF of P3 part 3 will reuse), right what this document IS: the
 * report, the fiscal year, the period.
 *
 * Page 1 only (owner decision 2026-09-24): what a reader needs on page 7 are
 * the column titles, and those repeat by themselves since the list prints as a
 * table. A header on every sheet would need `position: fixed` plus page
 * margins and collides with a long table.
 *
 * @var \Z77\Module\Mandator\Entities\Mandator|null  $mandator
 * @var \Z77\Module\Financial\Reports\ReportRange    $range
 * @var string                                       $reportLabel
 * @var bool                                         $atDay  a statement AT a day (the balance sheet), not over a span
 */
$atDay = (bool)($atDay ?? false);
?>
<header class="be-printhead be-printonly">
    <div class="be-printhead__party">
        <?= $this->partial('partials/letterhead', ['mandator' => $mandator ?? null], 'Z77\Module\Mandator') ?>
    </div>
    <div class="be-printhead__doc">
        <span class="be-printhead__title"><?= e($reportLabel) ?></span>
        <span>Geschäftsjahr <?= e($range->year->getCode()) ?></span>
        <span><?= $atDay
            ? 'per ' . e($range->to->format('d.m.Y'))
            : e($range->from->format('d.m.Y')) . ' – ' . e($range->to->format('d.m.Y')) ?></span>
    </div>
</header>
