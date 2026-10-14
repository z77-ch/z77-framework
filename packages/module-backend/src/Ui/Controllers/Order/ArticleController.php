<?php
namespace Z77\Module\Backend\Ui\Controllers\Order;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Article\Ui\ArticleControllerTrait;

/**
 * Backend mount of the article fragment (P7 slim step 1, plan §3 / §9.5; ADR-018 pattern): all
 * logic and templates live in `module-article` ({@see ArticleControllerTrait}); this host only
 * mounts it under the backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Order/articleControllerConfig.inc.php`.
 *
 * The first host of the backend group `order` (Aufträge, plan §3: `Ui/Controllers/Order/`);
 * module-order's order and order-status screens join it.
 *
 * Reachable only in projects that install z77/module-article.
 *
 * URL: /backend/order/article/list (Stammdaten › Aufträge › Artikel).
 */
class ArticleController extends BackendAbstractController
{
    use ArticleControllerTrait;
}
