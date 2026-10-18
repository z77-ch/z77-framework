<?php
/**
 * The delivery-address select of the order window — one partial for the window and for the
 * answer to a customer choice (owner 2026-10-15, usability review C2): a chosen customer
 * replaces it (`replace-html` on `#order-ship`, scoped to the order window) with THAT
 * customer's addresses, so the delivery address is chosen without saving first.
 *
 * Since 2026-10-17 the same select serves the BILLING address (`field` = 'bill', `#order-bill`,
 * `orderBillLabels()`) — one partial for both address cards.
 *
 * @var array<string, string> $ships     value → label (`OrderControllerTrait::orderShipLabels()` / `orderBillLabels()`)
 * @var string                $selected
 * @var bool                  $invalid
 * @var string                $field     'ship' (default) | 'bill'
 */
$field = ($field ?? 'ship') === 'bill' ? 'bill' : 'ship';
?>
<select class="be-input be-input--sm" id="order-<?= $field ?>" name="<?= $field ?>" aria-label="<?= $field === 'bill' ? 'Rechnungsadresse' : 'Lieferadresse' ?>" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
    <?php foreach ($ships as $value => $label): ?>
    <option value="<?= e((string) $value) ?>"<?= (string) $value === $selected ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
</select>
