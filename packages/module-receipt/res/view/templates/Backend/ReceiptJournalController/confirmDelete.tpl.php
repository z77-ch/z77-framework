<?php
/**
 * «Löschen …» — loaded into the receipt window (`data-window-link`; mask `receipt-delete`, record
 * `receipt:<id>`): date, text, amount, who captured it; then the switch «Foto auch löschen» — ONLY
 * when the photo would be left without a receipt (no split part names it, E12) and the session may
 * manage it in the DMS. Off by default: the journal keeps photos (receipt.md); on, the photo goes to
 * the DMS trash (restorable there). Action row first (ADR-049), the delete in the danger look.
 *
 * @var \Z77\Module\Receipt\Entities\Receipt $receipt
 * @var array<string, mixed> $photo  ReceiptPhoto
 * @var bool $offerPhoto
 * @var bool $sharedPhoto
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$window     = !empty($window);
$id         = (int) $receipt->getId();
$title      = $receipt->getReceiptDate()->format('d.m.Y') . ' · löschen';
$winAttr    = $window ? ' data-window="receipt-delete" data-window-entity="receipt:' . $id . '" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-delete?id=' . $id) ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="version" value="<?= (int) $receipt->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Beleg löschen',
            'kind'       => 'danger',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Text</span><span class="be-list__cell"><?= e($receipt->getText() !== '' ? $receipt->getText() : 'ohne Text') ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Betrag</span><span class="be-list__cell be-list__cell--mono"><?= e($fmt($receipt->getAmount())) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Erfasst von</span><span class="be-list__cell"><?= e($receipt->capturedByLabel()) ?></span></div></div>
        </div>
        <?php if ($offerPhoto): ?>
        <div class="be-form__field" data-z77-field-wrapper>
            <label class="be-switch">
                <input type="checkbox" class="be-switch__input" id="receipt-delete-photo" name="delete_photo" value="1" data-help-key="delete_photo">
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                <span class="be-switch__label">Foto auch löschen (<?= e($photo['name']) ?>)</span>
            </label>
        </div>
        <p class="be-form__hint">Ohne den Schalter bleibt das Foto unter «Belege» in den Dokumenten. Gelöscht liegt es im Papierkorb der Dokumente und lässt sich dort wiederherstellen.</p>
        <?php elseif ($sharedPhoto): ?>
        <p class="be-form__hint">Das Foto bleibt — ein aufgeteilter Teil dieses Belegs braucht es noch.</p>
        <?php else: ?>
        <p class="be-form__hint">Das Foto bleibt unter «Belege» in den Dokumenten.</p>
        <?php endif; ?>
    </form>
</div>
