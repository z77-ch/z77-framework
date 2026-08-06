<?php
namespace Z77\Module\Member\Ui;

/**
 * Layout config for a host that mounts the member accounts fragment (dms
 * DriveLayout pattern, ADR-018): the host's
 * `Ui/Config/Service/memberAccountsControllerConfig.inc.php` delegates here
 * (one line) and pins the page body to the fragment's `listAction` template
 * in `module-member` — required, because the LayoutManager would otherwise
 * look for the action template in the host namespace.
 */
final class AccountsLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\\Module\\Member';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/AccountsController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
