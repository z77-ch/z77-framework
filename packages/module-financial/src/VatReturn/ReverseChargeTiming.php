<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The REVERSE-CHARGE date rule (ADR-041 addendum 2026-11-25, point 10; addendum 2026-11-25 b,
 * point 3; ADR-059 amendment 2026-11-25 b): on which day the reverse-charge tax entry of an invoice
 * is posted — or why it is not.
 *
 *   - Year AND period are decided by the INVOICE date — the moment the tax claim arises (Art. 48
 *     para. 1 lit. b MWSTG, as the decision notes give it) — never by the payment date.
 *   - The invoice date in no posted VAT period → the tax entry is dated AT the invoice date.
 *   - The invoice date in a POSTED period → refused, also within the same calendar year: the
 *     reverse charge goes the correction path by hand (a correction entry naming that period); it is
 *     never caught up in the running period.
 *   - Before «Abrechnung ab» → «ohne MWST», as for every voucher.
 *   - After «Abrechnung bis» → the rule does not apply (`none`): after the end of the liability z77
 *     settles nothing.
 *
 * This is NOT the input-tax catch-up rule of {@see VatSettlement} (decided by the PAYMENT date, caught
 * up within the year through the transit account) and must not be merged with it, call it, wrap it or
 * share a helper with it: two rules in two places (owner, 2026-11-25). «Posted» is read through
 * {@see VatSettlement::periodCovering()} — the one reader of «settled», not a shared date check.
 *
 * No production caller yet: the contract of the build plan for the reverse-charge packages of the
 * creditor invoice and the receipt (wave 3), which call {@see forInvoiceDate()} for every line with a
 * reverse-charge code. Lock-free: an answer for building and showing; the ledger's freeze judges the
 * entries under its locks.
 */
final class ReverseChargeTiming
{
    private ?VatSettings $settings;

    private bool $settingsRead;

    private ?VatSettlement $settlement = null;

    /**
     * @param VatSettings|null $settings the mandator's VAT settings (a harness passes its own); null =
     *                                   read once through {@see LedgerService::vatSettings()}
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?VatSettings $settings = null)
    {
        $this->settings     = $settings;
        $this->settingsRead = $settings !== null;
    }

    /**
     * The date answer for a reverse-charge line of an invoice (or receipt) dated $invoiceDate — see
     * {@see ReverseChargeDate} for the four kinds.
     */
    public function forInvoiceDate(\DateTimeImmutable $invoiceDate): ReverseChargeDate
    {
        $s = $this->settings();
        if ($s === null || !$s->liable || $s->from === null) {
            return ReverseChargeDate::none();
        }
        if ($invoiceDate->format('Y-m-d') < $s->from->format('Y-m-d')) {
            return ReverseChargeDate::beforeStart();
        }
        // After «Abrechnung bis» the liability has ended: z77 settles nothing for that day, no return
        // would ever carry the tax entry — the rule does not apply.
        if ($s->until !== null && $invoiceDate->format('Y-m-d') > $s->until->format('Y-m-d')) {
            return ReverseChargeDate::none();
        }
        $posted = $this->settlement($s)->periodCovering($invoiceDate);
        if ($posted !== null) {
            return ReverseChargeDate::refused($posted);
        }

        return ReverseChargeDate::postAt($invoiceDate);
    }

    private function settlement(VatSettings $settings): VatSettlement
    {
        return $this->settlement ??= new VatSettlement($this->em, $settings);
    }

    private function settings(): ?VatSettings
    {
        if (!$this->settingsRead) {
            $this->settings     = (new LedgerService($this->em))->vatSettings();
            $this->settingsRead = true;
        }

        return $this->settings;
    }
}
