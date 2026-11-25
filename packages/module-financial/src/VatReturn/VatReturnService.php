<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\AccountType;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnState;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\JournalLineRepository;
use Z77\Module\Financial\Repositories\VatReturnLineSearch;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Financial\Services\YearOpeningService;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Mandator\Ui\MandatorLayout;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Module\Vat\CountryPack\FormEdition;
use Z77\Module\Vat\CountryPack\FormMapping;
use Z77\Module\Vat\CountryPack\FormMappingException;
use Z77\Module\Vat\CountryPack\UnmappedTaxCodeException;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * The VAT return, READ-ONLY (P5 part 2, build stage S1 — ADR-041 / ADR-042 addenda 2026-11-24):
 * between the journal and the pure {@see VatReturnCalculator}. Nothing is written — no entity, no
 * posting, no lock; every answer is computed from the journal on the call and cached for the
 * request on this instance (one instance per request), never stored.
 *
 * What a controller asks, in the order of the screens:
 *
 *   - {@see settingsRefusal()} — the gate: the mandator's {@see VatSettings} (not liable, method or
 *     consideration not set or not effective + agreed, rhythm or «Abrechnung ab» not set), each
 *     refusal a typed reason with a German sentence and the place that clears it;
 *   - {@see periods()} — the list: the periods that END in a fiscal year (screen decision 3), each
 *     with its state, method and payable amount; {@see nextReturn()} — the action cell
 *     «Neue Abrechnung»; {@see periodOn()} — the period of a day in the address;
 *   - {@see preview()} — one period: the form ({@see VatReturnForm}), every field with its flag
 *     «shown», the tree of «Herkunft»; it refuses a period holding an unmapped or unknown code +
 *     rate;
 *   - {@see lineSearch()} + {@see lines()} / {@see countLines()} / {@see lineTotals()} — the entries
 *     of a field › origin › account as a standard list;
 *   - {@see reconciliation()} — the tab «Abstimmung»;
 *   - {@see findings()} — the open-work registry (scope {@see SCOPE}) plus the return's own checks;
 *   - {@see declaredFields()} — the stored figures of a posted return; {@see changedSincePosting()}
 *     — whether its recomputed «Herkunft» / «Abstimmung» still match what was posted.
 *
 * Whether a period is settled comes from the posted returns (stage S2), asked of
 * {@see VatSettlement} — {@see isSettled()}, the state of {@see periods()} and {@see preview()},
 * {@see nextReturn()}; this class writes none.
 *
 * Nothing before «Abrechnung ab» is read: every period comes from the calendar, which starts there.
 * The journal is read through `JournalLineRepository` (aggregates, never hydrated lines).
 */
final class VatReturnService
{
    /** The open-work scope the preview asks (parameters `from`, `to` — `DateTimeImmutable`). */
    public const SCOPE = 'vat-return';

    /**
     * The source type of the return's own SETTLEMENT entries (stage S2, `VatReturnPostingService`) — left out
     * of every movement the reconciliation compares (ADR-041 addendum point 6, ADR-042 addendum
     * point 7: «the entries of the source `vat-return`»). The one place it is named.
     */
    public const SOURCE_TYPE = 'vat-return';

    /**
     * The country pack the return is computed with. One place: the mandator record carries no VAT
     * country yet, and CH is the only pack (`module-vat/res/country-packs/`).
     */
    public const COUNTRY = 'CH';

    /**
     * The chart group whose accounts count as FIXED ASSETS for the warning «a credit line without a
     * tax code on a fixed-asset account» (ADR-042 addendum point 1, review T4): every postable
     * account whose parent chain reaches the group numbered `14` — «Anlagevermögen» of the KMU chart.
     * OPEN (owner): the chart has no fixed-asset type or flag (`AccountType` knows asset only); this
     * group number is the conservative reading in ONE place — a chart without a group `14` gets no
     * such warning. The value-adjustment accounts under it are left out ({@see isValueAdjustment()}).
     */
    public const FIXED_ASSET_GROUP = '14';

    /**
     * How the KMU chart names a value-adjustment account («Wertberichtigungen Mobiliar und
     * Einrichtungen», 1519): the yearly depreciation is credited there, never a sale — so a credit on
     * it is no reason to warn (S1b, the rough edge of S1a). Read together with the number ending in
     * `9` ({@see isValueAdjustment()}), so a renamed or renumbered account warns as before.
     */
    public const VALUE_ADJUSTMENT_PREFIX = 'Wertberichtigung';

    /**
     * The tax categories whose tax lies on a VAT account the mandator names
     * (`LedgerService::vatAccountFor()`) — the ONE list: {@see sharedVatAccounts()} checks and
     * `VatReturnPostingService` locks exactly their accounts at step 2 of its lock order; which
     * account and sign a code expects is `VatAccountMap`'s ({@see VatAccountExpectation}). The
     * three input-tax corrections are here since stage S4 — they post to `vat-input-other`
     * (ADR-041 addendum 2026-11-25, point 3). Reverse charge needs no entry: its two tax lines lie
     * on the owed and an input account, both named by the categories above. A method, not a
     * constant: PHP evaluates every class constant when the class is first instantiated, and the
     * menu condition instantiates this service on every backend page — an enum of module-vat in a
     * constant would be loaded there for nothing.
     *
     * @return list<TaxCategory>
     */
    public static function vatAccountCategories(): array
    {
        return [
            TaxCategory::Standard, TaxCategory::Reduced, TaxCategory::Special, TaxCategory::InputMaterial, TaxCategory::InputOther,
            TaxCategory::InputSubsequent, TaxCategory::InputCorrection, TaxCategory::InputReduction,
        ];
    }

    /** Entries the movements of the reconciliation leave out: the year opening and the return's own settlement entries. */
    private const NOT_MOVEMENTS = [YearOpeningService::SOURCE_TYPE, self::SOURCE_TYPE];

    /** Half a Rappen per coded document (ADR-041 addendum point 13) — in halves of a minor unit. */
    private const ROUNDING_HALVES_PER_DOCUMENT = 1;

    /** The recomputed views of a posted return {@see changedSincePosting()} answers for — the tabs «Herkunft» and «Abstimmung». */
    public const VIEW_ORIGIN         = 'origin';
    public const VIEW_RECONCILIATION = 'reconciliation';

    /**
     * The findings the tab «Abstimmung» shows as a remainder or a named difference — what
     * {@see changedSincePosting()} compares a posted return's reconciliation by.
     */
    /**
     * The warning «Bezugsteuer mit vollem Vorsteuerabzug gerechnet» — a `VatReturnFinding` code of
     * the return's own checks (stage S6): z77 assumes full deduction; partial deduction is not
     * built and is corrected by hand (field 415).
     */
    public const FINDING_REVERSE_CHARGE_FULL_DEDUCTION = 'reverse-charge-full-deduction';

    private const RECONCILIATION_FINDINGS = [
        VatReturnFinding::VAT_ACCOUNT_REMAINDER,
        VatReturnFinding::VAT_ACCOUNT_MISSING,
        VatReturnFinding::VAT_ACCOUNT_SHARED,
        VatReturnFinding::TURNOVER_REMAINDER,
        VatReturnFinding::UNCODED_REVENUE,
    ];

    private ?VatSettings $settings;

    private ?OpenWorkChecks $checks;

    private ?VatReturnSources $sources;

    private ?FormMapping $mapping = null;

