<?php
/**
 * One contact row of the list (`.be-tree__node`) — the single place that renders it: the list
 * loops over it, and a save answers with it (`FetchResponse::replaceRow()` / `insertRow()`,
 * ADR-047 addendum 2026-10-10), so list and answer cannot drift. The node carries
 * `data-entity="contact:<id>"`, the address `FetchResponse::rowTarget()` builds.
 *
 * @var \Z77\Module\Contact\Entities\Contact $contact
 * @var list<\Z77\Module\Contact\Entities\ContactAddress> $links  in creation order
 * @var array<string,string> $kindLabels
 * @var \Z77\Module\Contact\Services\AddressTypes $addressTypes
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/contact/contact';
$id         = (string) $contact->getId();
?>
            <div class="be-tree__node<?= $contact->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-contact-id="<?= e($id) ?>" data-entity="contact:<?= e($id) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $contact->isActive() ? 'Aktiv — wird für neue Belege angeboten' : 'Inaktiv — nur noch für bestehende Belege' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e($id) ?>"<?= $contact->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="<?= e($actionBase) ?>/actions?id=<?= e($id) ?>">⋮</button>

                    <span class="be-tree__name" data-field="name">
                        <?= e($contact->displayName()) ?>
                        <small class="be-list__cell--muted">· <?= e($kindLabels[$contact->getKind()] ?? $contact->getKind()) ?><?= $contact->getEmail() !== '' ? ' · ' . e($contact->getEmail()) : '' ?> · <?= e(mb_strtoupper($contact->getLanguage())) ?></small>
                    </span>

                    <span class="be-tree__url" data-field="addresses">
                        <?php if ($links === []): ?>
                        <span class="badge badge--warning">keine Adresse</span>
                        <?php else: ?>
                        <?php foreach ($links as $i => $link): ?>
                        <?= $i > 0 ? ' · ' : '' ?><?= raw($this->partial('Backend/ContactController/_address', ['link' => $link, 'addressTypes' => $addressTypes], 'Z77\\Module\\Contact')) ?>
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
