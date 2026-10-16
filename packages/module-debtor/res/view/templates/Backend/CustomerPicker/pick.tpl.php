<?php
/**
 * «Kunde wählen» (`Ui\CustomerPickerTrait::customerPickAction()`, owner 2026-10-15) — fetched, a
 * WINDOW over the editor (ADR-047, mask `customer-pick`); without the script the same markup is
 * a page. Action row first (ADR-049): «+ Neuer Kontakt» (the host's new-contact form, loaded into
 * THIS window — its save fills the editor and closes it) · Schliessen.
 *
 * Below: a STANDARD LIST (listing.md, `Ui\CustomerPicker::definition()`) — the titles sort, the
 * magnifiers search in the database, the list is a fetch region inside the window. Every link
 * and the search form carry `_origin` and `back` (`$keep`). The rows belong to ONE POST form:
 * «Übernehmen» sends `contact=<id>`; the answer replaces the editor's customer field and closes
 * this window. A name cell is a `<label for>` of its column's search (listing.md).
 *
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var list<array{contact: \Z77\Module\Contact\Entities\Contact, profile: ?\Z77\Module\Debtor\Entities\DebtorProfile}> $rows
 * @var array<int, string> $cities  contact id → «PLZ Ort»
 * @var array<string, string> $keep  `_origin`, `back`
 * @var string $pickUrl
 * @var string $newContactUrl
 * @var string $origin
 * @var string $back
 * @var bool   $window
 * @var string $csrfToken provided by html()
 */
$window   = !empty($window);
$winAttr  = $window ? ' data-window="customer-pick" data-window-title="Kunde wählen" data-window-width="56rem"' : '';
$listLink = static fn(string $query): string => $pickUrl . '?' . ltrim(http_build_query($keep) . '&' . $query, '&');
$extra    = '<a class="be-btn be-btn--ghost" href="' . e($newContactUrl . ($window ? '' : '?' . http_build_query(['back' => $back]))) . '"' . ($window ? ' data-window-link' : '') . '>+ Neuer Kontakt</a>';
?>
<div class="be-list"<?= $winAttr ?>>
    <div class="be-list__section">
        <?= $this->partial('partials/modalActions', [
            'submit'     => '',
            'extra'      => $extra,
            'cancel'     => $window ? 'Schliessen' : 'Zurück',
            'cancelHref' => $window ? '' : $back,
        ], 'Z77\\Shared') ?>
    </div>
    <div class="be-list__section" data-fetch-region="<?= e($definition->region()) ?>">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $pickUrl, 'keep' => $keep], 'Z77\Shared') ?>
            <h2 class="be-list__section-title">Kunden <small class="be-list__cell--muted">· Debitoren und Kontakte ohne Debitor</small></h2>
            <span class="be-list__section-badge" title="Kunden"><?= $paging->total ?></span>
        </div>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $pickUrl, 'keep' => $keep], 'Z77\\Shared') ?>
        <form method="post" action="<?= e($pickUrl) ?>" id="customer-pick-choose">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <input type="hidden" name="_origin" value="<?= e($origin) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
        </form>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $pickUrl, 'keep' => $keep], 'Z77\\Shared') ?>
                <?php if ($rows === []): ?>
                <p class="be-list__empty"><?= $state->isActive() ? 'Kein Kunde gefunden — mit «+ Neuer Kontakt» erfassen.' : 'Noch kein Kontakt — mit «+ Neuer Kontakt» erfassen.' ?></p>
                <?php endif; ?>
                <?php foreach ($rows as $row): $contact = $row['contact']; $profile = $row['profile']; $id = (int) $contact->getId(); ?>
                <div class="be-list__item" data-entity="contact:<?= $id ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell"><button type="submit" form="customer-pick-choose" name="contact" value="<?= $id ?>" class="be-btn be-btn--primary be-btn--sm">Übernehmen</button></span>
                        <label class="be-list__cell be-list__cell--wrap" for="<?= e($definition->inputId('f_name')) ?>"><?= e($contact->displayName()) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_city')) ?>" data-priority="2"><?= e($cities[$id] ?? '') ?></label>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_number')) ?>"><?= $profile !== null ? $profile->getCustomerNumber() : '<span class="badge badge--muted" title="Wird mit der ersten gebuchten Rechnung Debitor">neu</span>' ?></label>
                        <label class="be-list__cell be-list__cell--wrap" for="<?= e($definition->inputId('f_email')) ?>" data-priority="3"><?= e($contact->getEmail()) ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?= $this->partial('partials/pager', [
            'paging'   => $paging,
            'unit'     => 'Kunden',
            'pageLink' => fn(int $p) => $listLink($state->query(['page' => $p])),
        ], 'Z77\\Shared') ?>
    </div>
</div>
