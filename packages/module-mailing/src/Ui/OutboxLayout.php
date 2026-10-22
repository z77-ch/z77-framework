<?php
namespace Z77\Module\Mailing\Ui;

/**
 * Layout config for a host that mounts the outbox fragment (ADR-018): the
 * host's `Ui/Config/Service/outboxControllerConfig.inc.php` delegates here
 * (one line) and pins the page body to the fragment's `listAction` template.
 * The window and the menu swap that section for their own template in the
 * trait.
 */
final class OutboxLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\\Module\\Mailing';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/OutboxController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
