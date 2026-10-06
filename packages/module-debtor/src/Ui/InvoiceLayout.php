<?php
namespace Z77\Module\Debtor\Ui;

/**
 * Layout config for a host that mounts the document screens (ADR-018
 * pattern, the journal model): the host's
 * `Ui/Config/Finance/invoiceControllerConfig.inc.php` delegates here (one
 * line) and pins the page body to the fragment's `listAction` template in
 * `module-debtor` — required, because the LayoutManager would otherwise look
 * for the action template in the host namespace.
 */
final class InvoiceLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\Module\Debtor';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/InvoiceController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
