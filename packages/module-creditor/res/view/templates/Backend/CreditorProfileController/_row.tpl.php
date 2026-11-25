<?php
/**
 * One creditor row of the standard list — the single place that renders it, for the list and for
 * the in-place answer of the switch (FETCH-ROW-001): the node carries
 * `data-entity="creditorProfile:<id>"`.
 *
 *   - the state icon opens the WINDOW (ADR-047; the origin is the list region);
 *   - Kreditor (the contact's name), UID, IBAN (the first, «+ n» more; QR-IBAN marked), Konto, MWST
 *     («ohne» for «ohne MWST»), «Bezahlt mit» — the defaults, «—» while not decided;
 *   - «Rechnungen»: how many invoices of the Kreditorenjournal name it (a creditor with invoices is
 *     deactivated, never removed);
 *   - «Aktiv»: the inline switch (`data-fetch-toggle`). No delete here.
 *
 * @var \Z77\Module\Creditor\Entities\CreditorProfile $profile
 * @var list<\Z77\Module\Creditor\Entities\CreditorIban> $ibans
 * @var array<string, string> $methods  payment-method code → label
 * @var int $invoices  invoices naming it
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
use Z77\Shared\Payment\Iban;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::BASE;
$id         = (int) $profile->getId();
$url        = $actionBase . '/edit?id=' . $id;
$active     = $profile->isActive();
$name       = (string) $profile->getContact()?->displayName();
$title      = $name . ' öffnen' . ($profile->getDefaultsSetAt() === null ? ' — noch keine Standardwerte' : '');
$first      = $ibans[0] ?? null;
$method     = $profile->getDefaultPaymentMethodCode();
?>
                <div class="be-list__item" data-entity="creditorProfile:<?= $id ?>">
                    <div class="be-list__row<?= $active ? '' : ' be-list__row--inactive' ?>">
                        <a class="be-list__cell be-list__state be-list__state--<?= $active ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($definition->region()) ?>" aria-label="<?= e($title) ?>" title="<?= e($title) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg></a>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_name')) ?>"><strong><?= e($name) ?></strong><?= $active ? '' : ' <small class="be-list__cell--muted">· inaktiv</small>' ?></label>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_uid')) ?>" data-priority="2"><?= $profile->getUid() !== null ? e($profile->getUid()) : '<span class="be-list__cell--muted">—</span>' ?></label>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_iban')) ?>">
                            <?php if ($first === null): ?><span class="be-list__cell--muted">keine IBAN</span><?php else: ?><?= e(Iban::format($first->getIban())) ?><?= $first->isQrIban() ? ' <span class="badge badge--success">QR</span>' : '' ?><?= count($ibans) > 1 ? ' <small class="be-list__cell--muted">+ ' . (count($ibans) - 1) . '</small>' : '' ?><?php endif; ?>
                        </label>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= e($profile->getDefaultAccount() ?? '—') ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= $profile->isDefaultWithoutVat() ? '<span class="be-list__cell--muted">ohne</span>' : e($profile->getDefaultTaxCode() ?? '—') . ($profile->isDefaultTaxOnly() && $profile->getDefaultTaxCode() !== null ? ' <small class="be-list__cell--muted">nur Steuer</small>' : '') ?></span>
                        <span class="be-list__cell" data-priority="3"><?= $method !== null ? e($methods[$method] ?? $method) : '<span class="be-list__cell--muted">—</span>' ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="3"><?= (int) ($invoices ?? 0) ?></span>
                        <span class="be-list__cell be-list__cell--actions">
                            <label class="be-switch be-switch--sm" title="<?= $active ? 'Aktiv — wird für neue Rechnungen angeboten' : 'Inaktiv — bestehende Rechnungen behalten ihn' ?>">
                                <input type="checkbox" class="be-switch__input" aria-label="Aktiv"
                                       data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= $id ?>"<?= $active ? ' checked' : '' ?>>
                                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                            </label>
                        </span>
                    </div>
                </div>
