<?php
/**
 * «Artikel wählen» (`Ui\ArticlePickerTrait::articlePickAction()`, owner 2026-10-19) — fetched, a
 * WINDOW over the editor (ADR-047, mask `article-pick`); without the script the same markup is a
 * page (opened in a new tab — the editor behind keeps what was typed). Action row first
 * (ADR-049): Schliessen (the window only).
 *
 * Below: ONE fetch region (`Ui\ArticlePicker::REGION`) — the toolbar (the product-group filter,
 * the module's shared `partials/productGroupPicker`; the search field) and the COMPACT list
 * Nr. · Bezeichnung · Einheit · Preis. A search or a group reloads only the region; every link and
 * the search form carry `$keep` (`_origin`, `target`, the source's context). The rows belong to
 * ONE POST form: the number button sends `article=<number>`, the other cells are its `<label>`s, so
 * a click anywhere on the row picks it. core.js «pick list» (`[data-pick-list]`): typing searches,
 * ↑/↓ move the active row (`[data-pick-item]`), Enter picks; Esc closes the window.
 *
 * @var \Z77\Module\Article\Services\ProductGroupTree $tree
 * @var string $query
 * @var int|null $group
 * @var list<\Z77\Module\Article\Entities\Variant> $rows
 * @var int $total
 * @var array<int, string> $prices  variant id → the source's price
 * @var string $priceLabel           the price column's head («Preis netto»)
 * @var string $pickUrl
 * @var array<string, string> $keep
 * @var string $origin
 * @var string $target
 * @var bool   $window
 * @var string $csrfToken provided by html()
 */
use Z77\Module\Article\Ui\ArticlePicker;

$window  = !empty($window);
// A chooser without edits: a click outside closes it (core.js `data-window-dismiss`, owner 2026-10-20).
$winAttr = $window ? ' data-window="article-pick" data-window-title="Artikel wählen" data-window-width="52rem" data-window-dismiss' : '';
$link    = static fn(array $state): string => ArticlePicker::url($pickUrl, $keep + $state);
?>
<div class="be-list be-pick"<?= $winAttr ?> data-pick-list>
    <?php if ($window): ?>
    <div class="be-list__section">
        <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    </div>
    <?php endif; ?>
    <div class="be-list__section" data-fetch-region="<?= e(ArticlePicker::REGION) ?>">
        <div class="be-pick__bar">
            <?= $this->partial('partials/productGroupPicker', [
                'tree'  => $tree,
                'id'    => 'article-pick-group',
                'group' => $group,
                'href'  => static fn(int|string|null $id): string => $link(['q' => $query, 'group' => $id]),
            ], 'Z77\\Module\\Article') ?>
            <form class="be-pick__find" method="get" action="<?= e($pickUrl) ?>" role="search" data-fetch-region-form>
                <?php foreach ($keep + array_filter(['group' => $group === null ? '' : (string) $group]) as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
                <?php endforeach; ?>
                <input class="be-input be-input--sm" type="search" id="article-pick-q" name="q" value="<?= e($query) ?>" maxlength="<?= ArticlePicker::QUERY_LENGTH ?>"
                       placeholder="Nr., Bezeichnung oder Beschreibung …" aria-label="Artikel suchen" autocomplete="off" spellcheck="false" autofocus data-pick-search>
                <button type="submit" class="be-btn be-btn--ghost be-btn--sm">Suchen</button>
            </form>
            <span class="be-list__section-badge" title="Artikel"><?= (int) $total ?></span>
        </div>
        <form method="post" action="<?= e($pickUrl) ?>" id="article-pick-choose">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <input type="hidden" name="_origin" value="<?= e($origin) ?>">
            <input type="hidden" name="target" value="<?= e($target) ?>">
        </form>
        <div class="be-list__frame">
            <div class="be-list__table" style="--be-list-cols: 7rem minmax(12rem, 1fr) 4.5rem 7rem">
                <div class="be-list__head">
                    <span class="be-list__col">Nr.</span>
                    <span class="be-list__col">Bezeichnung</span>
                    <span class="be-list__col">Einheit</span>
                    <span class="be-list__col be-list__col--num"><?= e($priceLabel) ?></span>
                </div>
                <?php if ($rows === []): ?>
                <p class="be-list__empty"><?= $query !== '' || $group !== null ? 'Kein Artikel gefunden.' : 'Noch kein aktiver Artikel.' ?></p>
                <?php endif; ?>
                <?php foreach ($rows as $n => $variant): $vid = (int) $variant->getId(); $bid = 'article-pick-' . $vid; ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"><button type="submit" form="article-pick-choose" name="article" value="<?= e($variant->getNumber()) ?>" id="<?= e($bid) ?>" class="be-pick__item" data-pick-item<?= $n === 0 ? ' data-pick-active' : '' ?>><?= e($variant->getNumber()) ?></button></span>
                        <label class="be-list__cell be-list__cell--wrap" for="<?= e($bid) ?>"><?= e($variant->displayName()) ?><?php if ($variant->getProduct()->getDescription() !== ''): ?> <span class="be-list__cell--muted"><?= e(strtok($variant->getProduct()->getDescription(), "\n")) ?></span><?php endif; ?></label>
                        <label class="be-list__cell" for="<?= e($bid) ?>"><?= e($variant->getUnit()) ?></label>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($bid) ?>"><?= ($prices[$vid] ?? '') !== '' ? e($prices[$vid]) : '<span class="be-list__cell--muted" title="Kein Preis an diesem Datum">–</span>' ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($total > count($rows)): ?>
        <p class="be-list__section-hint">Die ersten <?= count($rows) ?> von <?= (int) $total ?> — Suche verfeinern.</p>
        <?php endif; ?>
    </div>
</div>
