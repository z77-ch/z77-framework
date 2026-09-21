<?php
/**
 * Contact row action hub (⋮): edit, add address, and the contact's typed
 * addresses with «Bearbeiten» / «Entfernen» per row. No delete of the
 * contact itself — deactivate on the list row instead (documents reference
 * it by id).
 *
 * The address list is a small `.be-list__table` (the backend's list grid):
 * one row per link, phrase + action cell — the same classes every list
 * screen uses, no inline styles.
 *
 * @var \Z77\Module\Contact\Entities\Contact $entry
 * @var list<\Z77\Module\Contact\Entities\ContactAddress> $links  in creation order
 * @var \Z77\Module\Contact\Services\AddressTypes $addressTypes
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/contact/contact';
$tplNs      = 'Z77\\Module\\Contact';
$id         = $entry->getId();
$ic         = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — <?= e($entry->displayName()) ?></h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $id) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="<?= e($actionBase) ?>/add-address?id=<?= e((string) $id) ?>"><?= raw($ic('icon-plus')) ?> Adresse hinzufügen …</button>
        </div>
        <div class="be-form__section">Adressen <small>(Belege tragen eine Kopie — Änderungen hier ändern keinen Beleg)</small></div>
        <?php if ($links === []): ?>
        <p class="be-list__empty">Noch keine Adresse — ohne Adresse kann kein Beleg an diesen Kontakt gehen.</p>
        <?php else: ?>
        <div class="be-list__table be-list__table--actions">
            <?php foreach ($links as $link): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= raw($this->partial('Backend/ContactController/_address', ['link' => $link, 'addressTypes' => $addressTypes], $tplNs)) ?></span>
                    <span class="be-list__cell be-list__cell--actions">
                        <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                                data-fetch-get="<?= e($actionBase) ?>/edit-address?id=<?= e((string) $link->getId()) ?>">Bearbeiten</button>
                        <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                                data-fetch-get="<?= e($actionBase) ?>/confirm-remove-address?id=<?= e((string) $link->getId()) ?>">Entfernen</button>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
