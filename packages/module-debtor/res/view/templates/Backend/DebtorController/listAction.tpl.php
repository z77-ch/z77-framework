<?php
/**
 * Debtors (plan §6.1) — the receivables side of a contact. The list is
 * CONTACT-oriented on purpose: a debtor profile has no life of its own, and
 * mounting it into the contact screen would make module-contact know a
 * module it must not (plan §2). Search and limit are the contact list's
 * (`?q=`, contactConfig `contactListLimit`).
 *
 * An ACTIVE contact without a profile shows «Debitor anlegen»; one with a
 * profile shows its payment terms, the dunning block and the active switch
 * — there is no delete (ADR-043 decision 19 / plan §6.1: deactivate). An
 * INACTIVE contact without a profile gets no button: a new reference needs
 * an active row, here an active party. An existing profile on a contact
 * deactivated since stays editable.
 *
 * Above the list stand the account settings (`debtorConfig →
 * debtorAccounts`) with the refusal each would raise, so a wrong account is
 * found here and not when part 2 posts an invoice.
 *
 * Styling: the shared backend list/tree classes only.
 *
 * @var list<\Z77\Module\Contact\Entities\Contact> $contacts
 * @var array<int,\Z77\Module\Debtor\Entities\DebtorProfile> $profilesByContact  contact id → profile
 * @var array<string,\Z77\Module\Debtor\Entities\PaymentTerms> $termsByCode
 * @var int $total
 * @var int $limit
 * @var string $query
 * @var array<string,array{number: string, error: string|null}> $accounts
 * @var array<string,string> $accountLabels
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/debtor';
$shown      = count($contacts);
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Konteneinstellungen</h2>
            <span class="be-list__section-badge"><?= count($accounts) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php foreach ($accounts as $key => $row): ?>
            <div class="be-tree__node" style="--node-depth:0" data-debtor-account="<?= e($key) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name" data-field="label"><?= e($accountLabels[$key] ?? $key) ?></span>
                    <span class="be-tree__url" data-field="number">
                        <?php if ($row['number'] !== ''): ?>Konto <?= e($row['number']) ?><?php else: ?><span class="be-list__cell--muted">kein Konto hinterlegt</span><?php endif; ?>
                    </span>
                    <span class="be-tree__route" data-field="state">
                        <?php if ($row['error'] === null): ?>
                        <span class="badge badge--success">ok</span>
                        <?php else: ?>
                        <span class="badge badge--warning" title="<?= e($row['error']) ?>">prüfen</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php foreach ($accounts as $row): ?>
        <?php if ($row['error'] !== null): ?>
        <p class="be-form__hint"><?= e($row['error']) ?></p>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= $query === '' ? 'Kontakte' : 'Kontakte zu «' . e($query) . '»' ?></h2>
            <span class="be-list__section-badge"><?= $total ?></span>
        </div>
        <?php if ($shown < $total): ?>
        <p class="be-form__hint"><?= $shown ?> von <?= $total ?> angezeigt — die Suche grenzt ein.</p>
        <?php endif; ?>
        <div class="be-tree be-tree--hub">
            <?php if ($contacts === []): ?>
            <p class="be-list__empty"><?= $query === '' ? 'Keine Kontakte vorhanden.' : 'Kein Kontakt passt zu «' . e($query) . '».' ?></p>
            <?php endif; ?>
            <?php foreach ($contacts as $contact): ?>
            <?php $profile = $profilesByContact[$contact->getId()] ?? null; ?>
            <div class="be-tree__node<?= $profile === null || $profile->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-contact-id="<?= e((string) $contact->getId()) ?>">
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
                    <?php elseif ($contact->isActive()): ?>
                    <span class="be-tree__switch" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Debitor anlegen"
                            data-fetch-get="<?= e($actionBase) ?>/add?contact=<?= e((string) $contact->getId()) ?>">＋</button>
                    <?php else: ?>
                    <?php /* An inactive contact gets no debtor (ADR-043/19 on the party) — no button, and the server refuses a hand-built URL. */ ?>
                    <span class="be-tree__switch" aria-hidden="true"></span>
                    <span class="be-tree__menu" aria-hidden="true"></span>
                    <?php endif; ?>

                    <span class="be-tree__name" data-field="name">
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
            <?php endforeach; ?>
        </div>
    </div>
</div>
