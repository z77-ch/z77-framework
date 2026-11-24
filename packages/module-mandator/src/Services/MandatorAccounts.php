<?php

namespace Z77\Module\Mandator\Services;

use Z77\Module\Mandator\Entities\Mandator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The ledger accounts the mandator carries (owner decision E2, 2026-09-23):
 * their German labels, the KMU start values a NEW record is pre-filled
 * with, and the status of each — what the screen shows so a wrong account
 * is found before a posting refuses it.
 *
 * Who READS an account is NOT this class: the two access points that
 * existed before E2 stay the readers — `LedgerService::vatAccountFor()` for
 * the three `vat-*` keys (by tax-code category), `LedgerService::yearOpeningAccount()`
 * for `opening-balance` / `annual-result` (the year opening, 2026-10-22),
 * `LedgerService::bankPostingAccount()` for `private` / `bank-transfer` (bank-driven
 * bookkeeping, ADR-061 stage C4, 2026-11-17), the VAT return's settlement entry for
 * `vat-settlement` / `vat-rounding` (stage S2 — no reader yet, 2026-11-24) and debtor's
 * `DebtorAccounts` for the first five — so the resolution and its refusal
 * («no account for key x — set it in the mandator») live in one place per
 * module and no caller asks the record directly.
 *
 * The DEFAULTS are constants, not a config key: they are the pre-fill of a
 * form, not a setting — the setting is the record (Rule 2). Verified against
 * `module-financial/res/charts/kmu.json` by the harness; a project with
 * another chart types its numbers in the screen once. What was
 * `financialConfig → vatAccounts` and `debtorConfig → debtorAccounts` is
 * REMOVED from those configs — a leftover key in a project override is
 * refused loudly by its former reader, never read as a second source.
 */
final class MandatorAccounts
{
    /** German, for the screen and for the refusals of the readers. */
    public const LABELS = [
        'receivable'         => 'Debitoren-Sammelkonto',
        'discount'           => 'Skonto',
        'loss'               => 'Debitorenverlust',
        'rounding'           => 'Rundungsdifferenz Rechnung',
        'dunning-fee'        => 'Mahngebühr',
        'vat-input-material' => 'Vorsteuer Material, Waren, Dienstleistungen',
        'vat-input-other'    => 'Vorsteuer Investitionen, übriger Betriebsaufwand',
        'vat-owed'           => 'Geschuldete MWST (Umsatzsteuer)',
        'opening-balance'    => 'Eröffnungsbilanz',
        'annual-result'      => 'Jahresgewinn/-verlust',
        'private'            => 'Privat (Privatbezug / Privateinlage)',
        'bank-transfer'      => 'Übertrag eigene Bankkonten',
        'vat-settlement'     => 'Abrechnungskonto MWST',
        'vat-rounding'       => 'Rundungsdifferenzen MWST',
    ];

    /**
     * The two accounts of the VAT return (ADR-041 addendum 2026-11-24, point 13): asked by the setup
     * check `mandator.accounts` ONLY while the mandator is liable to VAT — a mandator that is not
     * liable has no VAT return and is not nagged for them.
     */
    public const VAT_RETURN_KEYS = ['vat-settlement', 'vat-rounding'];

    /**
     * Start values per the KMU chart (`res/charts/kmu.json` of module-financial) — the pre-fill of a NEW record.
     * `private` and `bank-transfer` (ADR-061 stage C4) have NONE: the shipped chart holds no such
     * account and an installation's chart may use the usual numbers for something else. The VAT
     * return's two (2026-11-24) pre-fill a NEW record only; an existing one got '' from the migration.
     */
    public const DEFAULTS = [
        'receivable'         => '1100',
        'discount'           => '3800',
        'loss'               => '3805',
        'rounding'           => '3809',
        'dunning-fee'        => '6950',
        'vat-input-material' => '1170',
        'vat-input-other'    => '1171',
        'vat-owed'           => '2200',
        'opening-balance'    => '9100',
        'annual-result'      => '2979',
        'vat-settlement'     => '2201',
        'vat-rounding'       => '6945',
    ];

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** A NEW record with the KMU start values on its account fields and nothing else — what the screen shows before the first save. */
    public static function prefilled(): Mandator
    {
        $mandator = new Mandator();
        foreach (self::DEFAULTS as $key => $number) {
            $mandator->mapFromArray([Mandator::accountField($key) => $number]);
        }

        return $mandator;
    }

    /**
     * Every key with its number and the refusal the bookkeeping would raise
     * — for the screen. `error` is null when the number is postable, or
     * when financial is absent and nothing can be told (the screen says
     * «ungeprüft» then, through `LedgerAccountCheck::available()`).
     *
     * @return array<string, array{number: string, error: string|null}>
     */
    public function status(Mandator $mandator): array
    {
        $check = new LedgerAccountCheck($this->em);
        $rows  = [];
        foreach (array_keys(Mandator::ACCOUNT_KEYS) as $key) {
            $number = $mandator->account($key);
            $error  = null;
            if ($number === '') {
                $error = 'Kein Konto hinterlegt — eine Buchung auf «' . self::LABELS[$key] . '» wird abgelehnt.';
            } elseif ($check->isPostable($number) === false) {
                $error = 'Konto ' . $number . ' gibt es in der Buchhaltung nicht, es ist eine Gruppe oder inaktiv.';
            }
            $rows[$key] = ['number' => $number, 'error' => $error];
        }

        return $rows;
    }
}
