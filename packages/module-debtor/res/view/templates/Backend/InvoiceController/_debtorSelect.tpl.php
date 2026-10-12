<?php
/**
 * The party picker of a NEW invoice (owner 2026-10-10): the active debtors, then — in an
 * optgroup of their own — the active contacts WITHOUT a debtor profile; choosing one opens
 * its profile when the draft is saved (`InvoicingService::invoice(openProfile: true)`, which
 * decides again). One partial for both places that render it: the editor (`form`) and the
 * answer of «+ Kontakt» (`new-contact`), which REPLACES this select in the invoice window
 * (`replace-html` on `#invoice-contact`) with the new contact selected — the rest of the
 * half-filled draft stays as it is.
 *
 * @var list<\Z77\Module\Debtor\Entities\DebtorProfile> $debtors
 * @var list<\Z77\Module\Contact\Entities\Contact> $candidates  active contacts without a profile
 * @var string $selected  the chosen contact id ('' = none)
 * @var bool   $invalid   the field carries an error
 */
$option = static fn(\Z77\Module\Contact\Entities\Contact $contact, string $suffix): string =>
    '<option value="' . (int) $contact->getId() . '"' . ((string) $contact->getId() === $selected ? ' selected' : '') . '>'
    . e($contact->displayName() . $suffix) . '</option>';
?>
<select id="invoice-contact" name="contact" required aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
    <option value="">–</option>
    <?php if ($candidates === []): ?>
    <?php foreach ($debtors as $profile): ?>
    <?= raw($option($profile->getContact(), '')) ?>
    <?php endforeach; ?>
    <?php else: ?>
    <optgroup label="Debitoren">
        <?php foreach ($debtors as $profile): ?>
        <?= raw($option($profile->getContact(), '')) ?>
        <?php endforeach; ?>
    </optgroup>
    <optgroup label="Kontakte ohne Debitorenprofil">
        <?php foreach ($candidates as $contact): ?>
        <?= raw($option($contact, ' (neu als Debitor)')) ?>
        <?php endforeach; ?>
    </optgroup>
    <?php endif; ?>
</select>
