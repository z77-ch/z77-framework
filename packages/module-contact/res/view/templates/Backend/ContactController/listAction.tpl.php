<?php
/**
 * Contacts with their typed addresses (plan §4a). One row per contact: the
 * inline switch is `active` (deactivate, never delete — documents reference
 * the contact by id), the ⋮ hub carries edit / add address / the address
 * list with edit and remove. The search (`?q=`, hc2 slot) is a plain GET
 * form; the list shows at most $limit rows and says how many match.
 *
 * Styling: the shared backend list/tree classes only (`.be-tree--hub` row
 * anatomy, `.be-tree__url` and `.be-list__cell--muted` for secondary text,
 * `.be-list__empty`, badges) — no inline styles, no CSS of its own.
 *
 * @var list<\Z77\Module\Contact\Entities\Contact> $contacts
 * @var array<int, list<\Z77\Module\Contact\Entities\ContactAddress>> $linksByContact  contact id → links
 * @var int $total  how many contacts match the query (all, when empty)
 * @var int $limit  rows shown at most
 * @var string $query
 * @var array<string,string> $kindLabels
 * @var \Z77\Module\Contact\Services\AddressTypes $addressTypes
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase  = $actionBase ?? '/backend/contact/contact';
$tplNs       = 'Z77\\Module\\Contact';
$addressLine = fn($link): string => $this->partial('Backend/ContactController/_address', ['link' => $link, 'addressTypes' => $addressTypes], $tplNs);
$shown       = count($contacts);
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= $query === '' ? 'Kontakte' : 'Kontakte zu «' . e($query) . '»' ?></h2>
            <span class="be-list__section-badge"><?= $total ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if ($contacts === []): ?>
            <p class="be-list__empty"><?= $query === '' ? 'Keine Kontakte vorhanden.' : 'Kein Kontakt passt zu «' . e($query) . '».' ?></p>
            <?php endif; ?>
            <?php foreach ($contacts as $contact): ?>
            <?php $links = $linksByContact[$contact->getId()] ?? []; ?>
            <div class="be-tree__node<?= $contact->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-contact-id="<?= e((string) $contact->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $contact->isActive() ? 'Aktiv — wird für neue Belege angeboten' : 'Inaktiv — nur noch für bestehende Belege' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $contact->getId()) ?>"<?= $contact->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="<?= e($actionBase) ?>/actions?id=<?= e((string) $contact->getId()) ?>">⋮</button>

                    <span class="be-tree__name" data-field="name">
                        <?= e($contact->displayName()) ?>
                        <small class="be-list__cell--muted">· <?= e($kindLabels[$contact->getKind()] ?? $contact->getKind()) ?><?= $contact->getEmail() !== '' ? ' · ' . e($contact->getEmail()) : '' ?> · <?= e(mb_strtoupper($contact->getLanguage())) ?></small>
                    </span>

                    <span class="be-tree__url" data-field="addresses">
                        <?php if ($links === []): ?>
                        <span class="badge badge--warning">keine Adresse</span>
                        <?php else: ?>
                        <?php foreach ($links as $i => $link): ?>
                        <?= $i > 0 ? ' · ' : '' ?><?= raw($addressLine($link)) ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php if (!$contact->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($total > $shown): ?>
        <p class="be-form__hint"><?= $shown ?> von <?= $total ?> Kontakten angezeigt — die Suche oben grenzt ein.</p>
        <?php endif; ?>
    </div>
</div>
