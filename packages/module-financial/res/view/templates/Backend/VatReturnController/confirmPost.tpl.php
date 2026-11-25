<?php
/**
 * The window «MWST buchen» (owner decisions 7 and 8 of 2026-11-25; the fiscal-year close's confirm
 * as the pattern): the action row on top — «MWST jetzt buchen» green first (no check mark),
 * «Schliessen» right —, then the two hint lines (ePortal first; the lock after posting), the
 * period, the payable amount, the rounding, the warnings with ONE mandatory tick «Warnungen
 * gelesen — trotzdem buchen» (no warning → no tick), and the settlement entry line by line through
 * the shared `entrySnapshot` partial. A NIL return: the same window, «Zahllast 0.00», «Buchung:
 * keine». The hidden fields carry the period (from / to / rhythm) and BOTH fingerprints of the plan
 * — `VatReturnPostingService::post()` computes the plan again under its locks and refuses when it
 * differs. A refusal at the click lands in `[data-vat-post-refusal]` (the window stays).
 *
 * Blocked (something blocking came up since the page was drawn) or refused → «Buchen nicht
 * möglich», the reason, only «Schliessen».
 *
 * A return that carries a block of correction entries (board S3-2, part 2): the first hint names
 * them («… und die Korrekturen früherer Perioden melden …»), one more line «Korrekturen früherer
 * Perioden» — the tax the settlement entry moves onto the settlement account for them, owed to the
 * ESTV or due from it, the corrected periods, the exact value small beneath (B-2) — and the entry's
 * correction lines «Korrektur {Periode}» stand in the snapshot.
 *
 * @var ?\Z77\Module\Financial\VatReturn\VatPeriod $period
 * @var string $periodLabel
 * @var ?\Z77\Module\Financial\VatReturn\VatSettlementPlan $plan
 * @var ?\Z77\Module\Financial\VatReturn\VatReturnRefusal $refusal
 * @var list<\Z77\Module\Financial\VatReturn\VatReturnFinding> $blocking
 * @var list<\Z77\Module\Financial\VatReturn\VatReturnFinding> $warnings
 * @var array{lines: list<array<string, string>>} $snapshot  the settlement entry's lines
 * @var array<string, string> $accounts  account number → name
 * @var string $methodLabel
 * @var string $entityCsrf
 * @var string $base
 * @var callable $fmt
 */
use Z77\Module\Financial\Ui\VatReturnLayout;
use Z77\Module\Financial\VatReturn\VatReturnPostingService;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\Money;

$ns = 'Z77\\Module\\Financial';

if ($plan === null || $blocking !== []): ?>
<div class="be-actions" data-vat-post-window="refused">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Buchen nicht möglich</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if ($refusal !== null): ?>
        <div class="be-modal__alert be-modal__alert--error" data-vat-post-refused="<?= e($refusal->reason) ?>"><?= e($refusal->message) ?></div>
        <?php else: ?>
        <div class="be-modal__alert be-modal__alert--error" data-vat-post-refused="blocked"><?= e($periodLabel) ?>: es ist noch etwas offen, das das Buchen verhindert.</div>
        <ul data-vat-post-blocking>
            <?php foreach ($blocking as $finding): ?>
            <li><?= e($finding->message) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php return; endif; ?>
