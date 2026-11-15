<?php
/** @var \Z77\Shared\Entities\Navigation|null $entry */
/** @var string $entityCsrf */
/** @var int $childCount direct children; > 0 → the delete is refused */
/** @var string $refusal */

// A confirm without any input field: the action row may stay at the bottom (ADR-049
// revision 2026-10-10, the owner's one exception → `end`).
if ($entry === null): ?>
<div class="be-modal__body">
    <p>Eintrag nicht gefunden.</p>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen', 'end' => true], 'Z77\\Shared') ?>
<?php return; endif;

// An entry with children is not deleted (NAV-DELETE-001) — removeAction refuses it too.
if (($childCount ?? 0) > 0): ?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Eintrag löschen</h2>
</div>
<div class="be-modal__body">
    <p>«<?= e($entry->getName()) ?>» hat <?= $childCount === 1 ? 'einen Unterpunkt' : e((string)$childCount) . ' Unterpunkte' ?>.
       <?= e($refusal) ?>.</p>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen', 'end' => true], 'Z77\\Shared') ?>
<?php return; endif; ?>

<form data-fetch-post="/backend/content/navigation/remove">
    <input type="hidden" name="id"          value="<?= e($entry->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Eintrag löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($entry->getName()) ?>» wirklich löschen?</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
