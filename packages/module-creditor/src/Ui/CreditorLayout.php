<?php
namespace Z77\Module\Creditor\Ui;

/**
 * Layout config for the hosts that mount the creditor screens (ADR-018 pattern): the hosts'
 * `Ui/Config/Finance/creditorProfileControllerConfig.inc.php` / `creditorJournalControllerConfig.inc.php`
 * delegate here (one line each) and pin the page body to the fragment's `listAction` template in
 * `module-creditor` — required, because the LayoutManager would otherwise look for the action
 * template in the host namespace. The URL roots of both mounts live HERE, once.
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

    /** Finanzen › Kreditorenjournal — the URL root of the journal mount (`CreditorJournalControllerTrait::creditorJournalBase()`). */
    public const JOURNAL_BASE = '/backend/finance/creditor-journal';

    /** @return array<string, mixed> */
    public static function profiles(): array
    {
        return self::pinned('Backend/CreditorProfileController');
    }

    /** @return array<string, mixed> */
    public static function journal(): array
    {
        return self::pinned('Backend/CreditorJournalController');
    }

    /** @return array<string, mixed> the page body pinned to the fragment's `listAction` in this namespace */
    private static function pinned(string $path): array
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
