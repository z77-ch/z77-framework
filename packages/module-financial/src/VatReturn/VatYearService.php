<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnCorrection;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Financial\Repositories\VatReturnCorrectionRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountMap;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Financial\Services\YearOpeningService;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Module\Vat\CountryPack\FormEdition;
use Z77\Module\Vat\CountryPack\FormMapping;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Services\VatException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The yearly overview of the VAT return («MWST-Jahresübersicht», stage S7 slice 7.1 — ADR-042
 * addendum 2 of 2026-11-25, point 8; addendum 3, points 4 to 9; ADR-041 addendum 2026-11-25,
 * point 11), READ-ONLY: it posts nothing, locks nothing, stores nothing, blocks nothing. One tax
 * year = one CALENDAR year (Art. 34 MWSTG); a fiscal year that differs is reconciled with the annual
 * accounts by hand — the overview counts the calendar year over every fiscal year it touches.
 *
 * What it reads (no recompute of a posted period — under the freeze the ordinary figures of a
 * settled period ARE its stored figures, and every correction is an explicit stored block):
 *
 *   - the periods of the year and their LIVE returns ({@see VatSettlement}, the one reader of
 *     «settled»); the stored figures of each (`VatReturnService::declaredFields()`);
 *   - the corrections FOR the year: the stored blocks whose corrected period lies in the year,
 *     carried by live returns of this or a later year (`VatReturnCorrectionRepository::correctingBetween()`,
 *     rendered by `VatReturnService::declaredCorrectionBlocks()`);
 *   - the corrections entered for the year but not yet reported: the blocks the unposted periods'
 *     returns would carry (`VatReturnService::correctionBlocks()`);
 *   - ONE movement query over the settled-for days of the year (`JournalLineRepository::vatReturnMovements()`
 *     — it takes several fiscal years and leaves out the year opening and the settlement entries),
 *     classified by the return's own rule ({@see VatYearClassifier}); the computed figures of an
 *     unposted period only for the turnover it adds ({@see VatYearTurnoverItem::OPEN_PERIODS});
 *   - the balances per 31.12. of 2201, 1179 and 2209 ({@see VatCorrectionTransit} for 2209).
 *
 * {@see isFinal()} decides «vorläufig» (B-4), {@see fingerprint()} what «Seit der Ablage geändert»
 * compares (B-5). One instance per request, like `VatReturnService` (whose instance it shares).
 */
final class VatYearService
{
    /** A finding of the overview: the transit account 1179 does not stand at 0.00 on 31 December (input tax is not caught up across the year, S56-8). */
    public const FINDING_INPUT_PENDING_BALANCE = 'input-pending-balance';

    /** A finding of the overview: an unposted period of the year cannot be computed (its preview is refused). */
    public const FINDING_PERIOD_REFUSED = 'period-refused';

    /** The «von Hand prüfen» lines of Art. 128 para. 2 MWSTV z77 cannot know (small rule 4 of S7) — letter => label. */
    public const BY_HAND = [
        'c' => 'Konzerninterne Verrechnungen',
        'e' => 'Vorauszahlungen',
        'f' => 'Übrige Einnahmen',
        'g' => 'Naturalleistungen',
    ];

    /** Entries the movements leave out — the year opening and the return's own settlement entries (as the return's tab «Abstimmung»). */
    private const NOT_MOVEMENTS = [YearOpeningService::SOURCE_TYPE, VatReturnService::SOURCE_TYPE];

    private readonly VatReturnService $returns;

    private ?FormMapping $mapping = null;

    /** @var array<int, VatTaxYear> */
    private array $years = [];

    /** @var array<int, VatYearOverview> */
    private array $overviews = [];

    /**
     * @param VatSettings|null      $settings the mandator's settings; null = read through the return service (a harness passes its own)
     * @param VatReturnService|null $returns  the request's return service (its caches are shared); null = a new one
     */
    public function __construct(
        private readonly UnifiedEntityManager $em,
        ?VatSettings $settings = null,
        ?VatReturnService $returns = null,
    ) {
        $this->returns = $returns ?? new VatReturnService($em, $settings);
    }

    /**
     * The tax year $year with its settlement periods.
     *
     * @throws VatReturnRefusedException the settings gate of the return; no settlement period lies in the year
     */
    public function taxYear(int $year): VatTaxYear
    {
        if (isset($this->years[$year])) {
            return $this->years[$year];
        }
        $taxYear = VatTaxYear::of($year, $this->returns->calendar());
        if (!$taxYear->hasPeriods()) {
            throw new VatReturnRefusedException(new VatReturnRefusal(
                VatReturnRefusal::NO_PERIOD,
                "Im Steuerjahr {$year} ist keine MWST-Periode — z77 rechnet die MWST nur für die Jahre ab «Abrechnung ab» (bis «Abrechnung bis») ab.",
                'mandator:vat'
            ));
        }

        return $this->years[$year] = $taxYear;
    }

