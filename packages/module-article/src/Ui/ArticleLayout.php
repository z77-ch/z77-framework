<?php
namespace Z77\Module\Article\Ui;

/**
 * Layout config for a host that mounts the article fragment (ADR-018 pattern, the debtor model):
 * the host's `Ui/Config/Order/articleControllerConfig.inc.php` delegates here (one line) and pins
 * the page body to the fragment's `listAction` template in `module-article` — required, because
 * the LayoutManager would otherwise look for the action template in the host namespace.
 */
final class ArticleLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Article';

    /** URL root of the backend mount (Stammdaten › Aufträge › Artikel, plan §3: `/backend/order/article/…`). */
    public const BASE = '/backend/order/article';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/ArticleController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
