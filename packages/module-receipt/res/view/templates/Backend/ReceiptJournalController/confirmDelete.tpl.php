<?php
/**
 * «Löschen …» — loaded into the receipt window (`data-window-link`; mask `receipt-delete`, record
 * `receipt:<id>`): date, text, amount, who captured it; then what happens to the photo — it goes
 * to the DMS trash with the receipt (restorable there; owner 2026-11-20, the former switch «Foto
 * auch löschen» is gone), unless a split part still names it (E12), then it stays. Action row
 * first (ADR-049), the delete in the danger look.
 *
 * @var \Z77\Module\Receipt\Entities\Receipt $receipt
 * @var array<string, mixed> $photo  ReceiptPhoto
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
        <?php if ($sharedPhoto): ?>
        <p class="be-form__hint">Das Foto bleibt — ein aufgeteilter Teil dieses Belegs braucht es noch.</p>
        <?php else: ?>
        <p class="be-form__hint">Das Foto kommt in den Papierkorb der Dokumente (dort wiederherstellbar).</p>
        <?php endif; ?>
    </form>
</div>
