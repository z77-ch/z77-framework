<?php
namespace Z77\Module\Contact\Ui;

/**
 * Layout config for a host that mounts the address-type fragment (ADR-018):
 * the host's `Ui/Config/{Group}/addressTypeControllerConfig.inc.php` delegates
 * here (one line) and pins the page body to the fragment's `listAction`
 * template in `module-contact`.
 */
final class AddressTypeLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\\Module\\Contact';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/AddressTypeController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
