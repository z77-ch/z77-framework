<?php
namespace Z77\Module\Financial\Ui;

/**
 * Layout config for a host that mounts the change log fragment (ADR-018):
 * the host's `Ui/Config/{Group}/changeLogControllerConfig.inc.php` delegates
 * here (one line) and pins the page body to the fragment's `listAction`
 * template in `module-financial`. The detail swaps that section for its own
 * template in the trait ({@see ChangeLogControllerTrait::detailAction()}).
 */
final class ChangeLogLayout
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
                        'path'      => 'Backend/ChangeLogController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
