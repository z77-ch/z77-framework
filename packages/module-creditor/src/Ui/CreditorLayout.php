<?php
namespace Z77\Module\Creditor\Ui;

/**
 * Layout config for the host that mounts the creditor master data (ADR-018 pattern): the host's
 * `Ui/Config/Finance/creditorProfileControllerConfig.inc.php` delegates here (one line) and pins
 * the page body to the fragment's `listAction` template in `module-creditor` — required, because
 * the LayoutManager would otherwise look for the action template in the host namespace.
 */
final class CreditorLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Creditor';

    /**
     * Stammdaten › Finanzen › Kreditoren — the URL root of the mount
     * (`CreditorProfileControllerTrait::creditorBase()`), also where the contact editor's role
     * «Kreditor» links to (`Roles\CreditorContactRole`).
     */
    public const BASE = '/backend/finance/creditor-profile';

    /** @return array<string, mixed> */
    public static function profiles(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/CreditorProfileController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
