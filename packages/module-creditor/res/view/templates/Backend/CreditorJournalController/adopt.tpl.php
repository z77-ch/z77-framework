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
 * NO DUPLICATE CONTACT (owner 2026-11-16, CREDITOR-ADOPT-DUP-001): `$same` lists the existing
 * contacts a new one would duplicate (the creditor with the proposal's UID, the same name) — then
 * the existing one is pre-selected and named above both forms, and the new form carries the
 * checkbox `confirm_new` («trotzdem neu anlegen»); without it the server creates nothing (`$held`).
 * The adoption also names the creditor on every other invoice of the same party.
 *
 * @var array<string, string> $gaps  field → label the proposal lacks (street, zip, city)
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice $invoice
 * @var array<string, string> $proposal
 * @var string $query
 * @var list<\Z77\Module\Contact\Entities\Contact> $matches
 * @var string $error
 * @var bool   $failed  the new contact was refused — the markers name the fields
 * @var string $mode    `existing` | `new` — which way is offered first
 * @var int    $selected the contact pre-selected under «Bestehenden Kontakt verwenden» (0 = none)
 * @var list<\Z77\Module\Contact\Entities\Contact> $same  existing contacts a new one would duplicate
 * @var bool   $held    «Neuen Kontakt anlegen» was held back: the same contact exists, not confirmed
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
$same       = $same ?? [];
$selected   = (int) ($selected ?? 0);
$sameName   = $same !== [] ? $same[0]->displayName() : '';
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
        <?php if ($same !== []): ?>
        <div class="be-modal__alert<?= !empty($held) ? ' be-modal__alert--error' : '' ?>" data-adopt-existing="<?= (int) $same[0]->getId() ?>">
            <strong><?= e($sameName) ?> gibt es schon — diesen verwenden?</strong>
            <?= !empty($held) ? 'Es wurde kein neuer Kontakt angelegt.' : '' ?>
            Er ist unten unter «Bestehenden Kontakt verwenden» gewählt. Nur wenn es wirklich ein anderer ist: unten «Trotzdem einen neuen Kontakt anlegen» ankreuzen.
        </div>
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
                    <option value="<?= (int) $contact->getId() ?>"<?= (int) $contact->getId() === $selected ? ' selected' : '' ?>><?= e($contact->displayName()) ?><?= $contact->isActive() ? '' : ' (inaktiv)' ?></option>
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
            <?php if ($same !== []): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label class="be-switch">
                    <input type="checkbox" class="be-switch__input" id="ca-confirm-new" name="confirm_new" value="1" data-help-key="adopt">
                    <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    <span class="be-switch__label">Trotzdem einen neuen Kontakt anlegen — «<?= e($sameName) ?>» gibt es schon</span>
                </label>
            </div>
            <?php endif; ?>
            <button type="submit" class="be-btn be-btn--primary">Kontakt anlegen und als Kreditor übernehmen</button>
        </form>
    </div>
</div>
