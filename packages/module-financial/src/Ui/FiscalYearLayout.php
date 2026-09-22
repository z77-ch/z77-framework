<?php
namespace Z77\Module\Financial\Ui;

/**
 * Layout config for a host that mounts the fiscal-year fragment (ADR-018): the
 * host's `Ui/Config/{Group}/fiscalYearControllerConfig.inc.php` delegates here
 * (one line) and pins the page body to the fragment's `listAction`
 * template in `module-financial`.
 */
final class FiscalYearLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\\Module\\Financial';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/FiscalYearController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
