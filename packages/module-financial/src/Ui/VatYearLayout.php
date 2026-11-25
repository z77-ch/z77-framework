<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\StoredVatYearOverview;
use Z77\Module\Financial\VatReturn\ExactAmount;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatYearCorrection;
use Z77\Module\Financial\VatReturn\VatYearOverview;
use Z77\Module\Financial\VatReturn\VatYearPeriod;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Module\Vat\CountryPack\FormMapping;
use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Shared\Money\Money;

/**
 * Layout config, URL root and the German display names of the yearly overview of the VAT return
 * («MWST-Jahresübersicht», stage S7 slices 7.2 and 7.5 — owner S7-1 … S7-4, B-1 … B-5, C-D of
 * 2026-11-25; ADR-018 mount pattern): the host's `Ui/Config/Finance/vatYearControllerConfig.inc.php`
 * in module-backend delegates here (one line) and pins the page body to {@see PAGE}. The presentation
 * rules the page AND the PDF share live here ({@see VatYearControllerTrait}, `Pdf\VatYearPdf`), so the
 * two never word a fact differently.
 *
 * The page is reached with `?year=<fiscal-year code>` like every finance screen: it shows the TAX
 * YEAR whose 31 December lies in that fiscal year (`VatTaxYear::forFiscalYear()`, owner B-3) — the
 * other tax year through the rail.
 */
final class VatYearLayout
{
    /** Namespace that owns the fragment's templates. */
    public const NS = 'Z77\\Module\\Financial';

    /** Finanzen › MWST-Abrechnungen › Jahresübersicht. */
    public const BASE = '/backend/finance/vat-year';

    /** The page template every tab renders through (the layout's pin). */
    public const PAGE = 'page';

    public const TITLE = 'MWST-Jahresübersicht';

    /** The three tabs — URL action → label, in reading order (board S7-2). */
    public const TABS = [
        'corrections' => 'Korrekturen',
        'turnover'    => 'Umsatz',
        'input'       => 'Vorsteuer',
    ];

    /** A year without a reported correction (board S7-2 part 1). */
    public const NO_CORRECTIONS = 'Keine Korrekturen — keine Jahresabstimmung einzureichen';

