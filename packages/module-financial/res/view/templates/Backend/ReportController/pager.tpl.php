<?php
/**
 * Pager of a long report (`.be-pagination`, server links `?page=`, no
 * JavaScript): previous, the pages, next, and «n Zeilen, Seite x von y».
 * Screen only (`.be-noprint`) — every printed page is the page on screen.
 *
 * @var \Z77\Module\Financial\Reports\Paging $paging
 * @var callable $pageLink  page number → URL
 * @var string $unit         «Zeilen» / «Buchungen»
 */
if ($paging->pageCount < 2) { return; }
$window = 3;
?>
<nav class="be-pagination be-noprint" aria-label="Seiten">
    <a class="be-pagination__link<?= $paging->page === 1 ? ' be-pagination__link--disabled' : '' ?>" href="<?= e($pageLink(max(1, $paging->page - 1))) ?>" aria-label="Vorherige Seite">‹</a>
    <?php for ($p = 1; $p <= $paging->pageCount; $p++): ?>
        <?php if ($p === 1 || $p === $paging->pageCount || abs($p - $paging->page) <= $window): ?>
        <a class="be-pagination__link<?= $p === $paging->page ? ' be-pagination__link--active' : '' ?>" href="<?= e($pageLink($p)) ?>"<?= $p === $paging->page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
        <?php elseif (abs($p - $paging->page) === $window + 1): ?>
        <span class="be-pagination__ellipsis">…</span>
        <?php endif; ?>
    <?php endfor; ?>
    <a class="be-pagination__link<?= $paging->page === $paging->pageCount ? ' be-pagination__link--disabled' : '' ?>" href="<?= e($pageLink(min($paging->pageCount, $paging->page + 1))) ?>" aria-label="Nächste Seite">›</a>
    <span class="be-pagination__count"><?= $paging->total ?> <?= e($unit) ?> · Seite <?= $paging->page ?> von <?= $paging->pageCount ?></span>
</nav>
