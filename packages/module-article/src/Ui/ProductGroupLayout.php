<?php
namespace Z77\Module\Article\Ui;

/**
 * Layout config for a host that mounts the product-group fragment (ADR-018 pattern, like
 * {@see ArticleLayout}): the host's `Ui/Config/Order/productGroupControllerConfig.inc.php`
 * delegates here and pins the page body to the fragment's `listAction` template.
 */
final class ProductGroupLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Article';

    /** URL root of the backend mount (Stammdaten › Aufträge › Warengruppen). */
    public const BASE = '/backend/order/product-group';

    /** The fetch region of the tree — a save in the group window reloads it. */
    public const REGION = 'product-group-list';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/ProductGroupController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
