<?php
/**
 * «Aufteilen …» (owner decision E12) — loaded into the receipt window (`data-window-link`; mask
 * `receipt-split`, record `receipt:<id>`): a receipt with two VAT rates becomes two receipts. One
 * field — the amount of the NEW part; the receipt keeps the rest. The new part shares photo, date,
 * text and «Bezahlt mit»; Soll and MWST are set on it afterwards. Action row first (ADR-049),
 * «Zurück» to the receipt.
 *
 * @var \Z77\Module\Receipt\Entities\Receipt $receipt
 * @var string $amount
 * @var array<string, string> $errors
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$window     = !empty($window);
$id         = (int) $receipt->getId();
$title      = $receipt->getReceiptDate()->format('d.m.Y') . ' · aufteilen';
$winAttr    = $window ? ' data-window="receipt-split" data-window-entity="receipt:' . $id . '" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
$error      = $errors['amount'] ?? '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/split?id=' . $id) ?>" class="be-list__section" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="version" value="<?= (int) $receipt->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Aufteilen',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
            'errors'     => ['count' => $error !== '' ? 1 : 0, 'target' => 'receipt-split-amount'],
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Beleg</span><span class="be-list__cell"><?= e($receipt->getText() !== '' ? $receipt->getText() : 'ohne Text') ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Betrag</span><span class="be-list__cell be-list__cell--mono"><?= e($fmt($receipt->getAmount())) ?></span></div></div>
        </div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="receipt-split-amount">Betrag des neuen Teils</label>
                <input type="text" id="receipt-split-amount" name="amount" value="<?= e($amount) ?>" inputmode="decimal" required autofocus data-help-key="split" aria-invalid="<?= $error !== '' ? 'true' : 'false' ?>">
                <?php if ($error !== ''): ?><small class="be-form__field-error" data-z77-field-error><?= e($error) ?></small><?php endif; ?>
            </div>
        </div>
        <p class="be-form__hint">Der Beleg behält den Rest. Der neue Teil steht danach als eigener Beleg im Journal — mit demselben Foto, Datum, Text und «Bezahlt mit»; Soll und MWST-Code dort ergänzen.</p>
    </form>
</div>