<?php
$payable  = $plan->payable();
$rounding = $plan->roundingDifference();
$roles    = array_column($plan->lines, 'account', 'role');
$credit   = $payable->isNegative();
// The block: its tax on the settlement account (owed positive), the exact payable difference, the periods.
$blockTax   = null;
$blockExact = null;
foreach ($plan->corrections as $block) {
    $blockTax = ($blockTax ?? Money::zero($payable->currency))->add($block->payableTax($payable->currency));
    foreach ($block->rows as $row) {
        if ($row['kind'] === FieldKind::Payable) {
            $blockExact = $blockExact === null ? $row['correction'] : $blockExact->add($row['correction']);
        }
    }
}
$blockPeriods = implode(', ', array_map(static fn($b): string => $b->period->label(), $plan->corrections));
// The settlement account of the block's lines: the payable's, else the last correction line (the block clears onto it last).
$correctionLines = array_values(array_filter($plan->lines, static fn(array $l): bool => $l['role'] === VatReturnPostingService::ROLE_CORRECTION));
$blockAccount    = $roles[VatReturnPostingService::ROLE_SETTLEMENT] ?? ($correctionLines === [] ? '' : $correctionLines[count($correctionLines) - 1]['account']);
?>
<form data-fetch-post="<?= e($base) ?>/post" data-vat-post-window="<?= $plan->isNil() ? 'nil' : 'entry' ?>">
    <input type="hidden" name="from" value="<?= e($period->from->format('Y-m-d')) ?>">
    <input type="hidden" name="to" value="<?= e($period->to->format('Y-m-d')) ?>">
    <input type="hidden" name="rhythm" value="<?= e($period->rhythm->value) ?>">
    <input type="hidden" name="figures_fingerprint" value="<?= e($plan->figuresFingerprint) ?>">
    <input type="hidden" name="warnings_fingerprint" value="<?= e((string) $plan->warningsFingerprint) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">MWST-Abrechnung <?= e($periodLabel) ?> buchen</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'MWST jetzt buchen', 'kind' => 'confirm'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div data-vat-post-refusal></div>
        <p class="be-form__hint"><?= $plan->corrections === [] ? 'Zuerst im ePortal einreichen, dann hier buchen. Gebucht heisst eingereicht.' : 'Zuerst im ePortal einreichen und die Korrekturen früherer Perioden melden, dann hier buchen. Gebucht heisst eingereicht.' ?></p>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(0, 1fr)" data-vat-post-facts>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Periode</span><span class="be-list__cell"><?= e($period->from->format('d.m.Y') . ' – ' . $period->to->format('d.m.Y')) ?> · <?= e($methodLabel) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted"><?= $credit ? 'Guthaben' : 'Zahllast' ?></span><span class="be-list__cell" data-vat-post-payable><strong><?= e($fmt($credit ? $payable->negate() : $payable)) ?></strong><?php if (!$payable->isZero() && ($roles[VatReturnPostingService::ROLE_SETTLEMENT] ?? '') !== ''): ?> <span class="be-list__cell--muted">· auf <?= e($roles[VatReturnPostingService::ROLE_SETTLEMENT] . ' ' . ($accounts[$roles[VatReturnPostingService::ROLE_SETTLEMENT]] ?? '')) ?></span><?php endif; ?></span></div></div>
            <?php if ($blockTax !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Korrekturen früherer Perioden</span><span class="be-list__cell" data-vat-post-corrections><strong><?= e($fmt($blockTax->isNegative() ? $blockTax->negate() : $blockTax)) ?></strong> <?= $blockTax->isNegative() ? 'von der ESTV' : 'an die ESTV' ?><?= $blockAccount === '' ? '' : ', auf ' . e($blockAccount) ?> — <?= e($blockPeriods) ?><?php if ($blockExact !== null): ?><br><small class="be-list__cell--muted" data-vat-exact><?= e(VatReturnLayout::exact($blockExact)) ?></small><?php endif; ?></span></div></div>
            <?php endif; ?>
            <?php if (!$rounding->isZero()): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Rundung</span><span class="be-list__cell" data-vat-post-rounding><?= e($fmt($rounding)) ?><?php if (($roles[VatReturnPostingService::ROLE_ROUNDING] ?? '') !== ''): ?> <span class="be-list__cell--muted">· auf <?= e($roles[VatReturnPostingService::ROLE_ROUNDING] . ' ' . ($accounts[$roles[VatReturnPostingService::ROLE_ROUNDING]] ?? '')) ?></span><?php endif; ?></span></div></div>
            <?php endif; ?>
            <?php if ($plan->isNil()): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Buchung</span><span class="be-list__cell" data-vat-post-entry="none">keine — es gibt nichts auszugleichen</span></div></div>
            <?php else: ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Buchung</span><span class="be-list__cell" data-vat-post-entry><?= e($plan->settlement->date->format('d.m.Y')) ?> · Nummer beim Buchen · «<?= e($plan->settlement->text) ?>»</span></div></div>
            <?php endif; ?>
        </div>
        <?php if ($warnings !== []): ?>
        <p><strong>Warnungen (<?= count($warnings) ?>)</strong></p>
        <ul data-vat-post-warnings>
            <?php foreach ($warnings as $finding): ?>
            <li><?= e($finding->message) ?></li>
            <?php endforeach; ?>
        </ul>
        <label class="be-choice">
            <input type="checkbox" class="be-choice__input" name="confirm_warnings" value="1" required>
            <span class="be-choice__label">Warnungen gelesen — trotzdem buchen</span>
        </label>
        <?php endif; ?>
        <?php if (!$plan->isNil()): ?>
        <?= $this->partial('Backend/partials/entrySnapshot', ['snapshot' => $snapshot], $ns) ?>
        <?php endif; ?>
        <p class="be-form__hint">Danach sind Buchungen mit MWST-Code vom <?= e($period->from->format('d.m.')) ?> bis <?= e($period->to->format('d.m.Y')) ?> gesperrt.</p>
    </div>
</form>
