<?php

namespace Z77\Module\Creditor\Close;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Posting\DirectPosting;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatPeriodCalendar;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Import VAT on a creditor invoice — its invoice date and its payment date in DIFFERENT VAT periods
 * (owner S56-6 / O-5, ADR-041 addendum 2026-11-25 point 8). Import VAT is a «nur Steuer» line; the
 * direct variant posts it on the PAYMENT date like every line, while the right to deduct arises in
 * the period of the customs assessment. There is no field «veranlagt am»: the INVOICE date stands
 * for it. The ONE comparison and the ONE sentence, read by two places with two jobs — no extra
 * entry, no block:
 *
 *   - «Journal verbuchen» (`CreditorJournalPostingService::preview()`): a grey line, early
 *     information;
 *   - «MWST buchen» of the payment period ({@see ImportVatPeriodCheck}, scope `vat-return`): the
 *     same sentence again as a WARNING, confirmed with the one tick.
 *
 * A date outside the settlement calendar (before «Abrechnung ab», after «Abrechnung bis», no
 * settings) has no VAT period — no hint. The calendar is built once per instance from the
 * mandator's settings, read through financial (`LedgerService::vatSettings()`).
 */
final class ImportVatPeriods
{
    private ?VatPeriodCalendar $calendar = null;

    private bool $read = false;

    /** @param VatSettings|null $settings the mandator's VAT settings (a harness passes its own); null = read once through financial */
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?VatSettings $settings = null) {}

    /**
     * The two periods when they DIFFER — [the period of the invoice date, the period of the payment
     * date]; null when a date is missing, lies outside the calendar, or both share one period.
     *
     * @return array{0: VatPeriod, 1: VatPeriod}|null
     */
    public function periods(?\DateTimeImmutable $invoiceDate, ?\DateTimeImmutable $paidOn): ?array
    {
        $calendar = $this->calendar();
        if ($calendar === null || $invoiceDate === null || $paidOn === null) {
            return null;
        }
        $invoicePeriod = $calendar->periodOn($invoiceDate);
        $paidPeriod    = $calendar->periodOn($paidOn);
        if ($invoicePeriod === null || $paidPeriod === null || $invoicePeriod->equals($paidPeriod)) {
            return null;
        }

        return [$invoicePeriod, $paidPeriod];
    }

    /**
     * The sentence for $invoice — null when its dates share one period (or one has none). The
     * caller decides that the invoice carries import VAT (a «nur Steuer» line).
     */
    public function sentence(CreditorInvoice $invoice): ?string
    {
        $periods = $this->periods($invoice->getInvoiceDate(), $invoice->getPaidOn());

        return $periods === null ? null : self::text(DirectPosting::name($invoice), $periods[0], $periods[1]);
    }

    /**
     * «Einfuhrsteuer der Rechnung 26-0815 (Muster Spedition AG): Rechnungsdatum im 3. Quartal 2026,
     * abgezogen im 4. Quartal 2026 — prüfen.» — a posted return stores the confirmed warnings:
     * keep the wording stable.
     */
    public static function text(string $invoiceName, VatPeriod $invoicePeriod, VatPeriod $paidPeriod): string
    {
        return 'Einfuhrsteuer der ' . $invoiceName . ': Rechnungsdatum im ' . $invoicePeriod->label() . ', abgezogen im ' . $paidPeriod->label() . ' — prüfen.';
    }

    private function calendar(): ?VatPeriodCalendar
    {
        if ($this->read) {
            return $this->calendar;
        }
        $this->read = true;
        $s = $this->settings ?? (new LedgerService($this->em))->vatSettings();
        if ($s === null || $s->from === null || $s->rhythmsByYear() === []) {
            return null;
        }
        try {
            $this->calendar = new VatPeriodCalendar($s->rhythmsByYear(), $s->from, $s->until);
        } catch (\InvalidArgumentException) {
            $this->calendar = null;   // settings the VAT return itself refuses — no period, no hint
        }

        return $this->calendar;
    }
}