    /**
     * B-4: the overview is FINAL — every VAT period of the year posted AND the fiscal year holding
     * 31 December closed; «vorläufig» otherwise. Lock-free.
     *
     * @throws VatReturnRefusedException see {@see taxYear()}
     */
    public function isFinal(int $taxYear): bool
    {
        $year = $this->taxYear($taxYear);

        return self::finalWhen($this->periods($year), $this->fiscalYears()->findByDate($year->lastDay()));
    }

    /**
     * The rule of B-4 on what it reads: every period posted, and the fiscal year holding 31.12.
     * opened and closed (no such fiscal year → not final).
     *
     * @param list<VatYearPeriod> $periods
     */
    public static function finalWhen(array $periods, ?FiscalYear $yearEnd): bool
    {
        if ($periods === [] || $yearEnd === null || !$yearEnd->isClosed()) {
            return false;
        }
        foreach ($periods as $period) {
            if (!$period->isPosted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * B-5: SHA-256 over everything the overview shows — the facts, the periods with their returns,
     * every figure exact, the blocks, the pending entries, the reconciliations, the balances and the
     * findings. Equal while nothing the overview shows has changed; the screen compares it with the
     * fingerprint of the newest stored PDF. The moment of reading («Stand») is not part of it.
     *
     * @throws VatReturnRefusedException see {@see overview()}
     */
    public function fingerprint(int $taxYear): string
    {
        return hash('sha256', json_encode(self::canonical($this->overview($taxYear)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The yearly overview of $taxYear. Cached on this instance.
     *
     * @throws VatReturnRefusedException the settings gate; no settlement period lies in the year
     */
    public function overview(int $taxYear): VatYearOverview
    {
        if (isset($this->overviews[$taxYear])) {
            return $this->overviews[$taxYear];
        }
        $year     = $this->taxYear($taxYear);
        $currency = LedgerService::baseCurrency();
        $zero     = Money::zero($currency);
        $periods  = $this->periods($year);
        $yearEnd  = $this->fiscalYears()->findByDate($year->lastDay());
        $deadline = VatYearDeadlines::of($taxYear, $yearEnd);
        $live     = array_values(array_filter(array_map(static fn(VatYearPeriod $p): ?VatReturn => $p->return, $periods)));
        $findings = [];

        // The declared figures and the payable amounts as posted.
        $declared = [];
        $payables = $zero;
        $rounding = $zero;
        foreach ($live as $return) {
            $declared[] = [$return, $this->returns->declaredFields($return)];
            $payables   = $payables->add($return->getPayable());
            $rounding   = $rounding->add($return->getRoundingDifference());
        }

        // The reported corrections FOR the year (carried by live returns of this or a later year).
        /** @var VatReturnCorrectionRepository $correctionRows */
        $correctionRows = $this->em->getRepository(VatReturnCorrection::class);
        $rows           = $correctionRows->correctingBetween($year->firstDay(), $year->lastDay());
        $corrections    = $this->corrections($year, $rows, $deadline);

        // The corrections entered for the year, not yet reported.
        $pending = $this->pending($year);

        // The books: one movement query over the settled-for days of the year.
        $movements = $this->lines()->vatReturnMovements($this->yearIds($year), $year->from()->format('Y-m-d'), $year->to()->format('Y-m-d'), self::NOT_MOVEMENTS, $this->openingAccountId());
        $turnover  = $this->turnover($year, $periods, $declared, $rows, $movements, $currency, $findings);
        $accounts  = $this->accounts($year, $declared, $movements, $currency);

        foreach ($accounts as $account) {
            if (!$account->remainder()->isZero()) {
                $findings[] = new VatReturnFinding(false, VatReturnFinding::VAT_ACCOUNT_REMAINDER,
                    'MWST-Konto «' . trim($account->accountNumber . ' ' . $account->accountName) . "» {$taxYear}: Bewegung " . AmountFormat::of($account->movement)
                    . ', Steuer der codierten Buchungen ' . AmountFormat::of($account->expected) . ' — Rest ' . AmountFormat::of($account->remainder()) . '; im Kontoblatt finden.',
                    'account:' . $account->accountNumber, $account->remainder());
            }
        }
        if (!$turnover->remainder()->isZero()) {
            $findings[] = new VatReturnFinding(false, VatReturnFinding::TURNOVER_REMAINDER,
                "Umsatzabstimmung {$taxYear}: unerklärter Rest " . AmountFormat::of($turnover->remainder()) . ' zwischen den Ertragskonten und der Deklaration (Reiter «Umsatz»).',
                'turnover', $turnover->remainder());
        }

        $inputPending = $this->balance(LedgerService::VAT_INPUT_PENDING_ACCOUNT, $year->lastDay());
        if ($inputPending !== null && !$inputPending->balance->isZero()) {
            $findings[] = new VatReturnFinding(false, self::FINDING_INPUT_PENDING_BALANCE,
                'Konto «' . trim($inputPending->accountNumber . ' ' . $inputPending->accountName) . '»: Saldo am ' . $year->lastDay()->format('d.m.Y') . ' ' . AmountFormat::of($inputPending->balance)
                . ' — über die Jahresgrenze wird keine Vorsteuer nachgeholt; im Kontoblatt finden.',
                'account:' . $inputPending->accountNumber, $inputPending->balance);
        }

        return $this->overviews[$taxYear] = new VatYearOverview(
            $year,
            $this->returns->methodLabel(),
            $this->touching($year),
            $yearEnd,
            $deadline,
            self::finalWhen($periods, $yearEnd),
            $periods,
            $this->fields($declared, $rows, $currency),
            $corrections,
            $pending,
            $turnover,
            $accounts,
            $rounding,
            $payables,
            $this->balance(LedgerService::VAT_SETTLEMENT_ACCOUNT, $year->lastDay()),
            $inputPending,
            $this->correctionPending($year),
            $findings,
        );
    }

    // ── the parts ────────────────────────────────────────────────────────

    /** @return list<VatYearPeriod> */
    private function periods(VatTaxYear $year): array
    {
        $settlement = $this->returns->settlement();

        return array_map(static function (VatPeriod $period) use ($settlement): VatYearPeriod {
            $return = $settlement->returnCovering($period->to);
            if ($return !== null && !$return->period()->equals($period)) {
                $return = null;   // a live return of other days — data no rule lets arise; the period counts as not posted
            }

            return new VatYearPeriod($period, $return, VatYearDeadlines::dueDate($period));
        }, $year->periods);
    }

    /**
     * The reported blocks for the year: per carrying return, its stored blocks whose corrected
     * period lies in the year — in order of the corrected period, then of the carrying return.
     *
     * @param list<VatReturnCorrection> $rows
     * @return list<VatYearCorrection>
     */
    private function corrections(VatTaxYear $year, array $rows, VatYearDeadlines $deadlines): array
    {
        $carrying = [];
        foreach ($rows as $row) {
            $carrying[(int) $row->getVatReturn()->getId()] = $row->getVatReturn();
        }
        $result = [];
        foreach ($carrying as $return) {
            $reportedOn = $return->getPostedAt()->setTime(0, 0);
            foreach ($this->returns->declaredCorrectionBlocks($return) as $block) {
                if (!$year->covers($block->period->to)) {
                    continue;
                }
                $result[] = new VatYearCorrection($block, $return, $reportedOn, $deadlines->beforeDay240($reportedOn), $return->getPeriodFrom()->format('Y-m-d') > $year->lastDay()->format('Y-m-d'));
            }
        }
        usort($result, static fn(VatYearCorrection $a, VatYearCorrection $b): int
            => [$a->block->period->from->format('Y-m-d'), $a->carryingReturn->getPeriodFrom()->format('Y-m-d')] <=> [$b->block->period->from->format('Y-m-d'), $b->carryingReturn->getPeriodFrom()->format('Y-m-d')]);

        return $result;
    }

    /**
     * The corrections entered for the year, not yet reported: the correction entries dated from the
     * running period on (every later period is unposted too — returns follow one another), per
     * carrying period the block its return would carry, kept when the corrected period lies in the
     * year.
     *
     * @return list<VatYearPending>
     */
    private function pending(VatTaxYear $year): array
    {
        $running = $this->returns->settlement()->claimableFrom();
        if ($running === null) {
            return [];
        }
        $calendar = $this->returns->calendar();
        $carrying = [];
        foreach ($this->lines()->vatCorrectionEntries($running->format('Y-m-d'), '9999-12-31') as $entry) {
            if (!$year->covers(new \DateTimeImmutable($entry['corrected_to']))) {
                continue;
            }
            $period = $calendar->periodOn(new \DateTimeImmutable($entry['entry_date']));
            if ($period !== null) {
                $carrying[$period->from->format('Y-m-d')] = $period;
            }
        }
        ksort($carrying);
        $result = [];
        foreach ($carrying as $period) {
            foreach ($this->returns->correctionBlocks($period) as $block) {
                if ($year->covers($block->period->to)) {
                    $result[] = new VatYearPending($block, $period);
                }
            }
        }

        return $result;
    }

    /**
     * The sum per field: declared (stored figures of the live returns; the payable amount as
     * posted) + the corrections for the year (stored block rows), in form order.
     *
     * @param list<array{0: VatReturn, 1: list<VatReturnField>}> $declared
     * @param list<VatReturnCorrection>                          $rows
     * @return list<VatYearField>
     */
    private function fields(array $declared, array $rows, string $currency): array
    {
        $zero  = Money::zero($currency);
        $exact = ExactAmount::zero($currency);
        $sum   = [];   // field => [label, kind, declared, declaredBase, corrections, correctionsBase, shown]
        $payableField = null;
        foreach ($declared as [$return, $fields]) {
            foreach ($fields as $f) {
                if ($f->kind === FieldKind::Credit) {
                    continue;   // folded into the payable field
                }
                $sum[$f->field] ??= [$f->label, $f->kind, $exact, null, $exact, null, false];
                if ($f->kind === FieldKind::Payable) {
                    $payableField = $f->field;
                    $sum[$f->field][2] = $sum[$f->field][2]->add(ExactAmount::ofMoney($return->getPayable()));
                    $sum[$f->field][6] = true;
                    continue;
                }
                $sum[$f->field][2] = $sum[$f->field][2]->add($f->value);
                if ($f->base !== null) {
                    $sum[$f->field][3] = ($sum[$f->field][3] ?? $zero)->add($f->base);
                }
                $sum[$f->field][6] = $sum[$f->field][6] || $f->shown;
            }
        }
        foreach ($rows as $row) {
            $kind  = $row->getKind();
            $field = $row->getField();
            if (($kind === FieldKind::Payable || $kind === FieldKind::Credit) && $payableField !== null) {
                $field = $payableField;
            }
            if (!isset($sum[$field])) {
                $sum[$field] = [$field, $kind, $exact, null, $exact, null, false];
            }
            $correction = $kind === FieldKind::Credit ? $row->getCorrection()->negate() : $row->getCorrection();
            $sum[$field][4] = $sum[$field][4]->add($correction);
            if ($row->getCorrectionBase() !== null) {
                $sum[$field][5] = ($sum[$field][5] ?? $zero)->add($row->getCorrectionBase());
            }
            $sum[$field][6] = true;
        }

        $result = [];
        foreach ($sum as $field => [$label, $kind, $declaredValue, $declaredBase, $corrections, $correctionsBase, $shown]) {
            $result[] = new VatYearField((string) $field, $label, $kind, $declaredValue, $declaredBase, $corrections, $correctionsBase, $shown);
        }

        return $result;
    }

    /**
     * The tab «Umsatz»: the revenue movements of the year against the declared turnover, with the
     * items of Art. 128 para. 2 MWSTV.
     *
     * @param list<VatYearPeriod>                                $periods
     * @param list<array{0: VatReturn, 1: list<VatReturnField>}> $declared
     * @param list<VatReturnCorrection>                          $rows
     * @param list<array<string, mixed>>                         $movements
     * @param list<VatReturnFinding>                             $findings appended to
     */
    private function turnover(VatTaxYear $year, array $periods, array $declared, array $rows, array $movements, string $currency, array &$findings): VatYearTurnover
    {
        $zero     = Money::zero($currency);
        $assets   = VatYearClassifier::fixedAssetAccountIds($this->em);
        $classes  = VatYearClassifier::turnover($movements, VatYearClassifier::categories($this->em), $currency, $assets);
        $taxYear  = $year->year;

        $revenueLines = [];
        $revenueTotal = $zero;
        foreach ($classes['revenue'] as $number => $r) {
            $revenueLines[] = new ReconciliationLine(ReconciliationLine::MOVEMENT, (string) $number, $r['name'], $r['lines'], $r['amount']);
            $revenueTotal   = $revenueTotal->add($r['amount']);
        }

        // Declared: field 200 − 235 of the live returns, as stored.
        $declaredTurnover = $zero;
        $reduction        = $zero;
        foreach ($declared as [, $fields]) {
            foreach ($fields as $f) {
                if ($f->kind === FieldKind::Total) {
                    $declaredTurnover = $declaredTurnover->add($f->value->rounded());
                } elseif ($f->kind === FieldKind::Reduction) {
                    $declaredTurnover = $declaredTurnover->subtract($f->value->rounded());
                    $reduction        = $reduction->add($f->value->rounded());
                }
            }
        }

        $items = [];

        // The unposted periods of the year: their turnover as computed now.
        $open       = $zero;
        $openLabels = [];
        foreach ($periods as $p) {
            if ($p->isPosted()) {
                continue;
            }
            $openLabels[] = $p->period->label();
            try {
                foreach ($this->returns->preview($p->period)->fields as $f) {
                    if ($f->kind === FieldKind::Total) {
                        $open = $open->add($f->value->rounded());
                    } elseif ($f->kind === FieldKind::Reduction) {
                        $open = $open->subtract($f->value->rounded());
                    }
                }
            } catch (VatReturnRefusedException $e) {
                $findings[] = new VatReturnFinding(false, self::FINDING_PERIOD_REFUSED, $p->period->label() . ': ' . $e->refusal->message, $e->refusal->fix);
            }
        }
        if ($openLabels !== []) {
            $items[] = new VatYearTurnoverItem(null, VatYearTurnoverItem::OPEN_PERIODS, 'Noch nicht gebucht, berechnet: ' . implode(', ', $openLabels), '', '', 0, $open, true);
        }

        // j — revenue without a tax code (the invoice-rounding account included; the finding leaves it out).
        $rounding = $this->invoiceRoundingAccount();
        foreach ($classes['uncoded'] as $number => $u) {
            $items[] = new VatYearTurnoverItem('j', VatYearTurnoverItem::UNCODED, 'Ertrag ohne MWST-Code', (string) $number, $u['name'], $u['lines'], $u['amount'], true);
            if ((string) $number !== $rounding && !$u['amount']->isZero()) {
                $findings[] = new VatReturnFinding(false, VatReturnFinding::UNCODED_REVENUE,
                    'Ertrag ohne MWST-Code: ' . AmountFormat::of($u['amount']) . ' auf ' . $number . ' ' . $u['name'] . " ({$taxYear}) — prüfen, ob es steuerbarer Umsatz ist.",
                    'account:' . $number, $u['amount']);
            }
        }
        if ($classes['uncoded'] === []) {
            $items[] = new VatYearTurnoverItem('j', VatYearTurnoverItem::UNCODED, 'Ertrag ohne MWST-Code', '', '', 0, $zero, true);
        }

        // b, d and the other types — output-coded lines outside the revenue accounts (the negated base).
        $expense = [];
        $fixed   = [];
        $other   = [];
        foreach ($classes['otherType'] as $number => $o) {
            if (isset($assets[$o['accountId']])) {
                $fixed[] = new VatYearTurnoverItem('d', VatYearTurnoverItem::FIXED_ASSET, 'Verkauf von Betriebsmitteln mit MWST-Code', (string) $number, $o['name'], $o['lines'], $o['amount'], true);
            } elseif ($o['type'] === AccountType::Expense->value) {
                $expense[] = new VatYearTurnoverItem('b', VatYearTurnoverItem::CODED_EXPENSE, 'Ertrag mit MWST-Code auf Aufwandkonten', (string) $number, $o['name'], $o['lines'], $o['amount'], true);
            } else {
                $other[] = new VatYearTurnoverItem(null, VatYearTurnoverItem::CODED_OTHER_TYPE, 'Umsatz mit MWST-Code auf anderem Kontotyp', (string) $number, $o['name'], $o['lines'], $o['amount'], true);
            }
        }
        foreach ($classes['assetCredits'] as $number => $a) {
            $fixed[] = new VatYearTurnoverItem('d', VatYearTurnoverItem::FIXED_ASSET_UNCODED, 'Haben ohne MWST-Code auf Anlagekonto — nicht deklariert, prüfen', (string) $number, $a['name'], $a['lines'], $a['amount'], false);
            $findings[] = new VatReturnFinding(false, VatReturnFinding::UNCODED_ASSET_CREDIT,
                'Anlagekonto «' . $number . ' ' . $a['name'] . '»: Haben ohne MWST-Code ' . AmountFormat::of($a['amount']) . " ({$taxYear}) — der Verkauf von Anlagevermögen ist steuerbarer Umsatz; prüfen.",
                'account:' . $number, $a['amount']);
        }
        array_push($items, ...($expense ?: [new VatYearTurnoverItem('b', VatYearTurnoverItem::CODED_EXPENSE, 'Ertrag mit MWST-Code auf Aufwandkonten', '', '', 0, $zero, true)]));
        array_push($items, ...($fixed ?: [new VatYearTurnoverItem('d', VatYearTurnoverItem::FIXED_ASSET, 'Verkauf von Betriebsmitteln (mit Code oder ohne Code auf Anlagekonten)', '', '', 0, $zero, true)]));
        array_push($items, ...$other);

        // h — field 235 as declared: already deducted, information only.
        $items[] = new VatYearTurnoverItem('h', VatYearTurnoverItem::REDUCTION, 'Entgeltsminderungen, Debitorenverluste (Ziffer 235) — in der Deklaration schon abgezogen', '', '', 0, $reduction, false);

        // The correction entries dated in the year: their revenue lines, by the year they correct.
        if ($classes['corrections'] !== []) {
            $corrects = [];
            foreach ($this->lines()->vatCorrectionEntries($year->from()->format('Y-m-d'), $year->to()->format('Y-m-d')) as $e) {
                $corrects[$e['entry_id']] = ['ref' => $e['year'] . '/' . $e['number'], 'year' => (int) substr($e['corrected_to'], 0, 4)];
            }
            $groups = [
                VatYearTurnoverItem::CORRECTION_SAME_YEAR    => ['label' => "Korrekturbuchungen für {$taxYear}", 'amount' => $zero, 'lines' => 0, 'refs' => []],
                VatYearTurnoverItem::CORRECTION_EARLIER_YEAR => ['label' => "Korrekturbuchungen für frühere Steuerjahre — nicht im Formular {$taxYear}", 'amount' => $zero, 'lines' => 0, 'refs' => []],
                VatYearTurnoverItem::CORRECTION_UNCODED      => ['label' => 'Korrekturbuchungen, Zeilen ohne MWST-Code', 'amount' => $zero, 'lines' => 0, 'refs' => []],
            ];
            foreach ($classes['corrections'] as $entryId => $c) {
                $ref  = $corrects[$entryId]['ref'] ?? (string) $entryId;
                $kind = ($corrects[$entryId]['year'] ?? $taxYear) === $taxYear ? VatYearTurnoverItem::CORRECTION_SAME_YEAR : VatYearTurnoverItem::CORRECTION_EARLIER_YEAR;
                foreach ([$kind => $c['coded'], VatYearTurnoverItem::CORRECTION_UNCODED => $c['uncoded']] as $k => $amount) {
                    if ($amount->isZero()) {
                        continue;
                    }
                    $groups[$k]['amount'] = $groups[$k]['amount']->add($amount);
                    $groups[$k]['refs'][] = $ref;
                }
                $groups[$kind]['lines'] += $c['lines'];
            }
            foreach ($groups as $kind => $g) {
                if ($g['refs'] !== []) {
                    $items[] = new VatYearTurnoverItem(null, $kind, $g['label'] . ' (' . implode(', ', array_unique($g['refs'])) . ')', '', '', $g['lines'], $g['amount'], true);
                }
            }
        }

        // c, e, f, g — what z77 cannot know.
        foreach (self::BY_HAND as $letter => $label) {
            $items[] = new VatYearTurnoverItem($letter, VatYearTurnoverItem::BY_HAND, $label, '', '', 0, null, false);
        }

        // Beside the equation: the corrections for the year, all reported ones and those of later years.
        $laterReported = $zero;
        $allReported   = $zero;
        foreach ($rows as $row) {
            $amount = match ($row->getKind()) {
                FieldKind::Total     => $row->getCorrection()->rounded(),
                FieldKind::Reduction => $row->getCorrection()->rounded()->negate(),
                default              => null,
            };
            if ($amount === null) {
                continue;
            }
            $allReported = $allReported->add($amount);
            if ($row->getVatReturn()->getPeriodFrom()->format('Y-m-d') > $year->lastDay()->format('Y-m-d')) {
                $laterReported = $laterReported->add($amount);
            }
        }

        return new VatYearTurnover($revenueLines, $revenueTotal, $declaredTurnover, $items, $laterReported, $declaredTurnover->add($allReported));
    }

    /**
     * The tab «Vorsteuer»: per VAT account its movement in the year, the tax of the coded lines,
     * and what the live returns declared for it (their blocks included).
     *
     * @param list<array{0: VatReturn, 1: list<VatReturnField>}> $declared
     * @param list<array<string, mixed>>                         $movements
     * @return list<VatYearAccount>
     */
    private function accounts(VatTaxYear $year, array $declared, array $movements, string $currency): array
    {
        $zero = Money::zero($currency);
        $map  = new VatAccountMap($this->em);
        $slots = [];   // number => [role, fields, expected, declared]
        $role  = static fn(string $key): string => $key === LedgerService::VAT_OWED_ACCOUNT ? VatAccountReconciliation::OWED : VatAccountReconciliation::INPUT;
        foreach ($map->accounts() as $number => $key) {
            $slots[(string) $number] ??= ['role' => $role($key), 'fields' => [], 'expected' => $zero, 'declared' => $zero];
        }
        if ($slots === []) {
            return [];   // no VAT account named, or the record unreadable — nothing to reconcile
        }

        // Expected: the tax of every coded row, by the table of the lock (rate date: the year's last settled-for day).
        $taxLines = [];
        foreach ($movements as $m) {
            if ($m['tax_code'] === null) {
                continue;
            }
            try {
                $taxLines[$m['tax_code']] ??= $map->taxLines($m['tax_code'], $year->to());
            } catch (VatException | VatAccountUnavailableException) {
                $taxLines[$m['tax_code']] = [];   // an unknown code shows as a remainder
            }
            foreach ($taxLines[$m['tax_code']] as $line) {
                $number = (string) $line['account'];
                if ($number === '' || !isset($slots[$number])) {
                    continue;
                }
                $signed = Money::fromDecimal($m['tax'], $currency)->multiply($line['sign']);
                $slots[$number]['expected'] = $slots[$number]['role'] === VatAccountReconciliation::OWED
                    ? $slots[$number]['expected']->subtract($signed)
                    : $slots[$number]['expected']->add($signed);
            }
        }

        // Declared: per return, the owed account its tax total, an input account its input fields; the blocks it carried.
        foreach ($declared as [$return, $fields]) {
            $owners = $this->fieldAccounts($return->period());
            foreach ($fields as $f) {
                $this->declare($slots, $owners, $f->field, $f->kind, $f->rounded);
            }
            foreach ($this->returns->declaredCorrectionBlocks($return) as $block) {
                $blockOwners = $this->fieldAccounts($block->period);
                foreach ($block->rows as $row) {
                    $this->declare($slots, $blockOwners, $row['field'], $row['kind'], $row['correction']->rounded());
                }
            }
        }

        // Movement: every row on the account, on its natural side.
        $moved = [];
        $names = [];
        foreach ($movements as $m) {
            $number = (string) $m['number'];
            if (!isset($slots[$number])) {
                continue;
            }
            $names[$number] = $m['name'];
            $net            = Money::fromDecimal($m['debit'], $currency)->subtract(Money::fromDecimal($m['credit'], $currency));
            $moved[$number] = ($moved[$number] ?? $zero)->add($slots[$number]['role'] === VatAccountReconciliation::OWED ? $net->negate() : $net);
        }

        $result = [];
        foreach ($slots as $number => $slot) {
            $fields = array_values(array_unique($slot['fields']));
            sort($fields, SORT_STRING);
            $result[] = new VatYearAccount($slot['role'], (string) $number, $names[$number] ?? $this->accountName((string) $number), $fields, $moved[$number] ?? $zero, $slot['expected'], $slot['declared']);
        }
        usort($result, static fn(VatYearAccount $a, VatYearAccount $b): int => [$a->role !== VatAccountReconciliation::OWED, $a->accountNumber] <=> [$b->role !== VatAccountReconciliation::OWED, $b->accountNumber]);

        return $result;
    }

    /**
     * Adds a declared field to the account it is declared on: the tax total to the owed account, an
     * input field (+) or input-correction field (−) to its input account.
     *
     * @param array<string, array{role: string, fields: list<string>, expected: Money, declared: Money}> $slots
     * @param array{owed: ?string, input: array<string, string>} $owners
     */
    private function declare(array &$slots, array $owners, string $field, FieldKind $kind, Money $amount): void
    {
        $number = match ($kind) {
            FieldKind::TaxTotal                         => $owners['owed'],
            FieldKind::Input, FieldKind::InputCorrection => $owners['input'][$field] ?? null,
            default                                     => null,
        };
        if ($number === null || !isset($slots[$number])) {
            return;
        }
        $slots[$number]['declared'] = $kind === FieldKind::InputCorrection ? $slots[$number]['declared']->subtract($amount) : $slots[$number]['declared']->add($amount);
        $slots[$number]['fields'][] = $field;
    }

    /**
     * Which account each input field of the period's form edition is declared on — through the
     * categories whose rule fills it (`LedgerService::vatAccountKey()` → the mandator's number) —
     * and the owed account.
     *
     * @return array{owed: ?string, input: array<string, string>}
     */
    private function fieldAccounts(VatPeriod $period): array
    {
        $owners = ['owed' => null, 'input' => []];
        try {
            $ledger  = new LedgerService($this->em);
            $edition = $this->edition($period);
            $owners['owed'] = $ledger->vatAccountFor(TaxCategory::Standard->value);
            foreach (TaxCategory::cases() as $category) {
                $kind = FieldKind::forCategory($category->value);
                if ($kind !== FieldKind::Input && $kind !== FieldKind::InputCorrection) {
                    continue;
                }
                $number = $ledger->vatAccountFor($category->value);
                if ($number === null) {
                    continue;
                }
                try {
                    $owners['input'][$edition->assign('', $category->value, 0)->field] = $number;
                } catch (VatException) {
                    // no rule for the category in this edition — nothing declared on it
                }
            }
        } catch (VatException | VatAccountUnavailableException) {
            // no edition, or the record unreadable — nothing is assigned
        }

        return $owners;
    }

    /** The year-end transit account 2209 (B-1): its balance per 31.12. and what of it is still open today. */
    private function correctionPending(VatTaxYear $year): ?VatYearBalance
    {
        $transit = new VatCorrectionTransit($this->em);
        $number  = $transit->account();
        $name    = $number === null ? null : $this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getName();
        if ($name === null) {
            return null;
        }

        return new VatYearBalance($number, $name, $transit->balanceAt($year->lastDay()), $transit->openAfter($year->lastDay()));
    }

    /** The balance per $day of a VAT-return account of the mandator; null when none is named or it is no account of the chart. */
    private function balance(string $key, \DateTimeImmutable $day): ?VatYearBalance
    {
        try {
            $number = (new LedgerService($this->em))->vatReturnAccount($key);
        } catch (MandatorUnavailableException) {
            return null;
        }
        $account = $number === null ? null : $this->em->getRepository(Account::class)->findOneBy(['number' => $number]);
        if ($account === null) {
            return null;
        }
        $fiscalYear = $this->fiscalYears()->findByDate($day);
        $currency   = LedgerService::baseCurrency();
        $balance    = $fiscalYear === null
            ? Money::zero($currency)
            : Money::fromDecimal($this->lines()->balanceAtOpeningOf((int) $account->getId(), (int) $fiscalYear->getId(), $day->modify('+1 day')->format('Y-m-d'), $this->openingAccountId()), $currency);

        return new VatYearBalance((string) $number, $account->getName(), $balance);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The fiscal years the calendar year touches, in order.
     *
     * @return list<FiscalYear>
     */
    private function touching(VatTaxYear $year): array
    {
        $from  = $year->firstDay()->format('Y-m-d');
        $to    = $year->lastDay()->format('Y-m-d');
        $years = array_values(array_filter($this->fiscalYears()->findAll(), static fn(FiscalYear $y): bool
            => $y->getStartDate()->format('Y-m-d') <= $to && $y->getEndDate()->format('Y-m-d') >= $from));
        usort($years, static fn(FiscalYear $a, FiscalYear $b): int => $a->getStartDate() <=> $b->getStartDate());

        return $years;
    }

    /** @return list<int> the ids of the fiscal years the settled-for days of the year touch */
    private function yearIds(VatTaxYear $year): array
    {
        $from = $year->from()->format('Y-m-d');
        $to   = $year->to()->format('Y-m-d');
        $ids  = [];
        foreach ($this->fiscalYears()->findAll() as $fiscalYear) {
            if ($fiscalYear->getStartDate()->format('Y-m-d') <= $to && $fiscalYear->getEndDate()->format('Y-m-d') >= $from) {
                $ids[] = (int) $fiscalYear->getId();
            }
        }
        sort($ids);

        return $ids;
    }

    /** @throws FormMappingException no edition covers the period */
    private function edition(VatPeriod $period): FormEdition
    {
        return ($this->mapping ??= FormMapping::forCountry(VatReturnService::COUNTRY))->edition($period->from, $period->to);
    }

    private function openingAccountId(): int
    {
        try {
            $number = (new LedgerService($this->em))->yearOpeningAccount(LedgerService::OPENING_BALANCE_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return 0;
        }

        return $number === null ? 0 : (int) ($this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getId() ?? 0);
    }

    private function invoiceRoundingAccount(): ?string
    {
        try {
            return (new LedgerService($this->em))->invoiceRoundingAccount();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    private function accountName(string $number): string
    {
        return (string) $this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getName();
    }

    private function fiscalYears(): FiscalYearRepository
    {
        return $this->em->getRepository(FiscalYear::class);
    }

    private function lines(): JournalLineRepository
    {
        return $this->em->getRepository(JournalLine::class);
    }

    /**
     * The overview as plain data for the fingerprint: amounts as decimals, days as `Y-m-d`, a return
     * as its id, revision and posting moment, a fiscal year as its code and state — every public
     * property, recursively.
     */
    private static function canonical(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Money              => $value->currency . ' ' . $value->toDecimal(),
            $value instanceof ExactAmount        => $value->currency . ' ' . $value->toDecimal(),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum        => $value->value,
            $value instanceof VatPeriod          => $value->from->format('Y-m-d') . '/' . $value->to->format('Y-m-d'),
            $value instanceof VatReturn          => 'return ' . $value->getId() . '/' . $value->getRevision() . '/' . $value->getPostedAt()->format('Y-m-d H:i:s'),
            $value instanceof FiscalYear         => 'fiscal-year ' . $value->getCode() . ($value->isClosed() ? ' closed' : ' open'),
            is_array($value)                     => array_map(self::canonical(...), $value),
            is_object($value)                    => array_map(self::canonical(...), get_object_vars($value)),
            default                              => $value,
        };
    }
}
