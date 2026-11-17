<?php

namespace Z77\Module\Financial\Ui;

/**
 * Layout config and URL roots of the two bank screens (ADR-060; ADR-018 mount pattern): each
 * host's `Ui/Config/Finance/{x}ControllerConfig.inc.php` in module-backend delegates here and
 * pins the page body to the fragment's `listAction` template in this module.
 */
final class BankLayout
{
    /** Namespace that owns the fragments' templates. */
    public const NS = 'Z77\Module\Financial';

    /** Stammdaten › Finanzen › Bankkonten. */
    public const ACCOUNT_BASE = '/backend/finance/bank-account';

    /** Finanzen › Bankkonto (upload and the list «Bewegungen»). */
    public const MOVEMENT_BASE = '/backend/finance/bank-movement';

    /** @return array<string, mixed> */
    public static function accounts(): array
    {
        return self::config('Backend/BankAccountController');
    }

    /** @return array<string, mixed> */
    public static function movements(): array
    {
        return self::config('Backend/BankMovementController');
    }

    /** @return array<string, mixed> */
    private static function config(string $path): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => $path,
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
