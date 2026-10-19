<?php
/**
 * The title of the «Kunde» block of a document editor's head (owner's draft 2026-10-17; shared
 * since 2026-10-19 — the order window and the invoice editor): «KUNDE 20417» — the debtor
 * profile's customer number in the title — and the contact's phone muted beside it; without a
 * customer just «Kunde». The number lives here, not under the name
 * (`CustomerPicker::fieldHtml(..., showNumber: false)`). Rendered by the editor and by the answer
 * to «Kunde wechseln» (`replace-html` on `#{prefix}-customer-title`, scoped to the editor window —
 * `CustomerPickerTrait::customerChosenAnswer()`).
 *
 * @var string $prefix  the host's id prefix (`CustomerPickerTrait::customerHeadPrefix()`: 'order', 'invoice')
 * @var string $number  the customer number, '' without a customer or a debtor profile
 * @var string $phone   the contact's phone, '' without
 */
?>
<h3 class="be-list__section-title" id="<?= e($prefix) ?>-customer-title">Kunde<?php if ($number !== ''): ?> <?= e($number) ?><?php endif; ?><?php if ($phone !== ''): ?> <small><?= e($phone) ?></small><?php endif; ?></h3>
