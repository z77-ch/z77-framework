<?php
/**
 * The window «Aufheben» — the emergency brake (owner decision 2 of 2026-11-25): ADMIN only (the
 * host's access config), only the LATEST posted return, one return per action, a mandatory reason
 * (at most 500 characters, kept whole in the protocol). The action row on top — «Aufheben»
 * (danger) first, «Schliessen» right —, then what is lifted (the return and its revision, the
 * settlement entry, the payable amount, the reversal's date), the reason, the reversal line by line
 * through the shared `entrySnapshot` partial (every line of the entry on its other side; none for a
 * nil return) and the one sentence what follows. The POST carries id + version + reason to
 * `VatReturnPostingService::lift()`; its refusals land in `[data-vat-lift-refusal]` as they are.
 *
 * Not the latest, or lifted already → «Aufheben nicht möglich», the sentence, only «Schliessen».
 *
 * @var ?\Z77\Module\Financial\Entities\VatReturn $return
 * @var ?string $refusal
 * @var string $periodLabel
 * @var string $entryRef      '' for a nil return
 * @var ?\Z77\Module\Financial\Entities\JournalEntry $entry
 * @var array{lines: list<array<string, string>>} $snapshot  the reversal's lines
 * @var int $reasonLength
 * @var string $entityCsrf
 * @var string $base
 * @var callable $fmt
 */
$ns = 'Z77\\Module\\Financial';

if ($return === null || $refusal !== null): ?>
<div class="be-actions" data-vat-lift-window="refused">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Aufheben nicht möglich</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-modal__alert be-modal__alert--error" data-vat-lift-refused><?= e((string) $refusal) ?></div>
    </div>
</div>
<?php return; endif; ?>
<form data-fetch-post="<?= e($base) ?>/lift" data-vat-lift-window="<?= $entry === null ? 'nil' : 'entry' ?>">
    <input type="hidden" name="id" value="<?= (int) $return->getId() ?>">
    <input type="hidden" name="version" value="<?= (int) $return->getVersion() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">MWST-Abrechnung <?= e($periodLabel) ?> aufheben</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Aufheben', 'kind' => 'danger'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div data-vat-lift-refusal></div>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(0, 1fr)" data-vat-lift-facts>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Abrechnung</span><span class="be-list__cell"><?= e($periodLabel) ?> · Fassung <?= (int) $return->getRevision() ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Buchung</span><span class="be-list__cell"><?= $entry === null ? 'keine (Nullabrechnung) — kein Storno' : 'Buchung ' . e($entryRef) . ' vom ' . e($entry->getDate()->format('d.m.Y')) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Zahllast</span><span class="be-list__cell"><?= e($fmt($return->getPayable())) ?></span></div></div>
            <?php if ($entry !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Storno (neu)</span><span class="be-list__cell" data-vat-lift-reversal><?= e($entry->getDate()->format('d.m.Y')) ?> · «Aufgehoben: <?= e($entry->getText()) ?> — Grund»</span></div></div>
            <?php endif; ?>
        </div>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="vat-return-lift-reason">Grund</label>
            <textarea id="vat-return-lift-reason" name="reason" class="be-form__control" rows="3" maxlength="<?= (int) $reasonLength ?>" required></textarea>
        </div>
        <?php if ($entry !== null): ?>
        <?= $this->partial('Backend/partials/entrySnapshot', ['snapshot' => $snapshot], $ns) ?>
        <?php endif; ?>
        <p class="be-form__hint">Danach ist die Periode wieder offen: Buchungen mit MWST-Code gehen dort wieder, und sie wird mit «MWST buchen …» neu gebucht.</p>
    </div>
</form>
