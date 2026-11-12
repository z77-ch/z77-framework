<?php

namespace Z77\Module\Api\Ui;

/**
 * Layout config for a host that mounts the screen «App-Benutzer» (ADR-018 pattern): the host's
 * `Ui/Config/System/apiAppUserControllerConfig.inc.php` delegates here (one line) and pins the
 * page body to the fragment's `listAction` template in `module-api`.
 */
final class ApiAppUserLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Api';

    /** URL root of the backend mount (Stammdaten › System › App-Benutzer). */
    public const BASE = '/backend/system/api-app-user';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/ApiAppUserController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