    private ?VatSettlement $settlement = null;

    /** @var array<string, string>|null tax code → category */
    private ?array $categories = null;

    /** @var array<string, array{preview: VatReturnPreview, edition: FormEdition, contributions: list<array{field: string, group: VatReturnLineGroup, amount: Money}>, yearIds: list<int>}> */
    private array $computed = [];

    /** @var array<string, VatReturnRefusedException> */
    private array $refused = [];

    /** @var array<string, VatReturnReconciliation> */
    private array $reconciliations = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $movements = [];

    /**
     * @param VatSettings|null      $settings the mandator's settings; null = read through {@see CurrentMandator::vatSettings()} (a harness passes its own)
     * @param OpenWorkChecks|null   $checks   the registry; null = every registered module's
     * @param VatReturnSources|null $sources  the origin declarations; null = every registered module's
     */
    public function __construct(
        private readonly UnifiedEntityManager $em,
        ?VatSettings $settings = null,
        ?OpenWorkChecks $checks = null,
        ?VatReturnSources $sources = null,
    ) {
        $this->settings = $settings;
        $this->checks   = $checks;
        $this->sources  = $sources;
    }

    // ── the gate ─────────────────────────────────────────────────────────

    /**
     * Why there is no VAT return for this mandator — null when the settings allow one. Lock-free;
     * what the screen (and the menu entry) asks first. Point 4: a mandator that is not liable has
     * none — unless «Abrechnung bis» is set, the screen stays for the periods up to that day.
     */
    public function settingsRefusal(): ?VatReturnRefusal
    {
        try {
            $s = $this->settings();
        } catch (MandatorUnavailableException $e) {
            return new VatReturnRefusal(VatReturnRefusal::MANDATOR_UNAVAILABLE, $e->getMessage(), 'mandator');
        }
        $place = VatReturnRefusal::SETTINGS_PLACE;
        if (!$s->liable && $s->until === null) {
            return new VatReturnRefusal(VatReturnRefusal::NOT_LIABLE, 'Der Mandant ist nicht mehrwertsteuerpflichtig — es gibt keine MWST-Abrechnung.', 'mandator:vat');
        }
        if ($s->method === null) {
            return new VatReturnRefusal(VatReturnRefusal::METHOD_NOT_SET, "Abrechnungsmethode nicht festgelegt — im Mandanten festlegen ({$place}).", 'mandator:vat');
        }
        if ($s->method !== VatMethod::Effective) {
            return new VatReturnRefusal(VatReturnRefusal::METHOD_UNSUPPORTED, '«' . MandatorLayout::VAT_METHODS[$s->method->value] . '» wird nicht unterstützt — z77 erstellt die MWST-Abrechnung nur nach der effektiven Methode mit vereinbartem Entgelt.', 'mandator:vat');
        }
        if ($s->consideration === null) {
            return new VatReturnRefusal(VatReturnRefusal::CONSIDERATION_NOT_SET, "Abrechnungsart nicht festgelegt — im Mandanten festlegen ({$place}).", 'mandator:vat');
        }
        if ($s->consideration !== VatConsideration::Agreed) {
            return new VatReturnRefusal(VatReturnRefusal::CONSIDERATION_UNSUPPORTED, '«' . MandatorLayout::VAT_CONSIDERATIONS[$s->consideration->value] . '» wird nicht unterstützt — z77 erstellt die MWST-Abrechnung nur nach der effektiven Methode mit vereinbartem Entgelt.', 'mandator:vat');
        }
        if ($s->rhythm === null) {
            return new VatReturnRefusal(VatReturnRefusal::RHYTHM_NOT_SET, "Abrechnungsrhythmus nicht festgelegt — im Mandanten festlegen ({$place}).", 'mandator:vat');
        }
        if ($s->from === null) {
            return new VatReturnRefusal(VatReturnRefusal::FROM_NOT_SET, "«Abrechnung ab» nicht festgelegt — im Mandanten festlegen ({$place}).", 'mandator:vat');
        }

        return null;
    }

    /** «Effektive Methode · Vereinbartes Entgelt» — what the list and the print name the method by. */
    public function methodLabel(): string
    {
        $s = $this->assertSettings();

        return MandatorLayout::VAT_METHODS[$s->method->value] . ' · ' . MandatorLayout::VAT_CONSIDERATIONS[$s->consideration->value];
    }

    // ── the periods ──────────────────────────────────────────────────────

    /**
     * The settlement calendar of the mandator: its rhythms by year (`VatSettings::rhythmsByYear()` —
     * a rhythm change takes effect on the 1 January of «Rhythmus gilt ab», the years before keep
     * their rhythm) from «Abrechnung ab», cut at «Abrechnung bis».
     *
     * @throws VatReturnRefusedException the settings gate
     */
    public function calendar(): VatPeriodCalendar
    {
        $s = $this->assertSettings();

        return new VatPeriodCalendar($s->rhythmsByYear(), $s->from, $s->until);
    }

    /**
     * The settlement period of a day — what a URL `?from=2026-07-01` is resolved by.
     *
     * @throws VatReturnRefusedException the settings gate, or the day lies outside the liability ({@see VatReturnRefusal::NO_PERIOD})
     */
    public function periodOn(\DateTimeImmutable $day): VatPeriod
    {
        return $this->calendar()->periodOn($day) ?? throw new VatReturnRefusedException(new VatReturnRefusal(
            VatReturnRefusal::NO_PERIOD,
            'Am ' . $day->format('d.m.Y') . ' ist keine MWST-Periode — abgerechnet wird ab ' . $this->settings()->from->format('d.m.Y')
                . ($this->settings()->until !== null ? ' bis ' . $this->settings()->until->format('d.m.Y') : '') . '.',
            'mandator:vat'
        ));
    }

    /**
     * The list of returns for a fiscal year: the periods that END in it (screen decision 3 — the
     * rule of the year-close check; a period that began in the year before belongs here, one that
     * ends after the year does not), in order. Each with its payable amount computed from the
     * journal (one aggregate query per period, cached on this instance) — or the refusal of that
     * period.
     *
     * @return list<VatReturnPeriodSummary>
     * @throws VatReturnRefusedException the settings gate
     */
    public function periods(FiscalYear $year, ?\DateTimeImmutable $today = null): array
    {
        $today  = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $label  = $this->methodLabel();
        // Every return of the year in ONE read (live and lifted, the latest revision first per period).
        $returns = $this->settlement()->returnsEndingBetween($year->getStartDate(), $year->getEndDate());
        $result  = [];
        foreach ($this->calendar()->periodsEndingBetween($year->getStartDate(), $year->getEndDate()) as $period) {
            $posted = $this->settlement()->returnCovering($period->to);
            if ($posted !== null) {
                // Settled: the DECLARED payable amount — nothing is recomputed for a posted period.
                $result[] = new VatReturnPeriodSummary($period, VatReturnPreview::STATE_POSTED, VatReturnPreview::STATE_LABELS[VatReturnPreview::STATE_POSTED],
                    $label, $period->to < $today, $posted->getPayable(), null, $posted);
                continue;
            }
            $payable = null;
            $refusal = null;
            try {
                $payable = $this->preview($period)->payable();
            } catch (VatReturnRefusedException $e) {
                $refusal = $e->refusal;
            }
            $lifted = null;
            foreach ($returns as $return) {
                if ($return->getPeriodFrom()->format('Y-m-d') === $period->from->format('Y-m-d') && $return->getState() === VatReturnState::Lifted) {
                    $lifted = $return;   // the latest revision comes first
                    break;
                }
            }
            $result[] = new VatReturnPeriodSummary($period, VatReturnPreview::STATE_OPEN, VatReturnPreview::STATE_LABELS[VatReturnPreview::STATE_OPEN],
                $label, $period->to < $today, $payable, $refusal, null, $lifted);
        }

        return $result;
    }

