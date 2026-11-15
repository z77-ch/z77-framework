<?php
/**
 * «Kreditor übernehmen …» (creditor plan §5), loaded into the invoice window: the PROPOSED creditor
 * (stage 2 fills it) becomes a creditor — either an EXISTING contact (found by name; the search is
 * a GET form inside the window, `data-window-form`) or a NEW one (module-contact's partials
 * `_contactFields` + `_addressFields`, prefilled from the proposal; the address optional). The save
 * opens the creditor profile when the contact is none yet, adds the proposed IBAN / UID, and the
 * invoice names it. Two forms, one per way; each carries the version shown.
 *
 * An INCOMPLETE proposal (a QR-bill without street — the standard allows it —, an AI answer) never
 * fails silently: `$gaps` names what a new contact still needs, the fields are marked, the form is
 * prefilled with what is there. A refused contact names every field in `$error`; a contact created
 * whose adoption was refused stays and is offered as an existing one (never created twice).
 *
 * @var array<string, string> $gaps  field → label the proposal lacks (street, zip, city)
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice $invoice
 * @var array<string, string> $proposal
 * @var string $query
 * @var list<\Z77\Module\Contact\Entities\Contact> $matches
 * @var string $error
 * @var bool   $failed  the new contact was refused — the markers name the fields
 * @var string $mode    `existing` | `new` — which way was posted
 * @var \Z77\Module\Contact\Entities\Contact $entry  (ContactForm::vars())
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var array<string,string> $kindLabels
 * @var list<string> $languages
 * @var \Z77\Module\Contact\Entities\Address $address
 * @var string $typeCode
 * @var string $linkTitle
 * @var list<\Z77\Module\Contact\Entities\AddressType> $types
 * @var \Z77\Module\Contact\Validators\ContactAddressValidator|null $linkValidator
 * @var \Z77\Module\Contact\Validators\AddressValidator|null $addressValidator
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$id         = (int) $invoice->getId();
$title      = 'Kreditor übernehmen · ' . ($proposal['name'] ?? $invoice->label());
$contactNs  = 'Z77\Module\Contact';
$winAttr    = $window ? ' data-window="creditor-invoice-adopt" data-window-entity="creditorInvoice:' . $id . '" data-window-title="' . e($title) . '" data-window-width="52rem"' : '';
$hidden     = '<input type="hidden" name="csrf_token" value="' . e($csrfToken ?? '') . '">'
    . ($window ? '<input type="hidden" name="_origin" value="' . e($origin ?? 'page') . '">' : '')
    . '<input type="hidden" name="version" value="' . (int) $invoice->getVersion() . '">';
$place      = trim(($proposal['street'] ?? '') . ' ' . ($proposal['house_no'] ?? '')) . ', ' . trim(($proposal['zip'] ?? '') . ' ' . ($proposal['city'] ?? ''));
?>
<div class="be-list"<?= $winAttr ?>>
    <div class="be-list__section">
        <?= $this->partial('partials/modalActions', [
            'submit'     => '',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>
        <p class="be-form__hint">Vorschlag: <strong><?= e($proposal['name'] ?? '') ?></strong> <?= e(trim($place, ', ')) ?><?= ($proposal['iban'] ?? '') !== '' ? ' · IBAN ' . e($proposal['iban']) : '' ?><?= ($proposal['uid'] ?? '') !== '' ? ' · ' . e($proposal['uid']) : '' ?></p>

        <h3 class="be-form__section">Bestehenden Kontakt verwenden</h3>
        <form method="get" action="<?= e($actionBase . '/adopt') ?>" data-window-form class="be-form__grid">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="ca-q">Kontakt suchen</label>
                <input type="search" id="ca-q" name="q" value="<?= e($query) ?>" data-help-key="adopt">
            </div>
            <div class="be-form__field"><button type="submit" class="be-btn be-btn--ghost">Suchen</button></div>
        </form>
        <form method="post" action="<?= e($actionBase . '/adopt?id=' . $id) ?>" autocomplete="off">
            <?= raw($hidden) ?>
            <input type="hidden" name="mode" value="existing">
            <?php if ($matches === []): ?>
            <p class="be-list__empty">Kein Kontakt gefunden — einen anderen Namen suchen oder unten einen neuen Kontakt anlegen.</p>
            <?php else: ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="ca-contact">Kontakt</label>
                <select id="ca-contact" name="contact_id" data-help-key="adopt">
                    <?php foreach ($matches as $contact): ?>
                    <option value="<?= (int) $contact->getId() ?>"><?= e($contact->displayName()) ?><?= $contact->isActive() ? '' : ' (inaktiv)' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="be-btn be-btn--primary">Diesen Kontakt als Kreditor übernehmen</button>
            <?php endif; ?>
        </form>

        <h3 class="be-form__section">Neuen Kontakt anlegen</h3>
        <form method="post" action="<?= e($actionBase . '/adopt?id=' . $id) ?>" id="creditor-adopt-new" autocomplete="off">
            <?= raw($hidden) ?>
            <input type="hidden" name="mode" value="new">
            <?php if (!empty($failed)): ?>
            <div class="be-modal__alert be-modal__alert--error">Bitte überprüfe die markierten Eingaben (oben steht, was fehlt).</div>
            <?php elseif (!empty($gaps)): ?>
            <div class="be-modal__alert" data-adopt-gaps="<?= e(implode(',', array_keys($gaps))) ?>">
                <strong>Der Vorschlag ist unvollständig — es fehlt: <?= e(implode(', ', $gaps)) ?>.</strong>
                Ergänze die markierten Felder (z.B. aus dem Briefkopf des PDF) und klick «Kontakt anlegen und als Kreditor übernehmen» — danach nennt die Rechnung den Kreditor.
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
                'required'         => false,
            ], $contactNs)) ?>
            <button type="submit" class="be-btn be-btn--primary">Kontakt anlegen und als Kreditor übernehmen</button>
        </form>
    </div>
</div>
