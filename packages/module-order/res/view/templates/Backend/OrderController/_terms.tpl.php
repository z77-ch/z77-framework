<?php
/**
 * The «Zahlungskonditionen» select of the order window, block (3) — debtor's `PaymentTerms` (the
 * days, «30 Tage netto»; owner 2026-10-18), not the bank account (that is «Zahlungsziel»). One
 * partial for the window and for the answer to a customer choice (`replace-html` on
 * the region `[data-fetch-region="order-terms"]`, `customerChosenCommands()`), which starts it at
 * «wie beim Kunden» again, and for `terms` (reloaded when «Debitor anpassen» closes).
 *
 * '' = «wie beim Kunden»: the booking print takes the customer's debtor terms — the option names
 * them ($customer), like the invoice editor's «wie beim Debitor».
 *
 * @var list<\Z77\Module\Debtor\Entities\PaymentTerms> $terms  the active ones plus the one the order holds
 * @var string $selected  the code, '' = the customer's
 * @var string $customer  what «wie beim Kunden» means now ('' = no customer yet)
 * @var bool   $invalid
 * @var string $src      the address the region reloads from (`terms?contact=…`; core.js adds the
 *                       current choice) — after «Debitor anpassen» (`data-popup-refresh`)
 */
?>
<div class="be-form__region" data-fetch-region="order-terms" data-fetch-region-src="<?= e($src) ?>">
<select id="order-terms" name="terms" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
    <option value="">wie beim Kunden<?= $customer !== '' ? ' — ' . e($customer) : '' ?></option>
    <?php foreach ($terms as $row): ?>
    <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $selected ? ' selected' : '' ?>><?= e($row->getLabel()) ?><?= $row->isActive() ? '' : ' (inaktiv)' ?></option>
    <?php endforeach; ?>
</select>
</div>