    /** A fiscal year that is not the calendar year (owner S7-2, ADR-041 addendum point 11). */
    public const FISCAL_YEAR_DIFFERS = 'Geschäftsjahr ≠ Kalenderjahr — Überleitung zur Jahresrechnung von Hand.';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Backend/VatYearController',
                        'name'      => self::PAGE,
                    ]],
                ],
            ],
        ];
    }

    /**
     * The page of the tax year a fiscal year opens (owner B-3) — the link of the tool
     * «Jahresübersicht» on the VAT return list and of «Jahr abschliessen».
     */
    public static function yearUrl(FiscalYear $fiscalYear, string $tab = 'corrections'): string
    {
        return self::BASE . '/' . $tab . '?' . http_build_query(['year' => $fiscalYear->getCode()]);
    }

    /**
     * The link to the overview a fiscal year opens — `{url, label}` with the label «MWST-Jahresübersicht
     * 2026 ansehen» — or null when there is none to open: the mandator does not settle VAT in z77 (the
     * settings gate of the return), or that tax year has no VAT period (board S7-1: the tool is visible
     * as soon as the tax year has a VAT period; the close window shows the link only then). ONE rule for
     * the tool «Jahresübersicht» of the VAT return list and the link in «Jahr abschliessen» (B-3).
     *
     * @return array{url: string, label: string}|null
     */
    public static function linkFor(FiscalYear $fiscalYear, VatReturnService $returns): ?array
    {
        try {
            if ($returns->settingsRefusal() !== null) {
                return null;
            }
            $taxYear = \Z77\Module\Financial\VatReturn\VatTaxYear::forFiscalYear($fiscalYear);
            if (!\Z77\Module\Financial\VatReturn\VatTaxYear::of($taxYear, $returns->calendar())->hasPeriods()) {
                return null;
            }
        } catch (\Z77\Module\Financial\VatReturn\VatReturnRefusedException | \Z77\Module\Mandator\Services\MandatorUnavailableException | \InvalidArgumentException) {
            return null;
        }

        return ['url' => self::yearUrl($fiscalYear), 'label' => self::title($taxYear) . ' ansehen'];
    }

    /** «MWST-Jahresübersicht 2026». */
    public static function title(int $taxYear): string
    {
        return self::TITLE . ' ' . $taxYear;
    }

    /** «Seit der Ablage geändert — das abgelegte PDF zeigt den Stand vom 15.02.2027.» (owner B-5). */
    public static function changedSince(StoredVatYearOverview $stored): string
    {
        return 'Seit der Ablage geändert — das abgelegte PDF zeigt den Stand vom ' . $stored->getStandAt()->format('d.m.Y') . '.';
    }

    /** «Abgelegt 15.02.2027 11:32 von pru» — the head of the page and the last line of the stored PDF. */
    public static function storedLine(string $by, \DateTimeImmutable $at): string
    {
        return 'Abgelegt ' . $at->format('d.m.Y H:i') . ' von ' . $by;
    }

    /** «01.01.2026 – 31.12.2026». */
    public static function yearSpan(VatYearOverview $overview): string
    {
        return $overview->taxYear->firstDay()->format('d.m.Y') . ' – ' . $overview->taxYear->lastDay()->format('d.m.Y');
    }

    /** «Quartal», «Quartal, Monat» — the rhythms of the year's periods, in order. */
    public static function rhythmLabel(VatYearOverview $overview): string
    {
        $labels = [];
        foreach ($overview->periods as $p) {
            $labels[] = \Z77\Module\Mandator\Ui\MandatorLayout::VAT_RHYTHMS[$p->period->rhythm->value] ?? $p->period->rhythm->value;
        }

        return implode(', ', array_values(array_unique($labels)));
    }

    /**
     * «Geschäftsjahr 2026» — or, when the calendar year touches several fiscal years, «Geschäftsjahre
     * 2025/26 und 2026/27» (the codes as the books name them).
     */
    public static function fiscalYearsLabel(VatYearOverview $overview): string
    {
        $codes = array_map(static fn(FiscalYear $y): string => $y->getCode(), $overview->fiscalYears);
        if ($codes === []) {
            return 'kein Geschäftsjahr';
        }
        if (count($codes) === 1) {
            return 'Geschäftsjahr ' . $codes[0];
        }
        $last = array_pop($codes);

        return 'Geschäftsjahre ' . implode(', ', $codes) . ' und ' . $last;
    }

    /** True when the fiscal year holding 31 December is the calendar year (the deadlines are then known). */
    public static function isCalendarYear(VatYearOverview $overview): bool
    {
        return $overview->deadlines->known;
    }

    /**
     * The state of the fiscal year holding 31 December: «Geschäftsjahr 2026 abgeschlossen 15.02.2027»,
     * «Geschäftsjahr 2026 offen», «kein Geschäftsjahr mit dem 31.12.2026».
     */
    public static function yearEndState(VatYearOverview $overview, ?\DateTimeImmutable $closedAt): string
    {
        $year = $overview->yearEndFiscalYear;
        if ($year === null) {
            return 'kein Geschäftsjahr mit dem ' . $overview->taxYear->lastDay()->format('d.m.Y');
        }
        if (!$year->isClosed()) {
            return 'Geschäftsjahr ' . $year->getCode() . ' offen';
        }

        return 'Geschäftsjahr ' . $year->getCode() . ' abgeschlossen' . ($closedAt === null ? '' : ' ' . $closedAt->format('d.m.Y'));
    }

    /**
     * Why the overview is «vorläufig» (owner B-4): the periods not posted, the fiscal year holding
     * 31 December not closed (or not opened). Empty when final.
     *
     * @return list<string>
     */
    public static function provisionalReasons(VatYearOverview $overview): array
    {
        if ($overview->final) {
            return [];
        }
        $reasons  = [];
        $unposted = array_map(static fn($p): string => $p->label(), $overview->unposted());
        if ($unposted !== []) {
            $reasons[] = (count($unposted) > 3 ? count($unposted) . ' Perioden' : implode(', ', $unposted)) . ' nicht gebucht';
        }
        if ($overview->yearEndFiscalYear === null || !$overview->yearEndFiscalYear->isClosed()) {
            $reasons[] = self::yearEndState($overview, null);
        }

        return $reasons;
    }

    /** «180. Tag 29.06.2027 · 240. Tag 28.08.2027», or «Frist bei der ESTV klären» (owner S3-8). */
    public static function deadlinesLine(VatYearOverview $overview): string
    {
        $d = $overview->deadlines;

        return $d->known
            ? '180. Tag ' . $d->day180()->format('d.m.Y') . ' · 240. Tag ' . $d->day240()->format('d.m.Y')
            : \Z77\Module\Financial\VatReturn\VatYearDeadlines::UNKNOWN;
    }

    /**
     * The head line of one reported block: «3. Quartal 2026 · Korrekturbuchung 2027/214 vom 22.02.2027
     * · gemeldet mit Abrechnung 1. Quartal 2027 am 25.05.2027 · vor dem 240. Tag».
     */
    public static function correctionLine(VatYearCorrection $c): string
    {
        $entries = $c->block->entries;
        $refs    = count($entries) === 1
            ? 'Korrekturbuchung ' . $entries[0]['ref'] . ' vom ' . $entries[0]['date']->format('d.m.Y')
            : 'Korrekturbuchungen ' . $c->block->entryRefs();
        $parts = [$c->block->period->label(), $refs, 'gemeldet mit Abrechnung ' . $c->carryingReturn->period()->label() . ' am ' . $c->reportedOn->format('d.m.Y')];
        if ($c->beforeDay240 !== null) {
            $parts[] = \Z77\Module\Financial\VatReturn\VatYearDeadlines::relationLabel($c->beforeDay240);
        }

        return implode(' · ', $parts);
    }

    /**
     * The turnover a block corrects — field 200 − 235 of its rows (each rounded once, as the read model
     * sums «später gemeldet»).
     */
    public static function blockTurnover(VatYearCorrection $c, string $currency): Money
    {
        $sum = Money::zero($currency);
        foreach ($c->block->rows as $row) {
            if ($row['kind'] === FieldKind::Total) {
                $sum = $sum->add($row['correction']->rounded());
            } elseif ($row['kind'] === FieldKind::Reduction) {
                $sum = $sum->subtract($row['correction']->rounded());
            }
        }

        return $sum;
    }

    /** The payable difference a block carries («Differenz Zahllast»), credit corrections subtracted; zero without such a row. */
    public static function blockPayable(VatYearCorrection $c, string $currency): Money
    {
        $sum = Money::zero($currency);
        foreach ($c->block->rows as $row) {
            if ($row['kind'] === FieldKind::Payable) {
                $sum = $sum->add($row['correction']->rounded());
            } elseif ($row['kind'] === FieldKind::Credit) {
                $sum = $sum->subtract($row['correction']->rounded());
            }
        }

        return $sum;
    }

    /** «2026/1188» of a period's live return (its settlement entry), '' for a nil return or an open period. */
    public static function periodEntry(VatYearPeriod $p): string
    {
        $entry = $p->return?->getSettlementEntry();

        return $entry === null ? '' : VatReturnLayout::entryRef($entry);
    }

    /**
     * The form fields of the input-tax corrections — the categories «Einlageentsteuerung»,
     * «Vorsteuerkorrektur», «Vorsteuerkürzung» in the edition of the year's last period (410, 415,
     * 420 in the CH form). By category, never by a field number; empty when no edition answers.
     *
     * @return list<string>
     */
    public static function inputCorrectionFields(VatYearOverview $overview): array
    {
        $periods = $overview->taxYear->periods;
        if ($periods === []) {
            return [];
        }
        $last = $periods[array_key_last($periods)];
        try {
            $edition = FormMapping::forCountry(VatReturnService::COUNTRY)->edition($last->from, $last->to);
        } catch (\Throwable) {
            return [];
        }
        $fields = [];
        foreach ([TaxCategory::InputSubsequent, TaxCategory::InputCorrection, TaxCategory::InputReduction] as $category) {
            try {
                $fields[] = $edition->assign('', $category->value, 0)->field;
            } catch (\Throwable) {
                // the edition has no rule for it — nothing to list
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * «6'519.51261» — the exact value with every decimal it has beyond the Rappen (at least two),
     * grouped like {@see \Z77\Shared\Money\AmountFormat} (owner B-2: small under every tax amount).
     * The ONE formatter of an exact amount is the return's ({@see VatReturnLayout::exact()}): the
     * return page, its PDF, the overview and its PDF never show the same value differently.
     */
    public static function exact(ExactAmount $amount): string
    {
        return VatReturnLayout::exact($amount);
    }

    /** True when the exact value carries more than the Rappen — only then is it shown beneath. */
    public static function showsExact(ExactAmount $amount): bool
    {
        return !$amount->isWhole();
    }

    /** «+40.46», «−0.01», «0.00». */
    public static function signed(Money $amount): string
    {
        return ($amount->isPositive() ? '+' : '') . \Z77\Shared\Money\AmountFormat::of($amount);
    }

    /** «+40.46274» — the exact value signed like {@see signed()}. */
    public static function signedExact(ExactAmount $amount): string
    {
        return (!$amount->isNegative() && !$amount->isZero() ? '+' : '') . self::exact($amount);
    }
}
