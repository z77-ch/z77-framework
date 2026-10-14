<?php
namespace Z77\Module\Order\Ui;

/**
 * Layout config for a host that mounts the order fragment (ADR-018 pattern, the invoice model):
 * the host's `Ui/Config/Order/orderControllerConfig.inc.php` delegates here (one line) and pins
 * the page body to the fragment's `listAction` template in `module-order`.
 */
final class OrderLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Order';

    /** URL root of the backend mount (Aufträge › Aufträge, plan §3: `/backend/order/order/…`). */
    public const BASE = '/backend/order/order';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/OrderController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
