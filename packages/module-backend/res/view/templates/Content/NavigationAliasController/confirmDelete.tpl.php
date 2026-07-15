<?php
/** @var \Z77\Shared\Entities\NavigationAlias|null $alias */
/** @var string $entityCsrf */

if ($alias === null): ?>
<div class="be-modal__body">
    <p>Alias nicht gefunden.</p>
</div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
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
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
