<?php
/** @var \Z77\Shared\Entities\Navigation|null $entry */
/** @var string $entityCsrf */
/** @var int $childCount direct children; > 0 → the delete is refused */
/** @var string $refusal */
/** @var int $refCount ref entries pointing at the entry; > 0 → the delete is refused */
/** @var string $refRefusal */
/** @var \Z77\Shared\Entities\NavigationAlias[] $aliases deleted together with the entry */
/** @var int $metaCount metadata records (one per language), deleted together with the entry */

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
<?php return; endif;

// Neither while ref entries point at it — removeAction refuses it too.
if (($refCount ?? 0) > 0): ?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Eintrag löschen</h2>
</div>
<div class="be-modal__body">
    <p>Auf «<?= e($entry->getName()) ?>» <?= $refCount === 1 ? 'zeigt ein Verweis' : 'zeigen ' . e((string)$refCount) . ' Verweise' ?>.
       <?= e($refRefusal) ?>.</p>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen', 'end' => true], 'Z77\\Shared') ?>
<?php return; endif;

// Aliases and metadata are keyed to this entry alone and go with it — named here.
$aliases   = $aliases ?? [];
$metaCount = $metaCount ?? 0; ?>

<form data-fetch-post="/backend/content/navigation/remove">
    <input type="hidden" name="id"          value="<?= e($entry->getId()) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Eintrag löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>«<?= e($entry->getName()) ?>» wirklich löschen?</p>
        <?php if ($aliases !== [] || $metaCount > 0): ?>
        <p>Mit dem Eintrag werden gelöscht:</p>
        <ul>
            <?php if ($aliases !== []): ?>
            <li>Alias-Adressen: <?= e((string)count($aliases)) ?>
                (<?= e(implode(', ', array_map(static fn($a) => $a->getPath(), $aliases))) ?>)</li>
            <?php endif; ?>
            <?php if ($metaCount > 0): ?>
            <li>Metadaten (<?= $metaCount === 1 ? 'eine Sprache' : e((string)$metaCount) . ' Sprachen' ?>)</li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
