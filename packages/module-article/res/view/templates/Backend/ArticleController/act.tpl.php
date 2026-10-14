<?php
/**
 * Artikel — the action cell (hc1): «+ Artikel», the most frequent action of this master-data
 * entry (backend-screen.md §2, css-backend.md «action cell per area»). Exactly one control,
 * rendered inset by the shell; create = `.be-btn--primary`. Opens the article WINDOW (ADR-047,
 * `data-window-open`); the href stays for a ctrl-click and a browser without the script. The
 * origin names the list region, so the save reloads it. While the list is filtered by a product
 * group (the rail picker), the link carries it (`?group=`): the new article starts in that group
 * with its default tax code and revenue account.
 *
 * Part of the fragment: added by `listAction()` (financial.md, «fragment slots»).
 *
 * @var string $actionBase
 * @var int|null $group  the group the list is filtered by
 */
$add = ($actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE) . '/add' . (!empty($group) ? '?group=' . (int) $group : '');
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Article\Ui\ArticleListing::ID) ?>-list">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Artikel</span>
</a>
