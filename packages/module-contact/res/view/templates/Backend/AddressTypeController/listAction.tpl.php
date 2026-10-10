<?php
/**
 * Address types (plan §4a, managed data). One row per type: the inline
 * switch is `active` (deactivate, never delete — `contact_address` rows
 * reference the code, ADR-043 decision 19), «Bearbeiten» opens the modal;
 * the badge says how many addresses carry the type, so a deactivation is an
 * informed one.
 *
 * One row = `_row` (also the in-place answer of a save, ADR-047 addendum 2026-10-10).
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
        <div class="be-tree be-tree--hub be-tree--lead-switch" data-entity-list="addressType">
            <?php if ($types === []): ?>
            <p class="be-list__empty">Keine Adresstypen vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($types as $type): ?>
            <?= raw($this->partial('Backend/AddressTypeController/_row', [
                'type'       => $type,
                'count'      => $usage[$type->getCode()] ?? 0,
                'actionBase' => $actionBase,
            ], 'Z77\\Module\\Contact')) ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
