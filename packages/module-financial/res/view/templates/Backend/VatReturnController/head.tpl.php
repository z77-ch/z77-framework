<?php
/**
 * One VAT return — the head of a view: «MWST-Abrechnung 3. Quartal 2026» with its state, under it
 * one muted line: the period, the method, the form edition and the declaration note «Deklaration
 * netto (ohne MWST)» (review T2 — the portal asks it first). A partial of the three views and the
 * entries list; it is the title of the card it opens (`$title` names the view's own card).
 *
 * @var \Z77\Module\Financial\VatReturn\VatReturnPreview $preview
 * @var string $periodLabel
 * @var string $title   the card's title — '' = «MWST-Abrechnung …»
 */
$title = ($title ?? '') !== '' ? $title : 'MWST-Abrechnung ' . $periodLabel;
?>
<div class="be-list__section-header">
    <h2 class="be-list__section-title"><?= e($title) ?></h2>
    <span class="badge badge--primary" data-vat-return-state="<?= e($preview->state) ?>"><?= e($preview->stateLabel()) ?></span>
</div>
<p class="be-list__section-hint" data-vat-return-facts>
    <?= e(\Z77\Module\Financial\Ui\VatReturnLayout::periodSpan($preview->period)) ?><?= $preview->period->short ? ' (verkürzt)' : '' ?>
    · <?= e($preview->methodLabel) ?>
    · Formular <?= e($preview->edition) ?>
    · <strong><?= e(\Z77\Module\Financial\VatReturn\VatReturnPreview::DECLARATION_NOTE) ?></strong>
</p>
