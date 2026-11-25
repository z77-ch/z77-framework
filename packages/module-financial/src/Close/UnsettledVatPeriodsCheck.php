<?php

namespace Z77\Module\Financial\Close;

use Z77\Core\DI;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Financial's close check for the VAT return (ADR-042 addendum 2026-11-24, point 6; financial.md
 * «MWST buchen», the year-close check): a fiscal year is not closed while a VAT period that ENDS
 * in it is not settled. Registered in `financialConfig` under `openWorkChecks` → `period-close`;
 * parameters as the scope documents them (`FiscalYearCloseService`): `fiscalYear`, `from`, `to`.
 *
 *   - Not liable and no «Abrechnung bis», a method z77 does not settle, no rhythm, NO «Abrechnung
 *     ab» — every case in which `VatReturnService::settingsRefusal()` refuses: NO finding. A liable
 *     mandator without «Abrechnung ab» settles its VAT outside z77 and is never blocked (owner
 *     2026-11-24, evening — a block would have stopped the close of every existing liable
 *     installation on deploy); {@see hint()} is the sentence a screen may show for it — and for
 *     the settings that stopped yielding periods although returns are posted or «Abrechnung ab» is
 *     set (rhythm cleared, liability switched off without «Abrechnung bis»): a hint too, not a
 *     block (point 6 decides «hint only» for the unset day; a block for the other cases is not
 *     decided).
 *   - Otherwise: one BLOCKING finding per period of the calendar that ends between `from` and `to`
 *     and is not settled (`VatSettlement`), naming it — «Die MWST-Periode 3. Quartal 2026 ist nicht
 *     abgerechnet …», reference `vat-return:{from}`. A period that began in the year before belongs
 *     here; one that ends after the year does not (it is the next year's). A year wholly before
 *     «Abrechnung ab» gets none — the calendar starts there.
 *
 * Reads in the caller's snapshot (the close asks after its locks: a return in flight holds the
 * fiscal-year rows shared, so the close waits and then reads the committed return), writes nothing.
 */
final class UnsettledVatPeriodsCheck implements OpenWorkCheckInterface
{
    /**
     * @param VatSettings|null $settings the mandator's VAT settings; null = read from the record
     */
    public function __construct(private readonly ?UnifiedEntityManager $em = null, private readonly ?VatSettings $settings = null) {}

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        $service = new VatReturnService($this->em(), $this->settings);
        if ($service->settingsRefusal() !== null) {
            return;
        }
        foreach ($service->calendar()->periodsEndingBetween($from, $to) as $period) {
            if (!$service->isSettled($period)) {
                yield Finding::blocking(
                    'Die MWST-Periode ' . $period->label() . ' ist nicht abgerechnet — zuerst die MWST-Abrechnung buchen (Finanzen › MWST-Abrechnungen).',
                    'vat-return:' . $period->from->format('Y-m-d')
                );
            }
        }
    }

    /** The hint for a liable mandator without «Abrechnung ab» — {@see hint()}. */
    public const HINT_NO_START = 'Der Mandant ist mehrwertsteuerpflichtig, aber «Abrechnung ab» ist nicht festgelegt — z77 prüft beim Abschluss nicht, ob die MWST des Jahres abgerechnet ist.';

    /** The hint for a mandator no longer liable, with posted returns or «Abrechnung ab», but no «Abrechnung bis» — {@see hint()}. */
    public const HINT_NO_END = 'Der Mandant ist nicht mehr mehrwertsteuerpflichtig, aber «Abrechnung bis» ist nicht festgelegt — z77 prüft beim Abschluss nicht mehr, ob die MWST bis zum Ende der Pflicht abgerechnet ist; «Abrechnung bis» festlegen (' . VatReturnRefusal::SETTINGS_PLACE . ').';

    /**
     * The HINT the close may show when its check cannot run — null in every other case (ADR-042
     * addendum point 6: «the close shows a hint only»). Not a finding: a warning of the registry
     * would have to be confirmed at every close, a blocking one is not decided. No screen shows it
     * yet (stage S2 slice 5b).
     *
     *   - a liable mandator without «Abrechnung ab» → {@see HINT_NO_START} (z77 does not settle its VAT);
     *   - returns are posted or «Abrechnung ab» is set, but the settings no longer yield periods
     *     (`VatReturnService::settingsRefusal()` refuses — the rhythm cleared, the method changed, the
     *     liability switched off without «Abrechnung bis»): the close would pass a year whose VAT
     *     periods z77 cannot name → {@see HINT_NO_END} for the liability switched off, else the
     *     settings gate's own sentence behind «z77 prüft beim Abschluss nicht, …».
     */
    public function hint(): ?string
    {
        try {
            $s = $this->settings ?? (new CurrentMandator($this->em()))->vatSettings();
        } catch (MandatorUnavailableException) {
            return null;
        }
        if ($s->liable && $s->from === null) {
            return self::HINT_NO_START;
        }
        $service = new VatReturnService($this->em(), $this->settings);
        $refusal = $service->settingsRefusal();
        if ($refusal === null || ($s->from === null && $service->settlement()->latest() === null)) {
            return null;   // the check runs — or nothing was ever settled in z77 and no day is named
        }
        if ($refusal->reason === VatReturnRefusal::NOT_LIABLE) {
            return self::HINT_NO_END;
        }

        return 'Die MWST-Einstellungen des Mandanten ergeben keine MWST-Perioden — z77 prüft beim Abschluss nicht, ob die MWST des Jahres abgerechnet ist. ' . $refusal->message;
    }

    private function em(): UnifiedEntityManager
    {
        return $this->em ?? DI::getUnifiedEntityManager();
    }
}
