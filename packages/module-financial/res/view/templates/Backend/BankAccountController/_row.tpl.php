<?php
/**
 * One bank-account row of the standard list — the single place that renders it, for the list and
 * for the in-place answer of the switch (FETCH-ROW-001): `data-entity="bankAccount:<id>"`.
 * State icon (opens the WINDOW, ADR-047) · Bezeichnung · IBAN · Konto · Kontoauszug («nein», «ja»
 * or «bis <date>») · «Aktiv» (`data-fetch-toggle`). There is no delete.
 *
 * @var \Z77\Module\Financial\Entities\BankAccount $row
 * @var string|null $covered  «eingelesen bis» (d.m.Y), null before the first statement
 * @var string $ledgerName
 * @var string $actionBase
 */
use Z77\Module\Financial\Ui\BankAccountListing;
use Z77\Shared\Payment\Iban;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::ACCOUNT_BASE;
$id         = (int) $row->getId();
$url        = $actionBase . '/edit?id=' . $id;
$active     = $row->isActive();
$title      = $row->getLabel() . ' öffnen';
$region     = BankAccountListing::definition()->region();
$statement  = !$row->isStatementImport() ? 'nein' : ($covered !== null ? 'bis ' . $covered : 'ja');
?>
                <div class="be-list__item" data-entity="bankAccount:<?= $id ?>">
                    <div class="be-list__row<?= $active ? '' : ' be-list__row--inactive' ?>">
                        <a class="be-list__cell be-list__state be-list__state--<?= $active ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($title) ?>" title="<?= e($title) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg></a>
                        <strong class="be-list__cell"><?= e($row->getLabel()) ?><?= $active ? '' : ' <small class="be-list__cell--muted">· inaktiv</small>' ?></strong>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= e(Iban::format($row->getIban())) ?></span>
                        <span class="be-list__cell"><span class="be-list__cell--mono"><?= e($row->getLedgerAccount()) ?></span> <span class="be-list__cell--muted"><?= e($ledgerName) ?></span></span>
                        <span class="be-list__cell<?= $row->isStatementImport() ? '' : ' be-list__cell--muted' ?>" data-priority="2"><?= e($statement) ?></span>
                        <span class="be-list__cell be-list__cell--actions">
                            <label class="be-switch be-switch--sm" title="Aktiv">
                                <input type="checkbox" class="be-switch__input" aria-label="Aktiv"
                                       data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= $id ?>"<?= $active ? ' checked' : '' ?>>
                                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                            </label>
                        </span>
                    </div>
                </div>
