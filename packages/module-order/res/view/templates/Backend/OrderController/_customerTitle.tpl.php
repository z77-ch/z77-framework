<?php
/**
 * The title of the order window's block (1) «Kunde» (owner's draft 2026-10-17): «KUNDE 20417»
 * — the debtor profile's customer number in the title — and the contact's phone muted beside it;
 * without a customer (a new order) just «Kunde». The number lives here, not under the name
 * (`CustomerPicker::fieldHtml(..., showNumber: false)`). Rendered by `edit` and by the answer to
 * «Kunde wechseln» (`replace-html` on `#order-customer-title`, scoped to the order window).
 *
 * @var string $number  the customer number, '' without a customer or a debtor profile
 * @var string $phone   the contact's phone, '' without
 */
?>
<h3 class="be-list__section-title" id="order-customer-title">Kunde<?php if ($number !== ''): ?> <?= e($number) ?><?php endif; ?><?php if ($phone !== ''): ?> <small><?= e($phone) ?></small><?php endif; ?></h3>
