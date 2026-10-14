<?php
/**
 * One article row of the standard list — the single place that renders it, for the list and for
 * the in-place answer of a switch or a save (FETCH-ROW-001): the node carries
 * `data-entity="article:<id>"`, the address `FetchResponse::rowTarget()` builds.
 *
 *   - the state icon opens the article WINDOW (ADR-047, `data-window-open`; the origin is the
 *     list region it stands in), the href stays for a ctrl-click;
 *   - every searchable cell is a `<label for>` of its column's search field;
 *   - Preis: the price valid today from the history (A4) — a gross price says «brutto»; a price
 *     that only starts later shows its day («ab …»);
 *   - «Aktiv»: the inline switch (`data-fetch-toggle`) — product and variants together.
 *
 * @var \Z77\Module\Article\Entities\Product $product
 * @var \Z77\Module\Article\Entities\Variant|null $variant  the default variant
 * @var \Z77\Module\Article\Entities\Price|null $price      the price shown
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Shared\Money\AmountFormat;

$actionBase = $actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE;
$id         = (int) $product->getId();
$url        = $actionBase . '/edit?id=' . $id;
$active     = $product->isActive();
$later      = $price !== null && $price->getValidFrom() > new \DateTimeImmutable('today');
$state      = $active ? 'Artikel öffnen — bearbeitbar' : 'Artikel öffnen — inaktiv, wird für neue Positionen nicht angeboten';
?>
                <div class="be-list__item" data-entity="article:<?= $id ?>">
                    <div class="be-list__row<?= $active ? '' : ' be-list__row--inactive' ?>">
                        <a class="be-list__cell be-list__state be-list__state--<?= $active ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" aria-label="<?= e($state) ?>" title="<?= e($state) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg></a>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_number')) ?>"><?= e($variant?->getNumber() ?? '') ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_name')) ?>"><?= e($product->getName()) ?></label>
                        <span class="be-list__cell" data-priority="2"><?= e($variant?->getUnit() ?? '') ?></span>
                        <span class="be-list__cell be-list__cell--num"><?php if ($price !== null): ?><?= e(AmountFormat::of($price->getAmount())) ?><?= $price->priceMode() === PriceMode::Gross ? ' <small class="be-list__cell--muted">brutto</small>' : '' ?><?= $later ? ' <small class="be-list__cell--muted">ab ' . e($price->getValidFrom()->format('d.m.Y')) . '</small>' : '' ?><?php endif; ?></span>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_tax')) ?>" data-priority="3"><?= e($product->getTaxCode()) ?></label>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_account')) ?>" data-priority="3"><?= e($product->getRevenueAccount()) ?></label>
                        <span class="be-list__cell" data-priority="2"><?= $product->isStockRelevant() ? 'ja' : '<span class="be-list__cell--muted">nein</span>' ?></span>
                        <span class="be-list__cell be-list__cell--actions">
                            <label class="be-switch be-switch--sm" title="<?= $active ? 'Aktiv — wird für neue Positionen angeboten' : 'Inaktiv — bestehende Positionen behalten ihn' ?>">
                                <input type="checkbox" class="be-switch__input" aria-label="Aktiv"
                                       data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= $id ?>"<?= $active ? ' checked' : '' ?>>
                                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                            </label>
                        </span>
                    </div>
                </div>
