<?php
namespace Z77\Module\Financial\Ui;

/**
 * Layout config for a host that mounts the journal fragment (ADR-018): the
 * host's `Ui/Config/{Group}/journalControllerConfig.inc.php` delegates here
 * (one line) and pins the page body to the fragment's `listAction` template
 * in `module-financial`. The other pages (detail, form) swap that section
 * for their own template in the trait (`journalPage()`).
 */
final class JournalLayout
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
                        'path'      => 'Backend/JournalController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
