<?php
/**
 * Navigation row action hub (⋮). Opened from a list row via data-fetch-get; each button
 * launches the specific modal via data-fetch-get (core-wired on popup show → a click
 * replaces this hub with the target modal). Mirrors the DMS drive actions hub
 * (module-dms .../DriveController/_actions.tpl.php) so every list row works the same.
 *
 * @var \Z77\Shared\Entities\Navigation $entry
 * @var string $siblingUrl
 */
$id = $entry->getId();
$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($entry->getName()) ?>»</h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="<?= e($siblingUrl) ?>"><?= raw($ic('icon-plus')) ?> Geschwister hinzufügen</button>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/content/navigation/edit?id=<?= e($id) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
            <button type="button" class="be-btn be-btn--danger be-actions__item" data-fetch-get="/backend/content/navigation/confirm-delete?id=<?= e($id) ?>"><?= raw($ic('icon-trash')) ?> Löschen</button>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
