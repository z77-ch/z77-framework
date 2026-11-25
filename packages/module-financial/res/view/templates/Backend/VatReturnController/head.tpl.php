<?php
/**
 * One VAT return — the head of a view: «MWST-Abrechnung 3. Quartal 2026» with its state, under it
 * one muted line: the period, the method, the form edition and the declaration note «Deklaration
 * netto (ohne MWST)» (review T2 — the portal asks it first). A partial of the views and the entries
 * list; it is the title of the card it opens (`$title` names the view's own card).
 *
 * A STORED return (`$stored`, owner decision 6 of 2026-11-25) takes its facts from the row — the
 * method and edition it was computed with — and adds the line «Gebucht … von … · Buchung 2026/n»
 * («keine Buchung (Nullabrechnung)»), from revision 2 «Fassung n»; a lifted revision a third line
 * «Aufgehoben … von … · Storno 2026/n · Grund: …».
 *
 * @var ?\Z77\Module\Financial\VatReturn\VatReturnPreview $preview  the computed return (null for a stored revision)
 * @var ?\Z77\Module\Financial\Entities\VatReturn $stored            the posted / lifted return, or null
 * @var array{method: string, entry: string, reversal: string} $storedFacts
 * @var string $periodLabel
 * @var string $title   the card's title — '' = «MWST-Abrechnung …»
 */
use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Financial\VatReturn\VatReturnPreview;

$stored = $stored ?? null;
$title  = ($title ?? '') !== '' ? $title : 'MWST-Abrechnung ' . $periodLabel;
if ($stored !== null) {
    $period  = $stored->period();
    $state   = $stored->isLive() ? 'posted' : 'lifted';
    $method  = $storedFacts['method'];
    $edition = $stored->getFormEdition();
} else {
    $period  = $preview->period;
    $state   = $preview->state === VatReturnPreview::STATE_POSTED ? 'posted' : 'open';
    $method  = $preview->methodLabel;
    $edition = $preview->edition;
}
?>
<div class="be-list__section-header">
    <h2 class="be-list__section-title"><?= e($title) ?></h2>
    <span class="badge <?= $state === 'posted' ? 'badge--success' : ($state === 'lifted' ? 'badge--muted' : 'badge--primary') ?>" data-vat-return-state="<?= e($state) ?>"><?= e(VatReturnLayout::STATE_LABELS[$state]) ?></span>
</div>
<p class="be-list__section-hint" data-vat-return-facts>
    <?= e(VatReturnLayout::periodSpan($period)) ?><?= $period->short ? ' (verkürzt)' : '' ?>
    · <?= e($method) ?>
    · Formular <?= e($edition) ?>
    · <strong><?= e(VatReturnPreview::DECLARATION_NOTE) ?></strong>
</p>
<?php if ($stored !== null): ?>
<p class="be-list__section-hint" data-vat-return-posted>
    Gebucht <?= e($stored->getPostedAt()->format('d.m.Y H:i')) ?> von <?= e($stored->getPostedBy()) ?>
    · <?= $storedFacts['entry'] === '' ? 'keine Buchung (Nullabrechnung)' : 'Buchung ' . e($storedFacts['entry']) ?>
    <?= $stored->getRevision() > 1 ? '· Fassung ' . (int) $stored->getRevision() : '' ?>
</p>
<?php if (!$stored->isLive()): ?>
<p class="be-list__section-hint" data-vat-return-lifted>
    Aufgehoben <?= e((string) $stored->getLiftedAt()?->format('d.m.Y H:i')) ?> von <?= e((string) $stored->getLiftedBy()) ?>
    <?= $storedFacts['reversal'] === '' ? '' : '· Storno ' . e($storedFacts['reversal']) ?>
    · Grund: <?= e((string) $stored->getLiftReason()) ?>
</p>
<?php endif; ?>
<?php endif; ?>
