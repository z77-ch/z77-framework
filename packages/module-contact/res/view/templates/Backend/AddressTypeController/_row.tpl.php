<?php
/**
 * One address-type row of the list (`.be-tree__node`) — the single place that renders it: the
 * list loops over it, and a save answers with it (`FetchResponse::replaceRow()` / `insertRow()`,
 * ADR-047 addendum 2026-10-10), so list and answer cannot drift. The node carries
 * `data-entity="addressType:<id>"`, the address `FetchResponse::rowTarget()` builds.
 *
 * @var \Z77\Module\Contact\Entities\AddressType $type
 * @var int $count  how many contact addresses carry the type
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/contact/address-type';
$id         = (string) $type->getId();
?>
            <div class="be-tree__node<?= $type->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-address-type-id="<?= e($id) ?>" data-entity="addressType:<?= e($id) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $type->isActive() ? 'Aktiv — wird für neue Adressen angeboten' : 'Inaktiv — nur noch an bestehenden Adressen' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e($id) ?>"<?= $type->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e($id) ?>">⋮</button>

                    <span class="be-tree__name" data-field="code">
                        <code><?= e($type->getCode()) ?></code>
                        <?= e($type->getLabel()) ?>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <span class="badge <?= $count > 0 ? 'badge--success' : 'badge--muted' ?>" title="Adressen mit diesem Typ"><?= $count ?> <?= $count === 1 ? 'Adresse' : 'Adressen' ?></span>
                        <?php if (!$type->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
