<?php
namespace Z77\Module\Debtor\Ui;

/**
 * Layout config for a host that mounts the Debitoren fragment (ADR-018
 * pattern, the tax-code / chart model): the host's
 * `Ui/Config/Finance/debtorControllerConfig.inc.php` delegates here (one line) and pins the
 * page body to the fragment's `listAction` template in `module-debtor` —
 * required, because the LayoutManager would otherwise look for the action
 * template in the host namespace.
 */
final class DebtorLayout
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
                        'path'      => 'Backend/DebtorController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