    /**
     * «Neue Abrechnung» (screen decision 1 of 2026-11-24, renamed by owner decision 3 of 2026-11-25): the first period that has ENDED and is not settled —
     * null when there is none (nothing elapsed yet, or every elapsed period settled).
     *
     * @throws VatReturnRefusedException the settings gate
     */
    public function nextReturn(?\DateTimeImmutable $today = null): ?VatPeriod
    {
        $today    = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $calendar = $this->calendar();
        for ($period = $calendar->first(); $period !== null && $period->to < $today; $period = $calendar->next($period)) {
            if (!$this->isSettled($period)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Whether a posted return covers the period (ADR-042 addendum point 2: «settled» is read from
     * the posted returns) — a live ORDINARY return covering its last day, asked of
     * {@see VatSettlement}, the one reader. Lock-free.
     */
    public function isSettled(VatPeriod $period): bool
    {
        return $this->settlement()->isSettled($period->to);
    }

    /** The reader of «settled» this instance asks — one per instance, like every other answer here. */
    public function settlement(): VatSettlement
    {
        return $this->settlement ??= new VatSettlement($this->em);
    }

    // ── one period ───────────────────────────────────────────────────────

    /**
     * The return of one period, computed from its coded journal lines: ONE aggregate query, the
     * calculator, the field rows and the tree of «Herkunft». Cached on this instance.
     *
     * @throws VatReturnRefusedException the settings gate; no form edition; an unmapped or unknown code
     */
    public function preview(VatPeriod $period): VatReturnPreview
    {
        return $this->compute($period)['preview'];
    }

    /**
     * The DECLARED form of a posted (or lifted) return — its stored figures (`vat_return_figure`),
     * never recomputed (owner decision of 2026-11-25: a posted return shows what was posted). One
     * {@see VatReturnField} per stored figure, in form order; the label from the rule file's
     * edition of the period (the field number when that edition no longer knows it), `shown` by
     * the rule of the computed form (the totals always, 500 or 510 by the sign of the declared
     * payable amount, every other field with an amount). Doctrine-only (one query).
     *
     * @return list<VatReturnField>
     */
    public function declaredFields(VatReturn $return): array
    {
        try {
            $edition = $this->edition($return->period());
            $labels  = $edition->fields();
        } catch (VatReturnRefusedException) {
            $labels  = [];
            $edition = null;
        }
        /** @var VatReturnRepository $returns */
        $returns = $this->em->getRepository(VatReturn::class);
        $always  = [FieldKind::DeductionsTotal, FieldKind::TaxableTotal, FieldKind::TaxTotal, FieldKind::InputTotal];
        $rows    = [];
        foreach ($returns->figuresOf($return) as $figure) {
            $field = $figure->getField();
            $kind  = $figure->getKind();
            $value = $figure->getValueExact();
            $base  = $figure->getBase();
            $shown = match (true) {
                in_array($kind, $always, true) => true,
                $kind === FieldKind::Payable   => !$return->getPayable()->isNegative(),
                $kind === FieldKind::Credit    => $return->getPayable()->isNegative(),
                default                        => !$value->isZero() || ($base !== null && !$base->isZero()),
            };
            $label = $edition !== null && isset($labels[$field]) ? $edition->label($field) : $field;
            $rows[] = new VatReturnField($field, $label, $kind, $value, $figure->getValueRounded(), $base, $figure->getLineCount(), $shown);
        }

        return $rows;
    }

    /**
     * Does the RECOMPUTED view $view of a posted return differ from what was posted? «Herkunft» and
     * «Abstimmung» of a posted return stay computed from the journal (owner 2026-11-25,
     * FIN-VAT-POSTED-VIEWS-001); the tab says so in one line when they no longer match the archived
     * voucher. Decided from what the return stored at posting — no table of its own:
     *
     *   - the figures (both views): a recomputed form field whose exact value, base or line count
     *     differs from its stored `vat_return_figure` row, or a field on one side only. Tax lines of
     *     a settled period are frozen, so this is a changed rule file or tax-code category;
     *   - «Abstimmung» besides: a reconciliation finding ({@see RECONCILIATION_FINDINGS}) the posted
     *     return did not carry — a BLOCKING one always (the return could not be posted with it: a
     *     gap on a VAT account, a missing or shared account), a warning when its sentence is not
     *     among the warnings confirmed at posting (an uncoded revenue line, an unexplained turnover
     *     remainder). Both arise from a later entry WITHOUT a tax code dated into the period.
     *
     * A lifted revision answers false: its page shows the stored figures only, no recomputed view.
     * The ONE place the two tabs ask; the templates compare nothing.
     *
     * @param self::VIEW_ORIGIN|self::VIEW_RECONCILIATION $view
     * @throws VatReturnRefusedException the recomputation refuses (see {@see preview()})
     */
    public function changedSincePosting(VatReturn $return, string $view): bool
    {
        if (!in_array($view, [self::VIEW_ORIGIN, self::VIEW_RECONCILIATION], true)) {
            throw new \InvalidArgumentException("Unknown view of a VAT return: {$view}");
        }
        if (!$return->isLive()) {
            return false;
        }
        $period = $return->period();
        if ($this->figuresDiffer($return, $this->preview($period))) {
            return true;
        }
        if ($view === self::VIEW_ORIGIN) {
            return false;
        }
        $confirmed = $return->getConfirmedWarnings();
        foreach ($this->findings($period) as $finding) {
            if (in_array($finding->code, self::RECONCILIATION_FINDINGS, true) && ($finding->blocking || !in_array($finding->message, $confirmed, true))) {
                return true;
            }
        }

        return false;
    }

    /** True when the recomputed fields of $preview are not the stored figures of $return, field for field. */
    private function figuresDiffer(VatReturn $return, VatReturnPreview $preview): bool
    {
        /** @var VatReturnRepository $returns */
        $returns = $this->em->getRepository(VatReturn::class);
        $stored  = [];
        foreach ($returns->figuresOf($return) as $figure) {
            $stored[$figure->getField()] = $figure;
        }
        if (count($stored) !== count($preview->fields)) {
            return true;
        }
        foreach ($preview->fields as $field) {
            $figure = $stored[$field->field] ?? null;
            $base   = $figure?->getBase();
            if ($figure === null || !$figure->getValueExact()->equals($field->value) || $figure->getLineCount() !== $field->lines
                || ($base === null) !== ($field->base === null) || ($base !== null && !$base->equals($field->base))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The search a list of entries starts from: the coded lines that make up one node of «Herkunft»
     * — a form field, optionally one display origin ({@see VatReturnSources::key()}) and one
     * account. Built from the very groups the form was computed from, so the list's total equals the
     * node. The list's own column searches and sort go on with {@see VatReturnLineSearch::narrowed()}.
     *
     * @throws VatReturnRefusedException see {@see preview()}
     * @throws \InvalidArgumentException not a field of the form
     */
    public function lineSearch(VatPeriod $period, string $field, ?string $origin = null, ?int $accountId = null): VatReturnLineSearch
    {
        $computed = $this->compute($period);
        $computed['edition']->kindOf($field);   // an unknown field is a programming error
        $selectors = [];
        foreach ($computed['contributions'] as $c) {
            $g = $c['group'];
            if ($c['field'] !== $field || ($origin !== null && $g->sourceKey !== $origin) || ($accountId !== null && $g->accountId !== $accountId)) {
                continue;
            }
            $key = $g->taxCode . '|' . $g->rate . '|' . $g->sourceKey;
            $selectors[$key] ??= ['code' => $g->taxCode, 'rate' => $g->rate, 'source' => $this->sources()->sourceTypeOf($g->sourceKey), 'reversal' => []];
            $flag = $g->reversal ? 1 : 0;
            if (!in_array($flag, $selectors[$key]['reversal'], true)) {
                $selectors[$key]['reversal'][] = $flag;
            }
        }

        return new VatReturnLineSearch($computed['yearIds'], $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), array_values($selectors), $accountId);
    }

    /**
     * One page of the lines of a search. Doctrine-only (SQL through the repository).
     *
     * @return list<VatReturnLine>
     */
    public function lines(VatReturnLineSearch $search, int $offset, int $limit): array
    {
        $currency = LedgerService::baseCurrency();

        return array_map(fn(array $r): VatReturnLine => new VatReturnLine(
            $r['line_id'],
            $r['entry_id'],
            $r['year'],
            $r['number'],
            new \DateTimeImmutable($r['entry_date']),
            $r['entry_text'],
            $r['line_text'],
            $r['account_id'],
            $r['account_number'],
            $r['account_name'],
            $r['tax_code'],
            $r['tax_rate'],
            Money::fromDecimal($r['base'], $currency),
            Money::fromDecimal($r['tax'], $currency),
            $r['source_type'],
            $r['source_ref'],
            $this->sources()->rowOrigin($r['source_type'], $r['reversal']),
        ), $this->journalLines()->vatReturnLines($search, $offset, $limit));
    }

    /** How many lines the search selects — the pager. */
    public function countLines(VatReturnLineSearch $search): int
    {
        return $this->journalLines()->countVatReturnLines($search);
    }

    /**
     * The total line of a list: count, Σ base, Σ posted tax of every line the search selects.
     *
     * @return array{lines: int, base: Money, tax: Money}
     */
    public function lineTotals(VatReturnLineSearch $search): array
    {
        $currency = LedgerService::baseCurrency();
        $t        = $this->journalLines()->vatReturnLineTotals($search);

        return ['lines' => $t['lines'], 'base' => Money::fromDecimal($t['base'], $currency), 'tax' => Money::fromDecimal($t['tax'], $currency)];
    }

    // ── the reconciliation ───────────────────────────────────────────────

    /**
     * The tab «Abstimmung» of one period ({@see VatReturnReconciliation}): the turnover — the
     * revenue accounts' movements against field 200 − 235, the named differences, the remainder —
     * and every VAT account against the tax its coded lines posted. ONE movement query (per account
     * and code), cached on this instance.
     *
     * @throws VatReturnRefusedException see {@see preview()}
     */
    public function reconciliation(VatPeriod $period): VatReturnReconciliation
    {
        $key = self::key($period);
        if (isset($this->reconciliations[$key])) {
            return $this->reconciliations[$key];
        }
        $computed   = $this->compute($period);
        $preview    = $computed['preview'];
        $edition    = $computed['edition'];
        $currency   = $preview->currency;
        $zero       = Money::zero($currency);
        $money      = static fn(string $decimal): Money => Money::fromDecimal($decimal, $currency);
        $categories = $this->categories();

        // (a) turnover — per revenue account: its movement, its uncoded lines; output codes on other types.
        $revenue = [];
        $uncoded = [];
        $other   = [];
        foreach ($this->movementsOf($period, $computed['yearIds']) as $m) {
            $net = $money($m['credit'])->subtract($money($m['debit']));
            if ($m['type'] === AccountType::Revenue->value) {
                $revenue[$m['number']] ??= ['name' => $m['name'], 'lines' => 0, 'amount' => $zero];
                $revenue[$m['number']]['lines'] += $m['lines'];
                $revenue[$m['number']]['amount'] = $revenue[$m['number']]['amount']->add($net);
                if ($m['tax_code'] === null) {
                    $uncoded[$m['number']] = new ReconciliationLine(ReconciliationLine::UNCODED, $m['number'], $m['name'], $m['lines'], $net);
                }
                continue;
            }
            $category = $m['tax_code'] === null ? null : ($categories[TaxCode::normalizeCode($m['tax_code'])] ?? null);
            if ($category !== null && self::isOutput($category)) {
                $other[$m['number']] ??= ['name' => $m['name'], 'lines' => 0, 'amount' => $zero];
                $other[$m['number']]['lines'] += $m['lines'];
                $other[$m['number']]['amount'] = $other[$m['number']]['amount']->subtract($money($m['base']));
            }
        }
        $revenueLines = [];
        $revenueTotal = $zero;
        foreach ($revenue as $number => $r) {
            $revenueLines[] = new ReconciliationLine(ReconciliationLine::MOVEMENT, (string) $number, $r['name'], $r['lines'], $r['amount']);
            $revenueTotal   = $revenueTotal->add($r['amount']);
        }
        $explained = array_values($uncoded);
        foreach ($other as $number => $o) {
            $explained[] = new ReconciliationLine(ReconciliationLine::CODED_OTHER_TYPE, (string) $number, $o['name'], $o['lines'], $o['amount']);
        }
        // Correction entries of the period (ReconciliationLine::CORRECTION): none exist before stage S3.

        $form = $preview->form;

        return $this->reconciliations[$key] = new VatReturnReconciliation(
            $revenueLines,
            $revenueTotal,
            $form->value($edition->fieldOf(FieldKind::Total))->rounded(),
            $form->value($edition->fieldOf(FieldKind::Reduction))->rounded(),
            $explained,
            $this->vatAccounts($period, $computed),
        );
    }

    // ── the findings ─────────────────────────────────────────────────────

    /**
     * What the preview shows above the form — blocking findings first, then the warnings
     * ({@see VatReturnFinding}): the open-work registry for the scope {@see SCOPE} over the period,
     * and the return's own checks — a failed plausibility check, one account named for owed VAT and
     * input tax, and a gap on a VAT account (all BLOCKING); every revenue account with lines without a tax code (except the invoice-rounding
     * account), every fixed-asset account with uncoded credit lines, an unexplained turnover
     * remainder, a negative field, exempt turnover without an input-tax correction, reverse charge
     * computed with full deduction, a rounding difference above half a Rappen per coded document
     * (warnings). A supplier voucher in a foreign currency or from a foreign address posted without
     * VAT is creditor's own check in the registry (ADR-042 addendum 2 of 2026-11-25, point 9).
     *
     * @return list<VatReturnFinding>
     * @throws VatReturnRefusedException see {@see preview()}
     */
    public function findings(VatPeriod $period): array
    {
        $computed = $this->compute($period);
        $preview  = $computed['preview'];
        $edition  = $computed['edition'];
        $form     = $preview->form;
        $reco     = $this->reconciliation($period);
        $found    = [];

        $open = $this->registry()->ask(self::SCOPE, ['from' => $period->from, 'to' => $period->to]);
        foreach ($open->blocking() as $f) {
            $found[] = new VatReturnFinding(true, VatReturnFinding::OPEN_WORK, $f->message, $f->reference);
        }
        foreach ($open->warnings() as $f) {
            $found[] = new VatReturnFinding(false, VatReturnFinding::OPEN_WORK, $f->message, $f->reference);
        }

        foreach ($form->checks as $check) {
            if ($check->severity === VatReturnCheck::ERROR && !$check->passed) {
                $found[] = new VatReturnFinding(true, VatReturnFinding::CHECK_FAILED,
                    'Plausibilitätsprüfung «' . $check->id . '» des Formulars nicht erfüllt (Feld ' . $check->field . ': ' . $check->actual?->toDecimal() . ' statt ' . $check->expected?->toDecimal() . ') — ein Fehler der Berechnung, bitte melden.',
                    'field:' . $check->field);
            } elseif ($check->id === VatReturnCheck::NEGATIVE_FIELD) {
                $amount  = $check->actual->rounded();
                $found[] = new VatReturnFinding(false, VatReturnFinding::NEGATIVE_FIELD,
                    'Feld ' . $check->field . ' «' . $edition->label($check->field) . '» ist negativ (' . AmountFormat::of($amount) . ') — möglich nach einer grossen Gutschrift für eine frühere Periode; bitte prüfen.',
                    'field:' . $check->field, $amount);
            } elseif ($check->id === VatReturnCheck::EXEMPT_WITHOUT_CORRECTION) {
                $amount  = $check->actual->rounded();
                $found[] = new VatReturnFinding(false, VatReturnFinding::EXEMPT_WITHOUT_CORRECTION,
                    'Von der Steuer ausgenommene Umsätze (' . AmountFormat::of($amount) . ', Feld ' . $check->field . ') ohne Vorsteuerkorrektur — wer ausgenommene Umsätze erzielt, korrigiert in der Regel die Vorsteuer (gemischte Verwendung, Feld 415). '
                    . 'Ausnahme: Zinserträge bis CHF 250 000 im Jahr verlangen keine Korrektur.',
                    'field:' . $check->field, $amount);
            }
        }

        // Reverse charge is computed with full input-tax deduction (ADR-041 addendum 2026-11-24, point 8) — a warning.
        $acquisition = ExactAmount::zero($preview->currency);
        $rcField     = null;
        foreach ($edition->fieldsOf(FieldKind::Acquisition) as $field) {
            if (!$form->value($field)->isZero()) {
                $acquisition = $acquisition->add($form->value($field));
                $rcField   ??= $field;
            }
        }
        if ($rcField !== null) {
            $amount  = $acquisition->rounded();
            $found[] = new VatReturnFinding(false, self::FINDING_REVERSE_CHARGE_FULL_DEDUCTION,
                'Bezugsteuer (' . AmountFormat::of($amount) . ') mit vollem Vorsteuerabzug gerechnet — wer nicht voll vorsteuerabzugsberechtigt ist (gemischte Verwendung, ausgenommene Umsätze), korrigiert den Abzug von Hand (Feld 415).',
                'field:' . $rcField, $amount);
        }

        $shared = [];
        try {
            $shared = $this->sharedVatAccounts();
        } catch (VatAccountUnavailableException $e) {
            $found[] = new VatReturnFinding(true, VatReturnFinding::VAT_ACCOUNT_MISSING, $e->getMessage(), 'mandator:accounts');
        }
        foreach ($shared as $number) {
            $found[] = new VatReturnFinding(true, VatReturnFinding::VAT_ACCOUNT_SHARED,
                'MWST-Konto «' . trim($number . ' ' . $this->accountName($number)) . '» ist zugleich für die geschuldete MWST und für die Vorsteuer hinterlegt — so stimmt es nie mit der Steuer der codierten Buchungen überein; für die Vorsteuer ein eigenes Konto ' . LedgerService::MANDATOR_HINT . '.',
                'mandator:accounts');
        }
        foreach ($reco->vatAccounts as $account) {
            if (in_array($account->accountNumber, $shared, true)) {
                continue;   // its remainder is the consequence of the shared account, not a posting to find in the account statement
            }
            if ($account->accountNumber === '') {
                $found[] = new VatReturnFinding(true, VatReturnFinding::VAT_ACCOUNT_MISSING,
                    'Für ' . ($account->role === VatAccountReconciliation::OWED ? 'die geschuldete MWST' : 'die Vorsteuer (Feld ' . implode(', ', $account->fields) . ')') . ' ist kein MWST-Konto hinterlegt — ' . LedgerService::MANDATOR_HINT . '.',
                    'mandator:accounts', $account->expected);
            } elseif (!$account->agrees()) {
                $found[] = new VatReturnFinding(true, VatReturnFinding::VAT_ACCOUNT_REMAINDER,
                    'MWST-Konto «' . trim($account->accountNumber . ' ' . $account->accountName) . '»: Bewegung ' . AmountFormat::of($account->movement) . ', Steuer der codierten Buchungen ' . AmountFormat::of($account->expected)
                    . ', Differenz ' . AmountFormat::of($account->difference()) . ' — eine Buchung auf dem Konto ohne MWST-Code; im Kontoblatt finden und korrigieren, bevor die Abrechnung gebucht wird.',
                    'account:' . $account->accountNumber, $account->difference());
            }
        }

        if (!$reco->remainder()->isZero()) {
            $found[] = new VatReturnFinding(false, VatReturnFinding::TURNOVER_REMAINDER,
                'Umsatzabstimmung: ' . AmountFormat::of($reco->remainder()) . ' zwischen den Ertragskonten und Feld 200 − 235 sind nicht erklärt — eine codierte Buchung, deren Basis vom Betrag abweicht, oder ein Vorsteuer-Code auf einem Ertragskonto (Reiter «Abstimmung»).',
                'reconciliation', $reco->remainder());
        }

        $rounding = $this->invoiceRoundingAccount();
        foreach ($reco->explained as $line) {
            if ($line->kind === ReconciliationLine::UNCODED && $line->accountNumber !== $rounding && !$line->amount->isZero()) {
                $found[] = new VatReturnFinding(false, VatReturnFinding::UNCODED_REVENUE,
                    'Ertragskonto «' . $line->accountNumber . ' ' . $line->accountName . '»: ' . self::count($line->lines, 'Buchung', 'Buchungen') . ' ohne MWST-Code, zusammen ' . AmountFormat::of($line->amount)
                    . ' — prüfen, ob es steuerbarer Umsatz ist (Verzugszins und Inkassokosten sind ohne Code richtig).',
                    'account:' . $line->accountNumber, $line->amount);
            }
        }

        $assets = $this->fixedAssetAccountIds();
        foreach ($this->movementsOf($period, $computed['yearIds']) as $m) {
            if ($m['tax_code'] === null && $m['credit_lines'] > 0 && isset($assets[$m['account_id']])) {
                $credit  = Money::fromDecimal($m['credit'], $preview->currency);
                $found[] = new VatReturnFinding(false, VatReturnFinding::UNCODED_ASSET_CREDIT,
                    'Anlagekonto «' . $m['number'] . ' ' . $m['name'] . '»: ' . self::count($m['credit_lines'], 'Haben-Buchung', 'Haben-Buchungen') . ' ohne MWST-Code, zusammen ' . AmountFormat::of($credit)
                    . ' — der Verkauf von Anlagevermögen ist steuerbarer Umsatz; prüfen.',
                    'account:' . $m['number'], $credit);
            }
        }

        $difference = $form->roundingDifference();
        $documents  = $this->journalLines()->vatReturnCodedEntries($computed['yearIds'], $period->from->format('Y-m-d'), $period->to->format('Y-m-d'));
        if (abs($difference->minor) * 2 > $documents * self::ROUNDING_HALVES_PER_DOCUMENT) {
            $found[] = new VatReturnFinding(false, VatReturnFinding::ROUNDING_DIFFERENCE,
                'Rundungsdifferenz ' . AmountFormat::of($difference) . ' zwischen gebuchter und abgerechneter Steuer — mehr als ein halber Rappen je Beleg (' . self::count($documents, 'Beleg', 'Belege') . ' mit MWST-Code); die Steuerbeträge der Belege prüfen.',
                'field:' . $edition->fieldOf(FieldKind::Payable), $difference);
        }

        usort($found, static fn(VatReturnFinding $a, VatReturnFinding $b): int => $b->blocking <=> $a->blocking);

        return $found;
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * @return array{preview: VatReturnPreview, edition: FormEdition, contributions: list<array{field: string, group: VatReturnLineGroup, amount: Money}>, yearIds: list<int>}
     * @throws VatReturnRefusedException
     */
    private function compute(VatPeriod $period): array
    {
        $key = self::key($period);
        if (isset($this->computed[$key])) {
            return $this->computed[$key];
        }
        if (isset($this->refused[$key])) {
            throw $this->refused[$key];
        }
        try {
            return $this->computed[$key] = $this->computeAnew($period);
        } catch (VatReturnRefusedException $e) {
            throw $this->refused[$key] = $e;
        }
    }

    /** @throws VatReturnRefusedException */
    private function computeAnew(VatPeriod $period): array
    {
        $settings = $this->assertSettings();
        $currency = LedgerService::baseCurrency();
        $edition  = $this->edition($period);
        $yearIds  = $this->yearIds($period);
        $groups   = $this->groups($period, $yearIds, $currency);

        try {
            $form          = (new VatReturnCalculator($edition))->calculate($currency, self::rowsOf($groups));
            $contributions = $this->contributions($edition, $groups);
        } catch (UnmappedTaxCodeException $e) {
            throw new VatReturnRefusedException(new VatReturnRefusal(
                VatReturnRefusal::UNMAPPED_CODE,
                'MWST-Code «' . $e->taxCode . '» (Kategorie «' . $e->category . '», Satz ' . self::percent($e->rate) . ') hat kein Feld im Formular «' . $e->edition . '» — Kategorie oder Satz des Codes prüfen (Stammdaten › Finanzen › MWST-Codes).',
                'tax-code:' . $e->taxCode
            ), $e);
        }

        $posted  = $this->settlement()->returnCovering($period->to);
        $preview = new VatReturnPreview(
            $period,
            $settings->method,
            $settings->consideration,
            $this->methodLabel(),
            $edition->name,
            $currency,
            $form,
            $this->fields($edition, $form, $contributions),
            $this->breakdown($edition, $contributions, $currency),
            $groups,
            self::rowsOf($groups),
            $posted === null ? VatReturnPreview::STATE_OPEN : VatReturnPreview::STATE_POSTED,
            $posted,
        );

        return ['preview' => $preview, 'edition' => $edition, 'contributions' => $contributions, 'yearIds' => $yearIds];
    }

    /**
     * The aggregate as groups, each classified — the ONE origin rule is {@see VatReturnSources::rowOrigin()}.
     *
     * @param list<int> $yearIds
     * @return list<VatReturnLineGroup>
     * @throws VatReturnRefusedException a code module-vat does not know
     */
    private function groups(VatPeriod $period, array $yearIds, string $currency): array
    {
        $categories = $this->categories();
        $groups     = [];
        foreach ($this->journalLines()->vatReturnAggregate($yearIds, $period->from->format('Y-m-d'), $period->to->format('Y-m-d')) as $r) {
            $category = $categories[TaxCode::normalizeCode($r['tax_code'])] ?? null;
            if ($category === null) {
                throw new VatReturnRefusedException(new VatReturnRefusal(
                    VatReturnRefusal::UNKNOWN_TAX_CODE,
                    'Journalzeilen tragen den MWST-Code «' . $r['tax_code'] . '», den es unter Stammdaten › Finanzen › MWST-Codes nicht gibt — den Code wieder anlegen (er wird nie gelöscht, nur deaktiviert).',
                    'tax-code:' . $r['tax_code']
                ));
            }
            $groups[] = new VatReturnLineGroup(
                $r['tax_code'],
                $category,
                $r['tax_rate'],
                $this->sources()->rowOrigin($r['source_type'], $r['reversal']),
                $this->sources()->key($r['source_type']),
                $r['reversal'],
                $r['account_id'],
                $r['number'],
                $r['name'],
                $r['type'],
                $r['lines'],
                Money::fromDecimal($r['base'], $currency),
                Money::fromDecimal($r['tax'], $currency),
            );
        }

        return $groups;
    }

    /**
     * The calculator's rows: the groups summed per code + category + rate + origin.
     *
     * @param list<VatReturnLineGroup> $groups
     * @return list<VatReturnRow>
     */
    private static function rowsOf(array $groups): array
    {
        $sums = [];
        foreach ($groups as $g) {
            $key = $g->taxCode . '|' . $g->category . '|' . $g->rate . '|' . $g->origin->value;
            if (!isset($sums[$key])) {
                $sums[$key] = [$g, $g->base, $g->tax];
                continue;
            }
            $sums[$key][1] = $sums[$key][1]->add($g->base);
            $sums[$key][2] = $sums[$key][2]->add($g->tax);
        }

        return array_values(array_map(static fn(array $s): VatReturnRow => new VatReturnRow($s[0]->taxCode, $s[0]->category, $s[0]->rate, $s[0]->origin, $s[1], $s[2]), $sums));
    }

    /**
     * Which field each group fills and with what — the calculator's placement restated per group,
     * so the tree and the entry lists add up to the form's figures (harness-checked against the
     * form): a rate field takes the BASE and the group goes on to 200 (or, a reduction, to 235
     * negated); a deduction field and 200 take the base; an acquisition field its base; an input or
     * input-correction field the posted TAX. A reverse-charge group also fills its INPUT field with
     * the tax DECLARED (base × rate, exact): the field sums those exact shares, the tree carries
     * money — so the rounded sum per input field is spread over its reverse-charge groups by the
     * largest remainder ({@see allocateShares()}), and the tree adds up to the form to the Rappen.
     *
     * @param list<VatReturnLineGroup> $groups
     * @return list<array{field: string, group: VatReturnLineGroup, amount: Money}>
     * @throws UnmappedTaxCodeException
     */
    private function contributions(FormEdition $edition, array $groups): array
    {
        $total     = $edition->fieldOf(FieldKind::Total);
        $reduction = $edition->fieldOf(FieldKind::Reduction);
        $out       = [];
        $shares    = [];   // input field → list<array{group, exact}>
        foreach ($groups as $g) {
            $a = $edition->assign($g->taxCode, $g->category, $g->rate);
            switch ($a->kind) {
                case FieldKind::Rate:
                    $out[] = ['field' => $a->field, 'group' => $g, 'amount' => $g->base];
                    $out[] = $g->origin === RowOrigin::Reduction
                        ? ['field' => $reduction, 'group' => $g, 'amount' => $g->base->negate()]
                        : ['field' => $total, 'group' => $g, 'amount' => $g->base];
                    break;
                case FieldKind::Deduction:
                    $out[] = ['field' => $total, 'group' => $g, 'amount' => $g->base];
                    $out[] = ['field' => $a->field, 'group' => $g, 'amount' => $g->base];
                    break;
                case FieldKind::Acquisition:
                    $out[] = ['field' => $a->field, 'group' => $g, 'amount' => $g->base];
                    $shares[$a->inputField][] = ['group' => $g, 'exact' => ExactAmount::taxOf($g->base, $g->rate)];
                    break;
                case FieldKind::Input:
                case FieldKind::InputCorrection:
                    $out[] = ['field' => $a->field, 'group' => $g, 'amount' => $g->tax];
                    break;
                default:
                    throw new \LogicException("Field {$a->field} of kind '{$a->kind->value}' is not filled by journal lines");
            }
        }
        foreach ($shares as $field => $items) {
            foreach (self::allocateShares($items) as $i => $amount) {
                $out[] = ['field' => (string) $field, 'group' => $items[$i]['group'], 'amount' => $amount];
            }
        }

        return $out;
    }

    /**
     * Exact shares → money that sums to the ROUNDED total of the shares (half away from zero, the
     * form's one rounding): each share floored to the Rappen, the Rappen left over to the shares
     * with the largest remainder (ties: the earlier one). Deterministic, no float.
     *
     * @param list<array{group: VatReturnLineGroup, exact: ExactAmount}> $items
     * @return list<Money> in the order of $items
     */
    private static function allocateShares(array $items): array
    {
        $currency = $items[0]['exact']->currency;
        $sum      = ExactAmount::zero($currency);
        $floors   = [];
        $rest     = [];
        foreach ($items as $i => $item) {
            $units      = $item['exact']->units;
            $floor      = intdiv($units, ExactAmount::PER_MINOR) - ($units % ExactAmount::PER_MINOR < 0 ? 1 : 0);
            $floors[$i] = $floor;
            $rest[$i]   = $units - $floor * ExactAmount::PER_MINOR;
            $sum        = $sum->add($item['exact']);
        }
        $left = $sum->rounded()->minor - array_sum($floors);
        $order = array_keys($rest);
        usort($order, static fn(int $a, int $b): int => [$rest[$b], $a] <=> [$rest[$a], $b]);
        foreach (array_slice($order, 0, max(0, $left)) as $i) {
            $floors[$i]++;
        }

        return array_map(static fn(int $minor): Money => Money::of($minor, $currency), $floors);
    }

    /**
     * @param list<array{field: string, group: VatReturnLineGroup, amount: Money}> $contributions
     * @return list<VatReturnField>
     */
    private function fields(FormEdition $edition, VatReturnForm $form, array $contributions): array
    {
        $lines = [];
        foreach ($contributions as $c) {
            $lines[$c['field']] = ($lines[$c['field']] ?? 0) + $c['group']->lines;
        }
        $always = [FieldKind::DeductionsTotal, FieldKind::TaxableTotal, FieldKind::TaxTotal, FieldKind::InputTotal];
        $rows   = [];
        foreach ($edition->fields() as $field => $kind) {
            $field = (string) $field;
            $value = $form->value($field);
            $base  = in_array($kind, [FieldKind::Rate, FieldKind::Acquisition], true) ? $form->base($field) : null;
            $shown = match (true) {
                in_array($kind, $always, true) => true,
                $kind === FieldKind::Payable   => !$form->payable->isNegative(),
                $kind === FieldKind::Credit    => $form->payable->isNegative(),
                default                        => !$value->isZero() || ($base !== null && !$base->isZero()),
            };
            $rows[] = new VatReturnField($field, $edition->label($field), $kind, $value, $value->rounded(), $base, $lines[$field] ?? 0, $shown);
        }

        return $rows;
    }

    /**
     * The tree of «Herkunft»: field (form order) › display origin (declaration order, manual entries
     * last) › account (by number).
     *
     * @param list<array{field: string, group: VatReturnLineGroup, amount: Money}> $contributions
     * @return list<VatReturnBreakdown>
     */
    private function breakdown(FormEdition $edition, array $contributions, string $currency): array
    {
        $zero = Money::zero($currency);
        $tree = [];
        foreach ($contributions as $c) {
            $g = $c['group'];
            $tree[$c['field']][$g->sourceKey][$g->accountNumber][] = $c;
        }
        $sum = static function (array $items) use ($zero): array {
            $lines = 0;
            $base  = $zero;
            $tax   = $zero;
            $amount = $zero;
            foreach ($items as $c) {
                $lines += $c['group']->lines;
                $base   = $base->add($c['group']->base);
                $tax    = $tax->add($c['group']->tax);
                $amount = $amount->add($c['amount']);
            }

            return [$lines, $base, $tax, $amount];
        };

        $nodes = [];
        foreach (array_keys($edition->fields()) as $field) {
            $field = (string) $field;
            if (!isset($tree[$field])) {
                continue;
            }
            $origins = $tree[$field];
            uksort($origins, fn(string $a, string $b): int => [$this->sources()->position($a), $a] <=> [$this->sources()->position($b), $b]);
            $originNodes = [];
            $all         = [];
            foreach ($origins as $origin => $accounts) {
                ksort($accounts, SORT_STRING);
                $accountNodes = [];
                $ofOrigin     = [];
                foreach ($accounts as $number => $items) {
                    [$lines, $base, $tax, $amount] = $sum($items);
                    $accountNodes[] = new VatReturnBreakdown(VatReturnBreakdown::ACCOUNT, (string) $number, $items[0]['group']->accountName, $items[0]['group']->accountId, $lines, $base, $tax, $amount);
                    array_push($ofOrigin, ...$items);
                }
                [$lines, $base, $tax, $amount] = $sum($ofOrigin);
                $originNodes[] = new VatReturnBreakdown(VatReturnBreakdown::ORIGIN, (string) $origin, $this->sources()->label((string) $origin), null, $lines, $base, $tax, $amount, $accountNodes);
                array_push($all, ...$ofOrigin);
            }
            [$lines, $base, $tax, $amount] = $sum($all);
            $nodes[] = new VatReturnBreakdown(VatReturnBreakdown::FIELD, $field, $edition->label($field), null, $lines, $base, $tax, $amount, $originNodes);
        }

        return $nodes;
    }

    /**
     * The VAT accounts of the period against the tax their coded lines expect — computed by
     * {@see VatAccountExpectation} from `VatAccountMap`, the table the lock of the VAT accounts
     * reads too.
     *
     * @param array{preview: VatReturnPreview, edition: FormEdition, contributions: list<array<string, mixed>>, yearIds: list<int>} $computed
     * @return list<VatAccountReconciliation>
     */
    private function vatAccounts(VatPeriod $period, array $computed): array
    {
        return (new VatAccountExpectation($this->em))->reconcile(
            $period,
            $computed['edition'],
            $computed['preview']->groups,
            $this->movementsOf($period, $computed['yearIds']),
            $computed['preview']->currency,
        );
    }

    /**
     * @param list<int> $yearIds
     * @return list<array<string, mixed>> the movement rows of the period (cached on this instance)
     */
    private function movementsOf(VatPeriod $period, array $yearIds): array
    {
        return $this->movements[self::key($period)] ??= $this->journalLines()->vatReturnMovements(
            $yearIds,
            $period->from->format('Y-m-d'),
            $period->to->format('Y-m-d'),
            self::NOT_MOVEMENTS,
            $this->openingAccountId(),
        );
    }

    /** @throws VatReturnRefusedException the settings gate */
    private function assertSettings(): VatSettings
    {
        $refusal = $this->settingsRefusal();
        if ($refusal !== null) {
            throw new VatReturnRefusedException($refusal);
        }

        return $this->settings();
    }

    /** @throws MandatorUnavailableException */
    private function settings(): VatSettings
    {
        return $this->settings ??= (new CurrentMandator($this->em))->vatSettings();
    }

    /**
     * SHA-256 of the rule file the form editions were read from — what a posted return snapshots
     * beside the resolved assignments (ADR-041 addendum 2026-11-24, point 5). The file is read ONCE
     * per instance and kept: asked on the instance that computed a period, it is the hash of the
     * very mapping those figures came from. Asked on an instance that has computed nothing yet, it
     * reads the file now — `VatReturnPostingService` therefore takes it from the instance of the
     * plan ({@see VatSettlementPlan::$mappingHash}), never from a new one.
     *
     * @throws FormMappingException the rule file cannot be read
     */
    public function ruleFileHash(): string
    {
        return ($this->mapping ??= FormMapping::forCountry(self::COUNTRY))->sha256;
    }

    /** @throws VatReturnRefusedException no edition of the form covers the period */
    private function edition(VatPeriod $period): FormEdition
    {
        try {
            return ($this->mapping ??= FormMapping::forCountry(self::COUNTRY))->edition($period->from, $period->to);
        } catch (FormMappingException $e) {
            throw new VatReturnRefusedException(new VatReturnRefusal(
                VatReturnRefusal::NO_FORM,
                'Für die Periode ' . $period->from->format('d.m.Y') . ' – ' . $period->to->format('d.m.Y') . ' gibt es kein Formular der MWST-Abrechnung in z77 (Formulare ab 2024).',
            ), $e);
        }
    }

    /**
     * The fiscal years a period touches — a period may span two.
     *
     * @return list<int>
     */
    private function yearIds(VatPeriod $period): array
    {
        $from = $period->from->format('Y-m-d');
        $to   = $period->to->format('Y-m-d');
        $ids  = [];
        foreach ($this->em->getRepository(FiscalYear::class)->findAll() as $year) {
            if ($year->getStartDate()->format('Y-m-d') <= $to && $year->getEndDate()->format('Y-m-d') >= $from) {
                $ids[] = (int) $year->getId();
            }
        }
        sort($ids);

        return $ids;
    }

    /** @return array<string, string> normalised code → category (the stored string — VAT-CAT-001) */
    private function categories(): array
    {
        if ($this->categories === null) {
            $this->categories = [];
            foreach ($this->em->getRepository(TaxCode::class)->findAll() as $code) {
                $this->categories[TaxCode::normalizeCode($code->getCode())] = $code->getCategory();
            }
        }

        return $this->categories;
    }

    /** Output turnover: a category whose lines land in field 200 (a rate or a deduction field). */
    private static function isOutput(string $category): bool
    {
        $kind = FieldKind::forCategory($category);

        return $kind === FieldKind::Rate || $kind === FieldKind::Deduction;
    }

    /**
     * Every postable account under the chart group {@see FIXED_ASSET_GROUP} (by the parent chain;
     * a cycle in the chart stops the walk) — except its value-adjustment accounts.
     *
     * @return array<int, true>
     */
    private function fixedAssetAccountIds(): array
    {
        /** @var AccountRepository $repository */
        $repository = $this->em->getRepository(Account::class);
        $ids        = [];
        foreach ($repository->allInOrder() as $account) {
            if (!$account->isPostable() || self::isValueAdjustment($account)) {
                continue;
            }
            $seen = [];
            for ($parent = $account->getParent(); $parent !== null && !isset($seen[$parent->getNumber()]); $parent = $parent->getParent()) {
                if ($parent->getNumber() === self::FIXED_ASSET_GROUP) {
                    $ids[(int) $account->getId()] = true;
                    break;
                }
                $seen[$parent->getNumber()] = true;
            }
        }

        return $ids;
    }

    /**
     * A value-adjustment account of the KMU chart: its number ends in `9` AND its name starts with
     * {@see VALUE_ADJUSTMENT_PREFIX} (1509, 1519, … 1779). Both, so that only an account the chart
     * itself marks as one is left out — a real asset account never loses its warning by one of them.
     */
    private static function isValueAdjustment(Account $account): bool
    {
        return str_ends_with($account->getNumber(), '9') && str_starts_with($account->getName(), self::VALUE_ADJUSTMENT_PREFIX);
    }

    private function openingAccountId(): int
    {
        $number = (new LedgerService($this->em))->yearOpeningAccount(LedgerService::OPENING_BALANCE_ACCOUNT);
        if ($number === null) {
            return 0;
        }

        return (int) ($this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getId() ?? 0);
    }

    private function invoiceRoundingAccount(): ?string
    {
        try {
            return (new LedgerService($this->em))->invoiceRoundingAccount();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    /**
     * The account numbers the mandator names for owed VAT AND for input tax (FIN-VAT-ACCOUNTS-001).
     * The mandator validator refuses that on save since 2026-11-25 (owner decision: forbidden, no
     * netting); a record stored before keeps loading, so the return says it here — blocking, because
     * {@see vatAccounts()} reconciles the merged slot under ONE sign and never agrees. Two input
     * categories on one account are no conflict (same role, same sign).
     *
     * @return list<string>
     * @throws VatAccountUnavailableException the mandator cannot be read, or the legacy config key is present
     */
    private function sharedVatAccounts(): array
    {
        $ledger = new LedgerService($this->em);
        $owed   = [];
        $input  = [];
        foreach (self::vatAccountCategories() as $category) {
            $number = $ledger->vatAccountFor($category->value);
            if ($number === null) {
                continue;
            }
            if (self::isOutput($category->value)) {
                $owed[] = $number;
            } else {
                $input[] = $number;
            }
        }

        return array_values(array_unique(array_intersect($owed, $input)));
    }

    private function accountName(string $number): string
    {
        return $number === '' ? '' : (string) $this->em->getRepository(Account::class)->findOneBy(['number' => $number])?->getName();
    }

    private function registry(): OpenWorkChecks
    {
        return $this->checks ??= OpenWorkChecks::fromModules(DI::getModuleManager());
    }

    private function sources(): VatReturnSources
    {
        return $this->sources ??= VatReturnSources::fromModules(DI::getModuleManager());
    }

    private function journalLines(): JournalLineRepository
    {
        return $this->em->getRepository(JournalLine::class);
    }

    private static function key(VatPeriod $period): string
    {
        return $period->from->format('Y-m-d') . '|' . $period->to->format('Y-m-d');
    }

    /** 810 → «8.1 %», 0 → «0 %». */
    private static function percent(int $rate): string
    {
        return rtrim(rtrim(intdiv($rate, 100) . '.' . str_pad((string) ($rate % 100), 2, '0', STR_PAD_LEFT), '0'), '.') . ' %';
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }
}
