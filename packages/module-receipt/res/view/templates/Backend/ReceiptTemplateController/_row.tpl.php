<?php
/**
 * One template row of the standard list — the single place that renders it, for the list and for
 * the in-place answer of the switch (FETCH-ROW-001): the node carries
 * `data-entity="receiptTemplate:<id>"`, the address `FetchResponse::rowTarget()` builds.
 *
 *   - the state icon opens the template WINDOW (ADR-047; the origin is the list region);
 *   - Symbol (the name of its key, `ReceiptTemplate::ICONS`), Name, Code, Soll (number and name),
 *     MWST («ohne» = without VAT, a deliberate choice), Reihenfolge, Belege (receipts carrying it);
 *   - «Aktiv»: the inline switch (`data-fetch-toggle`); there is no delete (receipts keep the code).
 *
 * @var \Z77\Module\Receipt\Entities\ReceiptTemplate $row
 * @var int $usage
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var string $actionBase
 */
use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Module\Receipt\Ui\MasterDataListing;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::TEMPLATE_BASE;
$id         = (int) $row->getId();
$url        = $actionBase . '/edit?id=' . $id;
$active     = $row->isActive();
$state      = $active ? $row->getLabel() . ' öffnen — auf dem Handy sichtbar' : $row->getLabel() . ' öffnen — inaktiv, nicht mehr auf dem Handy';
$region     = MasterDataListing::templates()->region();
$icon       = $row->getIcon();
?>
                <div class="be-list__item" data-entity="receiptTemplate:<?= $id ?>">
                    <div class="be-list__row<?= $active ? '' : ' be-list__row--inactive' ?>">
                        <a class="be-list__cell be-list__state be-list__state--<?= $active ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($state) ?>" title="<?= e($state) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg></a>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3"><?= e($icon === '' ? 'Standard' : (ReceiptTemplate::ICONS[$icon] ?? $icon)) ?></span>
                        <strong class="be-list__cell"><?= e($row->getLabel()) ?><?= $active ? '' : ' <small class="be-list__cell--muted">· inaktiv</small>' ?></strong>
                        <span class="be-list__cell be-list__cell--mono be-list__cell--muted" data-priority="3"><?= e($row->getCode()) ?></span>
                        <span class="be-list__cell"><span class="be-list__cell--mono"><?= e($row->getDebitAccount()) ?></span> <span class="be-list__cell--muted"><?= e($choices->accountName($row->getDebitAccount())) ?></span></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= $row->isWithoutVat() ? '<span class="be-list__cell--muted">ohne</span>' : e($row->getTaxCode()) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= $row->getPosition() ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= (int) $usage ?></span>
                        <span class="be-list__cell be-list__cell--actions">
                            <label class="be-switch be-switch--sm" title="<?= $active ? 'Aktiv — eine Kachel auf dem Handy' : 'Inaktiv — Belege damit behalten sie' ?>">
                                <input type="checkbox" class="be-switch__input" aria-label="Aktiv"
                                       data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= $id ?>"<?= $active ? ' checked' : '' ?>>
                                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                            </label>
                        </span>
                    </div>
                </div>
