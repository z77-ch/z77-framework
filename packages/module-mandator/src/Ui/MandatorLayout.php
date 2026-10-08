<?php
namespace Z77\Module\Mandator\Ui;

use Z77\Persistence\Validation\EntityValidator;

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

    /**
     * The form's sections, shown as radio tabs (owner 2026-10-08): key (the
     * radio's id suffix) → tab label, in FORM order. Toolbar labels and
     * panels number them 1..n in this order (`data-tab` — the generic
     * `.be-radiotabs` pattern of module-backend, components/_radiotabs.scss).
     */
    public const TABS = [
        'briefkopf' => 'Briefkopf',
        'uid'       => 'UID und MWST',
        'konten'    => 'Konten',
    ];

    /** The tab holding a form field: the accounts → Konten, UID and liability → UID und MWST, the rest → Briefkopf. */
    public static function tabOf(string $field): string
    {
        if (str_starts_with($field, 'account_')) {
            return 'konten';
        }

        return in_array($field, ['uid', 'liable_to_vat'], true) ? 'uid' : 'briefkopf';
    }

    /**
     * The tab the page opens on: the one holding the FIRST field with an
     * error (the tabs are in form order, so: the first tab with any field
     * error), else the first tab. An error without a field opens the first
     * tab — the alert stands above the panels, visible on every tab.
     */
    public static function openTab(EntityValidator $validator): string
    {
        $withError = array_map(self::tabOf(...), array_keys($validator->getFieldErrors()));
        foreach (array_keys(self::TABS) as $tab) {
            if (in_array($tab, $withError, true)) {
                return $tab;
            }
        }

        return array_key_first(self::TABS);
    }

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
