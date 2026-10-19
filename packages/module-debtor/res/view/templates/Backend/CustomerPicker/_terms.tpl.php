<?php
/**
 * The «Zahlungskonditionen» select of a document editor's head — debtor's `PaymentTerms` (the
 * days, «30 Tage netto»; owner 2026-10-18), not the bank account («Zahlungsziel»). Shared since
 * 2026-10-19 (the order window and the invoice editor). One partial for the editor, for the
 * answer to a customer choice (`replace-html` on the region `[data-fetch-region="{prefix}-terms"]`,
 * `CustomerPickerTrait::customerChosenAnswer()`), which starts it at '' again, and for
 * `customer-terms` (reloaded when «Debitor anpassen» closes, `data-popup-refresh`).
 *
 * '' = the customer's: the document takes the debtor profile's terms — the first option names
 * them ($customer). Since 2026-10-19 there is no document for a contact without a debtor profile,
 * so there is no «neu als Debitor: …» case any more.
 *
 * @var string $prefix   the host's id prefix ('order', 'invoice') — select `#{prefix}-terms`, name `terms`
 * @var string $inherit  the first option's words («wie beim Kunden», «wie beim Debitor»)
 * @var list<\Z77\Module\Debtor\Entities\PaymentTerms> $terms  the active ones plus the one the document holds
 * @var string $selected  the code, '' = the customer's
 * @var string $customer  the customer's terms label ('' = no customer / no profile)
 * @var bool   $invalid
 * @var string $src       the address the region reloads from (`customer-terms?contact=…`; core.js adds the current choice)
 */
?>
<div class="be-form__region" data-fetch-region="<?= e($prefix) ?>-terms" data-fetch-region-src="<?= e($src) ?>">
<select id="<?= e($prefix) ?>-terms" name="terms" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
    <option value=""><?= e($inherit) ?><?= $customer !== '' ? ' — ' . e($customer) : '' ?></option>
    <?php foreach ($terms as $row): ?>
    <option value="<?= e($row->getCode()) ?>"<?= $row->getCode() === $selected ? ' selected' : '' ?>><?= e($row->getLabel()) ?><?= $row->isActive() ? '' : ' (inaktiv)' ?></option>
    <?php endforeach; ?>
</select>
</div>
