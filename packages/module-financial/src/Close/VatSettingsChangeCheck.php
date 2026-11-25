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
 *   - the rhythm, year by year (since 2026-11-25, ADR-041 addendum point 1 — «Rhythmus gilt ab»):
 *     refused when a live return would come out in another rhythm, or when periods before the
 *     change are not all posted ({@see rhythmFinding()}); way out: the change on a 1 January after
 *     the latest return, once every period before it is posted. Asked also without a live return;
 *   - «Abrechnung ab», the method or the consideration CHANGED — refused, unless the
 *     new value is the one the latest live return carries (a setting changed past the returns may
 *     be set back; «Abrechnung ab» is not stored on a return, so any change of it is refused);
 *     way out: an admin lifts the returns (latest first);
 *   - the liability switched off without «Abrechnung bis» (or «bis» cleared while not liable) —
 *     refused; way out: set «Abrechnung bis» to the last day of the liability;
 *   - «Abrechnung bis» CHANGED to a day before the end of the latest live return — refused. «Bis»
 *     itself stays settable: it only cuts the last period.
 *
 * Nothing when no setting changed; without a live return only the rhythm rule can refuse. Reads
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
        $settlement = new VatSettlement($this->em());
        $latest     = $settlement->latest();

        $rhythm = self::rhythmFinding($before, $after, $settlement->liveReturns(), $latest);
        if ($rhythm !== null) {
            yield $rhythm;
        }
        if ($latest === null) {
            return;
        }
        $label = $latest->period()->label();

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

    /**
     * The rhythm, year by year (ADR-041 addendum 2026-11-25, point 1, «Rhythmus gilt ab»): the change
     * takes effect from the first year whose rhythm differs (`VatSettings::firstRhythmDifference()`).
     *
     *   - a LIVE return on or after that 1 January whose rhythm the new calendar would not yield —
     *     refused, the posted periods are bound (setting a drifted rhythm BACK to the returns' passes);
     *   - else, when periods lie between «Abrechnung ab» and that 1 January: the latest live return
     *     must end on its eve (or on «Abrechnung bis» before it) — every period before is posted.
     *
     * A change of the whole calendar (from «Abrechnung ab» on, nothing posted) passes.
     *
     * @param list<VatReturn> $live the live returns, in period order
     */
    private static function rhythmFinding(VatSettings $before, VatSettings $after, array $live, ?VatReturn $latest): ?Finding
    {
        $year = VatSettings::firstRhythmDifference($before, $after);
        if ($year === null) {
            return null;
        }
        $change = new \DateTimeImmutable($year . '-01-01');
        // The sentence stands at «Rhythmus gilt ab» when that day was typed anew, else at the rhythm.
        $field  = $after->rhythmFrom !== null && !self::sameDay($after->rhythmFrom, $before->rhythmFrom) ? 'vat_rhythm_from' : 'vat_settlement_rhythm';
        foreach ($live as $return) {
            if ($return->getPeriodTo() >= $change && $return->getRhythm() !== $after->rhythmIn((int) $return->getPeriodFrom()->format('Y'))) {
                return Finding::blocking(
                    'Der Abrechnungsrhythmus ist an die gebuchten MWST-Abrechnungen gebunden (zuletzt ' . $latest?->period()->label() . ') — einen Wechsel mit «Rhythmus gilt ab» auf einen 1. Januar nach dieser Abrechnung eintragen.',
                    $field
                );
            }
        }
        if ($after->from === null) {
            return null;
        }
        $mustEnd = $change->modify('-1 day');
        if ($after->until !== null && $after->until < $mustEnd) {
            $mustEnd = $after->until;
        }
        if ($mustEnd < $after->from || ($latest !== null && $latest->getPeriodTo()->format('Y-m-d') >= $mustEnd->format('Y-m-d'))) {
            return null;
        }

        return Finding::blocking(
            'Ein Wechsel des Abrechnungsrhythmus auf den ' . $change->format('d.m.Y') . ' geht erst, wenn alle MWST-Abrechnungen davor gebucht sind ('
                . ($latest === null ? 'noch keine gebucht' : 'zuletzt gebucht: ' . $latest->period()->label()) . ').',
            $after->rhythmFrom !== null ? 'vat_rhythm_from' : $field
        );
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
