<?php
/**
 * The window «Ablegen …» of the yearly overview (board S7-2 part 2; owner S7-1 option b, B-4, B-5):
 * the action row on top — «Ablegen» first, «Schliessen» right —, then what is stored (the tax year,
 * «Stand», the corrections, the fiscal year) and the one sentence what happens: the PDF goes
 * unchangeably into the archive and into the protocol «Abschlüsse und MWST», nothing is posted.
 * The POST carries the tax year and the fingerprint the window showed to
 * `YearOverviewStore::store()`; its refusals land in `[data-vat-year-store-refusal]` as they are.
 *
 * Not final, or unchanged since the newest storing → «Ablegen nicht möglich», the sentence, only
 * «Schliessen».
 *
 * @var ?\Z77\Module\Financial\VatReturn\VatYearOverview $overview
 * @var ?string $refusal
 * @var \DateTimeImmutable $stand
 * @var ?\DateTimeImmutable $closedAt
 * @var string $fingerprint
 * @var string $entityCsrf
 * @var string $base
 */
use Z77\Module\Financial\Ui\VatYearLayout;

if ($overview === null || $refusal !== null): ?>
<div class="be-actions" data-vat-year-store-window="refused">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Ablegen nicht möglich</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-modal__alert be-modal__alert--error" data-vat-year-store-refused><?= e((string) $refusal) ?></div>
    </div>
</div>
<?php return; endif; ?>
<?php
$year        = $overview->taxYear->year;
$corrections = count($overview->corrections);
?>
<form data-fetch-post="<?= e($base) ?>/store" data-vat-year-store-window="store">
    <input type="hidden" name="tax_year" value="<?= (int) $year ?>">
    <input type="hidden" name="fingerprint" value="<?= e($fingerprint) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= e(VatYearLayout::title($year)) ?> ablegen</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Ablegen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div data-vat-year-store-refusal></div>
        <div class="be-list__table" style="--be-list-cols: 9rem minmax(0, 1fr)" data-vat-year-store-facts>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Steuerjahr</span><span class="be-list__cell"><?= (int) $year ?> · <?= e(VatYearLayout::yearSpan($overview)) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Stand</span><span class="be-list__cell"><?= e($stand->format('d.m.Y H:i')) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Korrekturen</span><span class="be-list__cell"><?= $corrections === 0 ? 'keine' : $corrections . ($corrections === 1 ? ' Korrektur' : ' Korrekturen') ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Geschäftsjahr</span><span class="be-list__cell"><?= e(VatYearLayout::isCalendarYear($overview) ? VatYearLayout::yearEndState($overview, $closedAt) : VatYearLayout::fiscalYearsLabel($overview)) ?></span></div></div>
        </div>
        <p class="be-form__hint">Das PDF wird unveränderbar im Archiv abgelegt und im Protokoll «Abschlüsse und MWST» vermerkt. Es wird nichts gebucht.</p>
    </div>
</form>
