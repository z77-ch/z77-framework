<?php
namespace Z77\Module\Mandator\Ui;

use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\MandatorAccounts;
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
        'belege'    => 'Belege',
    ];

    /**
     * The choices of the VAT return's selects (2026-11-24), enum value → German label — the enums
     * (`Entities\VatMethod`, `VatConsideration`, `VatRhythm`) are domain only. The terms are the
     * law's: «Abrechnungsmethode» (Art. 36 / 37 MWSTG), «Abrechnungsart» (Art. 39 MWSTG).
     */
    public const VAT_METHODS = [
        'effective'    => 'Effektive Methode',
        'net-tax-rate' => 'Saldosteuersatzmethode',
        'flat-tax-rate' => 'Pauschalsteuersatzmethode',
    ];
    public const VAT_CONSIDERATIONS = [
        'agreed'   => 'Vereinbartes Entgelt',
        'received' => 'Vereinnahmtes Entgelt',
    ];
    public const VAT_RHYTHMS = [
        'quarter' => 'Quartal',
        'month'   => 'Monat',
        'year'    => 'Jahr',
    ];

    /**
     * The rhythms the record keeps for the years before «Rhythmus gilt ab» (2026-11-25), as the one
     * line under that field — «Davor: Quartal bis 31.12.2027» (several joined by « · »); '' when
     * there are none. Stored data, not an explanation.
     */
    public static function earlierRhythmsLine(Mandator $mandator): string
    {
        $from    = $mandator->getVatRhythmFrom();
        $earlier = $mandator->earlierVatRhythms();
        if ($from === null || $earlier === []) {
            return '';
        }
        $years = array_keys($earlier);
        $parts = [];
        foreach ($years as $i => $year) {
            $end     = ($years[$i + 1] ?? (int) $from->format('Y')) - 1;
            $parts[] = self::VAT_RHYTHMS[$earlier[$year]->value] . ' bis 31.12.' . $end;
        }

        return 'Davor: ' . implode(' · ', $parts);
    }

    /** The tab holding a form field: the accounts → Konten, UID, liability, the no-VAT note and the VAT return's settings (`vat_*`) → UID und MWST, the document defaults → Belege, the rest → Briefkopf. */
    public static function tabOf(string $field): string
    {
        if (str_starts_with($field, 'account_')) {
            return 'konten';
        }
        if ($field === 'default_subject_label') {
            return 'belege';
        }

        return $field === 'uid' || $field === 'liable_to_vat' || str_starts_with($field, 'vat_') ? 'uid' : 'briefkopf';
    }

    /**
     * The account keys in SCREEN order on «Konten»: `Mandator::ACCOUNT_KEYS` (the column order), with
     * the keys appended to the table later moved next to their kin — the VAT return's accounts
     * (`MandatorAccounts::VAT_RETURN_KEYS`: 2026-11-24 the settlement and rounding account, 2026-11-25
     * «Vorsteuer, noch nicht abgerechnet», then «MWST-Korrektur, noch nicht abgerechnet») right after `vat-owed`, the payment charges (2026-11-24, «Gebühren») right after
     * `loss`, beside Skonto and Debitorenverlust.
     *
     * @return list<string>
     */
    public static function accountKeys(): array
    {
        $placedAfter = ['loss' => ['payment-charges'], 'vat-owed' => MandatorAccounts::VAT_RETURN_KEYS];
        $moved       = array_merge(...array_values($placedAfter));
        $ordered     = [];
        foreach (array_diff(array_keys(Mandator::ACCOUNT_KEYS), $moved) as $key) {
            $ordered[] = $key;
            array_push($ordered, ...($placedAfter[$key] ?? []));
        }

        return $ordered;
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
