<?php
namespace Z77\Module\Debtor\Ui;

/**
 * Layout config for a host that mounts the debtor master-data fragment (ADR-018
 * pattern, the tax-code / chart model): the host's
 * `Ui/Config/Finance/debtorProfileControllerConfig.inc.php` delegates here (one line) and pins the
 * page body to the fragment's `listAction` template in `module-debtor` —
 * required, because the LayoutManager would otherwise look for the action
 * template in the host namespace.
 */
final class DebtorProfileLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Debtor';

    /**
     * URL root of the backend mount (`DebtorProfileControllerTrait::debtorListBase()`) — also
     * where the contact editor's «Debitor» role links to (`Roles\DebtorContactRole`). A host
     * mounting the fragment elsewhere overrides `debtorListBase()`; the role link follows this
     * constant (DEBTOR-ROLE-001 in debtor.md).
     */
    public const BASE = '/backend/finance/debtor-profile';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/DebtorProfileController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
