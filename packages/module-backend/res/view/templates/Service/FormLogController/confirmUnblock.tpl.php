<?php
/**
 * Confirm lifting a country block. It shows the reason the block was entered
 * under — the decision is reviewed against what justified it, not against
 * memory. No input field: the action row may stand at the bottom (`end`, ADR-049 rev. 2026-10-10).
 *
 * @var \Z77\Shared\Entities\BlockedCountry $entry
 * @var string $entityCsrf
 * @var string $actionBase
 */
?>
<form data-fetch-post="<?= e($actionBase) ?>/unblock">
    <input type="hidden" name="code"        value="<?= e($entry->getCode()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Sperre für «<?= e($entry->getCode()) ?>» aufheben</h2>
    </div>
    <div class="be-modal__body">
        <p>Übermittlungen aus <strong><?= e($entry->getCode()) ?></strong> werden
           danach wieder normal angenommen.</p>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8)">
            Gesperrt <?= e($entry->getAddedAt() !== '' ? date('d.m.Y', (int)strtotime($entry->getAddedAt())) : 'unbekannt') ?><?php
            ?><?= $entry->getAddedBy() !== null ? ' durch ' . e($entry->getAddedBy()) : '' ?>:
            <em><?= e($entry->getReason()) ?></em>
        </p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Sperre aufheben', 'end' => true], 'Z77\\Shared') ?>
</form>
