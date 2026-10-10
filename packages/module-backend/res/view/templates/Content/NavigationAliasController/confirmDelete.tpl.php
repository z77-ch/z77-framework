<?php
/** @var \Z77\Shared\Entities\NavigationAlias|null $alias */
/** @var string $entityCsrf */

// A confirm without any input field: the action row may stay at the bottom (ADR-049
// revision 2026-10-10, the owner's one exception → `end`).
if ($alias === null): ?>
<div class="be-modal__body">
    <p>Alias nicht gefunden.</p>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen', 'end' => true], 'Z77\\Shared') ?>
<?php return; endif; ?>

<form data-fetch-post="/backend/content/navigation-alias/remove">
    <input type="hidden" name="id"          value="<?= (int)$alias->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Alias löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>Alias «<?= e($alias->getPath()) ?>» wirklich löschen? Der Pfad ist danach nicht mehr erreichbar.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
