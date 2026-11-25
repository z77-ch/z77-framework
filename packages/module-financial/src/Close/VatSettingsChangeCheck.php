<?php

namespace Z77\Module\Financial\Close;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\VatReturn\VatSettlement;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Mandator\Services\VatSettingsGuard;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Financial's answer to the mandator's question «may the VAT settings change like this?» (scope
 * `mandator-vat-settings`, {@see VatSettingsGuard}; ADR-042 addendum 2026-11-24 point 5, financial.md
 * FIN-VAT-SETTINGS-001). Registered in `financialConfig` under `openWorkChecks` beside the close
 * checks — this namespace holds financial's answers to the open-work registry.
 *
 * Once a LIVE return exists (posted, not lifted — `VatSettlement::latest()`), the settings it was
 * computed with are bound: a period the calendar no longer yields can never be settled, lifted or
 * posted again cleanly (the posting service's `period-overlaps` refusal stays the backstop under
 * its locks). Each BLOCKING finding names the form field it belongs to (the finding's reference)
 * and the way out:
 *
 *   - the rhythm, «Abrechnung ab», the method or the consideration CHANGED — refused, unless the
 *     new value is the one the latest live return carries (a setting changed past the returns may
 *     be set back; «Abrechnung ab» is not stored on a return, so any change of it is refused);
 *     way out: an admin lifts the returns (latest first);
 *   - the liability switched off without «Abrechnung bis» (or «bis» cleared while not liable) —
 *     refused; way out: set «Abrechnung bis» to the last day of the liability;
 *   - «Abrechnung bis» CHANGED to a day before the end of the latest live return — refused. «Bis»
 *     itself stays settable: it only cuts the last period.
 *
 * Nothing when no setting changed or no live return exists — then the mandator saves freely. Reads
 * lock-free in the caller's request, writes nothing.
 */
final class VatSettingsChangeCheck implements OpenWorkCheckInterface
{
    /** Where the returns are lifted — the way out every «bound» sentence names. */
    private const LIFT_PLACE = 'Finanzen › MWST-Abrechnungen';

    public function __construct(private readonly ?UnifiedEntityManager $em = null) {}

    public function check(string $scope, array $parameters): iterable
    {
        $before = $parameters['before'] ?? null;
        $after  = $parameters['after'] ?? null;
        if (!$before instanceof VatSettings || !$after instanceof VatSettings) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'before' and 'after' as " . VatSettings::class . ' — got ' . get_debug_type($before) . ' / ' . get_debug_type($after));
        }
        $latest = (new VatSettlement($this->em()))->latest();
        if ($latest === null) {
            return;
        }
        $label = $latest->period()->label();

        if ($after->rhythm !== $before->rhythm && $after->rhythm !== $latest->getRhythm()) {
            yield Finding::blocking(self::bound('Der Abrechnungsrhythmus ist', $label), 'vat_settlement_rhythm');
        }
        if (!self::sameDay($after->from, $before->from)) {
            yield Finding::blocking(self::bound('«Abrechnung ab» ist', $label), 'vat_settlement_from');
        }
        if ($after->method !== $before->method && $after->method !== $latest->getMethod()) {
            yield Finding::blocking(self::bound('Die Abrechnungsmethode ist', $label), 'vat_method');
        }
        if ($after->consideration !== $before->consideration && $after->consideration !== $latest->getConsideration()) {
            yield Finding::blocking(self::bound('Die Abrechnungsart ist', $label), 'vat_consideration');
        }
        if (self::endsOpen($after) && !self::endsOpen($before)) {
            yield Finding::blocking(
                'Ohne «Abrechnung bis» lässt sich die MWST-Pflicht nicht ausschalten, solange MWST-Abrechnungen gebucht sind (zuletzt ' . $label . ') — «Abrechnung bis» auf den letzten Tag der Pflicht setzen, dann ausschalten.',
                'liable_to_vat'
            );
        }
        if ($after->until !== null && !self::sameDay($after->until, $before->until) && $after->until < $latest->getPeriodTo()) {
            yield Finding::blocking(self::untilTooEarly($latest), 'vat_settlement_until');
        }
    }

    /** «{Subject} an die gebuchten MWST-Abrechnungen gebunden …» — one sentence per bound setting. */
    private static function bound(string $subject, string $latestLabel): string
    {
        return $subject . ' an die gebuchten MWST-Abrechnungen gebunden (zuletzt ' . $latestLabel . ') — ändern geht erst, wenn ein Admin sie aufgehoben hat (' . self::LIFT_PLACE . ').';
    }

    private static function untilTooEarly(VatReturn $latest): string
    {
        $end = $latest->getPeriodTo()->format('d.m.Y');

        return '«Abrechnung bis» liegt vor dem Ende der gebuchten MWST-Abrechnung ' . $latest->period()->label() . ' — frühestens den ' . $end . ' wählen oder die Abrechnung zuerst von einem Admin aufheben lassen (' . self::LIFT_PLACE . ').';
    }

    /** Not liable and no «Abrechnung bis» — the settings then name no last period. */
    private static function endsOpen(VatSettings $s): bool
    {
        return !$s->liable && $s->until === null;
    }

    private static function sameDay(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): bool
    {
        return $a?->format('Y-m-d') === $b?->format('Y-m-d');
    }

    private function em(): UnifiedEntityManager
    {
        return $this->em ?? DI::getUnifiedEntityManager();
    }
}
