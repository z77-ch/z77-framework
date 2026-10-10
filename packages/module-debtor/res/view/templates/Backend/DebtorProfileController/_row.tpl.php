<?php
/**
 * One contact row of the debtor master data (`.be-tree__node`) — the single place that
 * renders it, for the list and for the in-place answer of a save or a switch (ADR-047
 * addendum 2026-10-10). The row is the CONTACT's (the list shows contacts, with or without a
 * profile), so it carries `data-entity="debtorContact:<contact id>"`: creating a profile
 * replaces the same row as editing one. core.js wires the switch and the ⋮ of the HTML a
 * `replaceRow` inserts — the row that just gained a profile has live controls at once.
 *
 * @var \Z77\Module\Contact\Entities\Contact $contact
 * @var \Z77\Module\Debtor\Entities\DebtorProfile|null $profile
 * @var array<string,\Z77\Module\Debtor\Entities\PaymentTerms> $termsByCode
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/finance/debtor-profile';
$contactId  = (string) $contact->getId();
?>
            <div class="be-tree__node<?= $profile === null || $profile->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-contact-id="<?= e($contactId) ?>" data-entity="debtorContact:<?= e($contactId) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <?php if ($profile !== null): ?>
                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $profile->isActive() ? 'Aktiv — wird für neue Rechnungen angeboten' : 'Inaktiv — nur noch für bestehende Belege' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $profile->getId()) ?>"<?= $profile->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>
                    <button type="button" class="be-tree__menu" title="Debitor bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $profile->getId()) ?>">⋮</button>
                    <?php else: ?>
                    <?php /* No profile yet: «+ Debitor» in the action cell offers the active contacts without one
                             (2026-10-08) — the row carries no button of its own. An inactive contact gets no
                             debtor (ADR-043/19 on the party); the server refuses a hand-built URL. */ ?>
                    <span class="be-tree__switch" aria-hidden="true"></span>
                    <span class="be-tree__menu" aria-hidden="true"></span>
                    <?php endif; ?>

                    <span class="be-tree__name" data-field="name">
                        <?php if ($profile !== null): ?>
                        <small class="be-list__cell--muted" data-field="customer-number" title="Kundennummer — vergeben beim Anlegen, steht auf der QR-Referenz"><?= e((string) $profile->getCustomerNumber()) ?> ·</small>
                        <?php endif; ?>
                        <?= e($contact->displayName()) ?>
                        <small class="be-list__cell--muted">· <?= e(mb_strtoupper($contact->getLanguage())) ?></small>
                    </span>

                    <span class="be-tree__url" data-field="terms">
                        <?php if ($profile === null): ?>
                        <span class="be-list__cell--muted">kein Debitor</span>
                        <?php else: ?>
                        <?php $terms = $termsByCode[$profile->getPaymentTermsCode()] ?? null; ?>
                        <?= e($terms?->getLabel() ?? $profile->getPaymentTermsCode()) ?>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php if ($profile !== null && $profile->hasDunningBlock()): ?>
                        <span class="badge badge--warning" title="Kein Mahnlauf erfasst diesen Debitor">Mahnsperre</span>
                        <?php endif; ?>
                        <?php if ($profile !== null && !$profile->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                        <?php if (!$contact->isActive()): ?>
                        <span class="badge badge--muted">Kontakt inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
