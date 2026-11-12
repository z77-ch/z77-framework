<?php

namespace Z77\Module\Api\Ui;

/**
 * Layout config for a host that mounts the device screen (ADR-018 pattern): the host's
 * `Ui/Config/System/apiDeviceControllerConfig.inc.php` delegates here (one line) and pins the
 * page body to the fragment's `listAction` template in `module-api`.
 */
final class ApiDeviceLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Api';

    /** URL root of the backend mount (Stammdaten › System › Geräte). */
    public const BASE = '/backend/system/api-device';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/ApiDeviceController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
