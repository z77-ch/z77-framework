<?php
/**
 * «+ Kontakt» of the invoice editor (DEBTOR-CONTACT-001, owner 2026-10-10) — a new contact
 * with its first address. Fetched, a WINDOW over the editor (ADR-047: mask
 * `invoice-new-contact`, no record yet); the form carries `_origin` (the editor's window)
 * back, so the save can put the re-rendered debtor select into THAT window. The fields are
 * module-contact's partials (`_contactFields`, `_addressFields` — variables from
 * `ContactForm::vars()`), the address required: debtor phrases no contact field itself.
 * Without the script the same markup is a page.
 *
 * Action row first, directly under the title bar (ADR-049 rev. 2026-10-10): Speichern ·
 * Abbrechen (closes this window — the editor behind keeps what was typed).
 *
 * @var \Z77\Module\Contact\Entities\Contact $entry
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var array<string,string> $kindLabels
 * @var list<string> $languages
 * @var \Z77\Module\Contact\Entities\Address $address
 * @var string $typeCode
 * @var string $linkTitle
 * @var list<\Z77\Module\Contact\Entities\AddressType> $types
 * @var \Z77\Module\Contact\Validators\ContactAddressValidator|null $linkValidator
 * @var \Z77\Module\Contact\Validators\AddressValidator|null $addressValidator
 * @var bool   $required  always true here — a document needs an address
 * @var bool   $failed    the save was refused — the markers name the fields
 * @var string $csrfToken provided by html()
 * @var string $actionBase
 * @var bool   $window    fetched as a window (ADR-047)
 * @var string $origin    where the window came from (WindowOrigin) — travels back as `_origin`
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$window     = !empty($window);
$title      = 'Kontakt erfassen';
$contactNs  = 'Z77\\Module\\Contact';
$winAttr    = $window ? ' data-window="invoice-new-contact" data-window-title="' . e($title) . '"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/new-contact') ?>" class="be-list__section" id="invoice-new-contact" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>

        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Speichern',
            // In a window: no href — «Abbrechen» closes it. As a page: back to a new invoice.
            'cancelHref' => $window ? '' : $actionBase . '/add',
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>
        <?php endif; ?>

        <?php if (!empty($failed)): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>

        <?= raw($this->partial('Backend/ContactController/_contactFields', [
            'entry'      => $entry,
            'validator'  => $validator,
            'kindLabels' => $kindLabels,
            'languages'  => $languages,
        ], $contactNs)) ?>
        <div class="be-form__section">Adresse</div>
        <?= raw($this->partial('Backend/ContactController/_addressFields', [
            'address'          => $address,
            'typeCode'         => $typeCode,
            'linkTitle'        => $linkTitle,
            'types'            => $types,
            'linkValidator'    => $linkValidator,
            'addressValidator' => $addressValidator,
            'required'         => true,
        ], $contactNs)) ?>
    </form>
</div>
