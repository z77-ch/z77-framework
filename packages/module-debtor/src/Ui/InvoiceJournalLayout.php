<?php
namespace Z77\Module\Debtor\Ui;

/**
 * Layout config for a host that mounts the Faktura-Journal (ADR-018 pattern): the host's
 * `Ui/Config/Finance/invoiceJournalControllerConfig.inc.php` delegates here (one line) and pins
 * the page body to the fragment's `listAction` template in `module-debtor`.
 */
final class InvoiceJournalLayout
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
                        'path'      => 'Backend/InvoiceJournalController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
