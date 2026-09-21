<?php
/**
 * Address types (plan §4a, managed data). One row per type: the inline
 * switch is `active` (deactivate, never delete — `contact_address` rows
 * reference the code, ADR-043 decision 19), «Bearbeiten» opens the modal;
 * the badge says how many addresses carry the type, so a deactivation is an
 * informed one.
 *
 * Styling: the shared backend list/tree classes only — no inline styles,
 * no CSS of its own.
 *
 * @var list<\Z77\Module\Contact\Entities\AddressType> $types  in file order
 * @var array<string,int> $usage  code → number of contact addresses carrying it
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/contact/address-type';
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Adresstypen</h2>
            <span class="be-list__section-badge"><?= count($types) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if ($types === []): ?>
            <p class="be-list__empty">Keine Adresstypen vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($types as $type): ?>
            <?php $count = $usage[$type->getCode()] ?? 0; ?>
            <div class="be-tree__node<?= $type->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-address-type-id="<?= e((string) $type->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $type->isActive() ? 'Aktiv — wird für neue Adressen angeboten' : 'Inaktiv — nur noch an bestehenden Adressen' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $type->getId()) ?>"<?= $type->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $type->getId()) ?>">⋮</button>

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
            <?php endforeach; ?>
        </div>
    </div>
</div>
