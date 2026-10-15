<?php
/**
 * The delivery-address select of the order window — one partial for the window and for the
 * answer to a customer choice (owner 2026-10-15, usability review C2): a chosen customer
 * replaces it (`replace-html` on `#order-ship`, scoped to the order window) with THAT
 * customer's addresses, so the delivery address is chosen without saving first.
 *
 * @var array<string, string> $ships     value → label (`OrderControllerTrait::orderShipLabels()`)
 * @var string                $selected
 * @var bool                  $invalid
 */
?>
<select id="order-ship" name="ship" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
    <?php foreach ($ships as $value => $label): ?>
    <option value="<?= e((string) $value) ?>"<?= (string) $value === $selected ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
</select>
