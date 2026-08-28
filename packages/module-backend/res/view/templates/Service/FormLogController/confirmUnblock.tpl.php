<?php
/**
 * Confirm lifting a country block. It shows the reason the block was entered
 * under — the decision is reviewed against what justified it, not against
 * memory.
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
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn">Sperre aufheben</button>
    </div>
</form>
