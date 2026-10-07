<?php
namespace Z77\Module\Debtor\Ui;

/**
 * Layout config for a host that mounts the «Zahlungseingänge» fragment
 * (ADR-018 pattern, the invoice model): the host's
 * `Ui/Config/Finance/bankImportControllerConfig.inc.php` delegates here and
 * pins the page body to the fragment's `listAction` template in
 * `module-debtor`.
 */
final class BankImportLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Debtor';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/BankImportController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
