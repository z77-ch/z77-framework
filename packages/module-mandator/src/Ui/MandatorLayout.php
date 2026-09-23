<?php
namespace Z77\Module\Mandator\Ui;

/**
 * Layout config for a host that mounts the mandator fragment (ADR-018
 * pattern, the debtor / chart model): the host's
 * `Ui/Config/Finance/mandatorControllerConfig.inc.php` delegates here (one
 * line) and pins the page body to the fragment's `edit` template in
 * `module-mandator` — required, because the LayoutManager would otherwise
 * look for the action template in the host namespace. `edit` rather than
 * `listAction`: one record, one page (`backendConfig` names it the
 * controller's `defaultAction`).
 */
final class MandatorLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Mandator';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/MandatorController',
                        'name'      => 'edit',
                    ]],
                ],
            ],
        ];
    }
}
