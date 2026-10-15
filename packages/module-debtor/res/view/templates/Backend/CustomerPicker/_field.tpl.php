<?php
/**
 * The customer field of a NEW document (`Ui\CustomerPicker`, owner 2026-10-15): the chosen
 * customer as read-only text, its id in the hidden `contact`, and «Kunde wählen …» — a window
 * over the editor (`data-window-open`) with the searchable list; without the script a page
 * that returns to $back. The whole element is `#invoice-contact`: a choice in the picker and the
 * save of «+ Kontakt» replace it in the editor window (`replace-html`, scoped to the origin), the
 * rest of the half-filled document stays. The caller's `<label>` points at `#invoice-contact-name`.
 *
 * @var \Z77\Module\Contact\Entities\Contact|null $contact
 * @var string $label    `CustomerPicker::label()` — '' without a customer
 * @var string $pickUrl  the host's `customer-pick` URL
 * @var string $back     the editor's own URL (the picker's way back without the script)
 * @var bool   $invalid
 */
$href = $pickUrl . '?' . http_build_query(['back' => $back]);
?>
<span id="invoice-contact">
    <input type="hidden" name="contact" value="<?= $contact !== null ? (int) $contact->getId() : '' ?>">
    <input type="text" id="invoice-contact-name" value="<?= e($label) ?>" placeholder="– noch kein Kunde gewählt –" readonly aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
    <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($href) ?>" data-window-open="<?= e($pickUrl) ?>">Kunde wählen …</a>
</span>
