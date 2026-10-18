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
 * @var string $label    `CustomerPicker::label()` — '' without a customer (the title of the name)
 * @var string $name     the customer's display name, '' without a customer
 * @var string $note     «Kundennr. …» or «neu als Debitor», '' without a customer
 * @var string $pickUrl  the host's `customer-pick` URL
 * @var string $back     the editor's own URL (the picker's way back without the script)
 * @var bool   $invalid
 */
$href = $pickUrl . '?' . http_build_query(['back' => $back]);
?>
<?php // The look of the owner's draft (2026-10-17): the customer as TEXT — name, its number below —
      // and «Kunde wechseln» on the right; `#invoice-contact-name` stays the target of an error link. ?>
<span id="invoice-contact" class="be-customer">
    <input type="hidden" name="contact" value="<?= $contact !== null ? (int) $contact->getId() : '' ?>">
    <span class="be-customer__who">
        <?php if ($contact !== null): ?>
        <strong class="be-customer__name" id="invoice-contact-name" tabindex="-1" title="<?= e($label) ?>" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>"><?= e($name ?? $label) ?></strong>
        <?php if (($note ?? '') !== ''): ?><span class="be-customer__note"><?= e($note) ?></span><?php endif; ?>
        <?php else: ?>
        <span class="be-customer__none" id="invoice-contact-name" tabindex="-1" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">– noch kein Kunde gewählt –</span>
        <?php endif; ?>
    </span>
    <a class="be-customer__change" href="<?= e($href) ?>" data-window-open="<?= e($pickUrl) ?>"><?= $contact !== null ? 'Kunde wechseln' : 'Kunde wählen …' ?></a>
</span>
