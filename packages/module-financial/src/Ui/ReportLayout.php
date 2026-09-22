<?php
namespace Z77\Module\Financial\Ui;

/**
 * Layout config for a host that mounts the report fragment (ADR-018): the
 * host's `Ui/Config/{Group}/reportControllerConfig.inc.php` delegates here
 * (one line) and pins the page body to the trial balance — the reports'
 * default page. The other reports swap that section for their own template
 * in the trait (`reportPage()`), which also adds the tab row.
 */
final class ReportLayout
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
                        'path'      => 'Backend/ReportController',
                        'name'      => 'trialBalance',
                    ]],
                ],
            ],
        ];
    }
}
