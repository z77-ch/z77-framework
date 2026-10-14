<?php
/**
 * One order-status row of the standard list — the single place that renders it, for the list and
 * for the in-place answer of the switch (FETCH-ROW-001): the node carries
 * `data-entity="orderStatus:<id>"`, the address `FetchResponse::rowTarget()` builds.
 *
 *   - the state icon opens the status WINDOW (ADR-047, `data-window-open`; the origin is the list
 *     region it stands in);
 *   - «Verhalten»: one badge per flag that is ON — the words of the window's switches;
 *   - «Aktiv»: the inline switch (`data-fetch-toggle`); there is no delete (orders reference the code).
 *
 * @var \Z77\Module\Order\Entities\OrderStatus $row
 * @var string $actionBase
 */
use Z77\Module\Order\Ui\OrderStatusListing;

$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderStatusLayout::BASE;
$id         = (int) $row->getId();
$url        = $actionBase . '/edit?id=' . $id;
$active     = $row->isActive();
$state      = $active ? 'Status öffnen — bearbeitbar' : 'Status öffnen — inaktiv, wird nicht mehr vergeben';
$region     = OrderStatusListing::definition()->region();
?>
                <div class="be-list__item" data-entity="orderStatus:<?= $id ?>">
                    <div class="be-list__row<?= $active ? '' : ' be-list__row--inactive' ?>">
                        <a class="be-list__cell be-list__state be-list__state--<?= $active ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($state) ?>" title="<?= e($state) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg></a>
                        <span class="be-list__cell be-list__cell--mono"><?= e($row->getCode()) ?></span>
                        <span class="be-list__cell"><?= e($row->getLabel()) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $row->getLevel() ?></span>
                        <span class="be-list__cell" data-priority="2">
                            <?php foreach ($row->flags() as $flag => $on): ?>
                            <?php if ($on): ?><span class="badge badge--success"><?= e(OrderStatusListing::FLAG_LABELS[$flag]) ?></span><?php endif; ?>
                            <?php endforeach; ?>
                        </span>
                        <span class="be-list__cell be-list__cell--actions">
                            <label class="be-switch be-switch--sm" title="<?= $active ? 'Aktiv — wird als Status angeboten' : 'Inaktiv — Aufträge darin behalten ihn' ?>">
                                <input type="checkbox" class="be-switch__input" aria-label="Aktiv"
                                       data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= $id ?>"<?= $active ? ' checked' : '' ?>>
                                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                            </label>
                        </span>
                    </div>
                </div>
