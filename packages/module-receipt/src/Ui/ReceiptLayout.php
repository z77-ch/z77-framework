<?php
namespace Z77\Module\Receipt\Ui;

/**
 * Layout config for the hosts that mount the receipt screens (ADR-018 pattern): each host's
 * `Ui/Config/Finance/{x}ControllerConfig.inc.php` delegates here (one line) and pins the page body
 * to the fragment's `listAction` template in `module-receipt`. The URL roots of the three mounts
 * live here too — every link and form of a screen is built from its trait's base, which defaults
 * to these.
 */
final class ReceiptLayout
{
    /** Namespace that owns the fragments' templates. */
    public const NS = 'Z77\Module\Receipt';

    /** Finanzen › Belegjournal. */
    public const JOURNAL_BASE = '/backend/finance/receipt-journal';

    /** Stammdaten › Finanzen › Belegvorlagen. */
    public const TEMPLATE_BASE = '/backend/finance/receipt-template';

    /** Stammdaten › Finanzen › Zahlungsarten («Bezahlt mit», E10). */
    public const PAYMENT_METHOD_BASE = '/backend/finance/payment-method';

    /** @return array<string, mixed> */
    public static function journal(): array
    {
        return self::config('Backend/ReceiptJournalController');
    }

    /** @return array<string, mixed> */
    public static function templates(): array
    {
        return self::config('Backend/ReceiptTemplateController');
    }

    /** @return array<string, mixed> */
    public static function paymentMethods(): array
    {
        return self::config('Backend/PaymentMethodController');
    }

    /** @return array<string, mixed> */
    private static function config(string $path): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => $path,
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
