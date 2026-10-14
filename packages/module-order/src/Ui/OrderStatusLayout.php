<?php
namespace Z77\Module\Order\Ui;

/**
 * Layout config for a host that mounts the order-status fragment (ADR-018 pattern, the article
 * model): the host's `Ui/Config/Order/orderStatusControllerConfig.inc.php` delegates here (one
 * line) and pins the page body to the fragment's `listAction` template in `module-order`.
 */
final class OrderStatusLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Order';

    /** URL root of the backend mount (Stammdaten › Aufträge › Auftragsstatus, plan §3). */
    public const BASE = '/backend/order/order-status';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/OrderStatusController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
