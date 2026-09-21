<?php
namespace Z77\Module\Contact\Ui;

/**
 * Layout config for a host that mounts the contact fragment (dms DriveLayout
 * pattern, ADR-018): the host's `Ui/Config/{Group}/contactControllerConfig.inc.php`
 * delegates here (one line) and pins the page body to the fragment's
 * `listAction` template in `module-contact` — required, because the
 * LayoutManager would otherwise look for the action template in the host
 * namespace.
 */
final class ContactLayout
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
                        'path'      => 'Backend/ContactController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
