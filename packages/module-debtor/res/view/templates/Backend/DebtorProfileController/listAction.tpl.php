<?php
/**
 * Debtors (plan §6.1) — the receivables side of a contact. The list is
 * CONTACT-oriented on purpose: a debtor profile has no life of its own, and
 * mounting it into the contact screen would make module-contact know a
 * module it must not (plan §2). Search and limit are the contact list's
 * (`?q=`, contactConfig `contactListLimit`).
 *
 * A contact with a profile shows its payment terms, the dunning block and
 * the active switch — there is no delete (ADR-043 decision 19 / plan §6.1:
 * deactivate). A contact without one shows «kein Debitor» and no button:
 * a profile is opened with «+ Debitor» in the action cell (`act.tpl.php`,
 * 2026-10-08), whose dialog offers only ACTIVE contacts without a profile —
 * a new reference needs an active row, here an active party. An existing
 * profile on a contact deactivated since stays editable.
 *
 * Above the list stand the account settings (the mandator's debtor
 * accounts, `DebtorAccounts::status()`) with the refusal each would raise,
 * so a wrong account is found here and not when an invoice is posted. They
 * are edited on the mandator screen — the panel only shows them.
 *
 * A contact row is `_row` — the same partial a save or a switch answers
 * with in place (`replaceRow('debtorContact', …)`, ADR-047 addendum
 * 2026-10-10).
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
 * @var string|null $accountsNotice  the red band while the account settings cannot be read at all (leftover config key, mandator unavailable)
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/debtor-profile';
$shown      = count($contacts);
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Konteneinstellungen</h2>
            <span class="be-list__section-badge"><?= count($accounts) ?></span>
        </div>
        <?php if (!empty($accountsNotice)): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($accountsNotice) ?></div>
        <?php endif; ?>
        <div class="be-tree be-tree--hub be-tree--lead-switch">
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
        <div class="be-tree be-tree--hub be-tree--lead-switch">
            <?php if ($contacts === []): ?>
            <p class="be-list__empty"><?= $query === '' ? 'Keine Kontakte vorhanden.' : 'Kein Kontakt passt zu «' . e($query) . '».' ?></p>
            <?php endif; ?>
            <?php foreach ($contacts as $contact): ?>
            <?= $this->partial('Backend/DebtorProfileController/_row', [
                'contact'     => $contact,
                'profile'     => $profilesByContact[$contact->getId()] ?? null,
                'termsByCode' => $termsByCode,
                'actionBase'  => $actionBase,
            ], 'Z77\\Module\\Debtor') ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
