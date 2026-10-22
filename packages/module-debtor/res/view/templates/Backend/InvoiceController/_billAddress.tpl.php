<?php
/**
 * The body of the invoice editor's card «Rechnung» (owner 2026-10-19, the order window's head):
 * the address the save takes — the customer's invoice / main / first address as it is NOW
 * (`InvoicingService::invoiceAddressOf()`; a draft has no address choice of its own, every save
 * takes it again). Read-only — changed at the contact. One partial for the editor and for the
 * answer to «Kunde wechseln» (`replace-html` on `#invoice-bill-address`, `customerChosenCommands()`).
 *
 * @var bool         $chosen  a customer is chosen
 * @var list<string> $lines   its address lines ([] = none)
 * @var string       $contact the address's e-mail · phone (`AddressSnapshot::contactLine()`, '' = none) —
 *                            small and muted under the address: the document is e-mailed there (2026-10-22)
 */
$contact = $contact ?? '';
?>
<div id="invoice-bill-address">
    <?php if ($lines !== []): ?>
    <div class="be-form__address"><?php foreach ($lines as $line): ?><div><?= e($line) ?></div><?php endforeach; ?></div>
    <?php if ($contact !== ''): ?><div class="be-form__hint"><?= e($contact) ?></div><?php endif; ?>
    <?php elseif ($chosen): ?>
    <div class="be-form__fact">Der Kunde hat keine Adresse — beim Kontakt erfassen.</div>
    <?php else: ?>
    <div class="be-form__fact">Die Adresse des Kunden.</div>
    <?php endif; ?>
</div>
