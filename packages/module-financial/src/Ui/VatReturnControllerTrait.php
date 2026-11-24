<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI;
use Z77\Core\Http\Response\BytesResponse;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Core\Http\Response\RedirectResponse;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Ledger\EntryDocuments;
use Z77\Module\Financial\Pdf\VatReturnPdf;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnField;
use Z77\Module\Financial\VatReturn\VatReturnLine;
use Z77\Module\Financial\VatReturn\VatReturnPreview;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnRefusedException;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «Finanzen › MWST-Abrechnungen» — the VAT return, READ-ONLY (P5 part 2, build stage S1b; the
 * screen decisions of 2026-11-24, `vat-return-review-2026-11-24.md` §8) — mounted by a thin host
 * controller in the backend (ADR-018 pattern, like the reports). Host side: `use
 * VatReturnControllerTrait` + a one-line layout config delegating to {@see VatReturnLayout::config()}.
 *
 * The pages, every one a GET (nothing is posted or stored — stage S1; no form post, no CSRF):
 *
 *   - `list` — the returns of the fiscal year chosen at the top of the rail (the periods that END
 *     in it, decision 3), the action cell «Nächste Abrechnung» (decision 1); a settings refusal
 *     instead of the list when the mandator's VAT settings allow none;
 *   - `form` / `origin` / `reconciliation` — one return (`?from=` a day of the period) in three
 *     views, the toolbar tabs «Formular | Herkunft | Abstimmung» (one page per view, like the
 *     report tabs); the action cell stays EMPTY in S1 (decision 2: «MWST buchen …» comes with S2);
 *   - `entries` — the coded lines of one form field (`?field=`), narrowed by origin and account
 *     (decision 9): a standard list ({@see VatReturnEntriesListing}), the tabs stay with «Herkunft»;
 *   - `pdf` — the return as a PDF through the kernel's `pdf/report` ({@see VatReturnPdf}).
 *
 * Every figure is the service's ({@see VatReturnService}) — this trait only resolves the period,
 * picks the template and turns the opaque places of refusals and findings into links. ACL: the
 * finance controllers' module role (ADMIN), no deviation entry. The using class MUST provide (via
 * its host base): `html()`, `redirect()`, `bytes()`, `em()`, `$layoutManager`, `$help` (ADR-048).
 */
trait VatReturnControllerTrait
{
    private ?VatReturnService $vatReturnService = null;

    /** URL root of THIS mount — every link of the screens is built from it. */
    protected function vatReturnBase(): string
    {
        return VatReturnLayout::BASE;
    }

    /** The read service — one per request (it caches what it computed). */
    protected function vatReturns(): VatReturnService
    {
        return $this->vatReturnService ??= new VatReturnService($this->em());
    }

    private function vatReturnYears(): FiscalYearRepository
    {
        return $this->em()->getRepository(FiscalYear::class);
    }

    // ── the list ─────────────────────────────────────────────────────────

    /**
     * The returns of the selected fiscal year — or, when the mandator's VAT settings allow none,
     * that one refusal with the link to the place that clears it (the menu entry is not hidden:
     * the backend has no data-driven menu condition — financial.md, P5 part 2).
     */
    protected function listAction(): HtmlResponse
    {
        $service = $this->vatReturns();
        $base    = $this->vatReturnBase();
        $refusal = $service->settingsRefusal();
        $years   = $this->vatReturnYears();
        $code    = $this->vatReturnParameter('year');
        $year    = FiscalYearSelection::of($years)->resolve($code);
        $notices = [];
        if ($code !== '' && $year?->getCode() !== $code) {
            $notices[] = "Geschäftsjahr «{$code}» gibt es nicht — das gewählte wird gezeigt.";
        }

        $rows = [];
        $next = null;
        if ($refusal === null && $year !== null) {
            foreach ($service->periods($year) as $summary) {
                $rows[] = [
                    'summary' => $summary,
                    'label'   => VatReturnLayout::periodLabel($summary->period),
                    'url'     => $this->vatReturnUrl('form', $summary->period),
                    'pdfUrl'  => $summary->refusal === null ? $this->vatReturnUrl('pdf', $summary->period) : null,
                    'fix'     => $summary->refusal === null ? null : $this->vatReturnFixLink($summary->refusal),
                ];
            }
            $next = $service->nextReturn();
        }

        // The list's help (ADR-048, HELP-003): at the right end of the toolbar.
        $this->help->attach('Backend/VatReturnController/list.help', VatReturnLayout::NS, [], 'Hilfe: MWST-Abrechnungen', toolbar: true);
        $response = $this->html([
            'refusal'     => $refusal,
            'fix'         => $refusal === null ? null : $this->vatReturnFixLink($refusal),
            'year'        => $year,
            'rows'        => $rows,
            'rhythm'      => $rows === [] ? '' : (\Z77\Module\Mandator\Ui\MandatorLayout::VAT_RHYTHMS[$rows[0]['summary']->period->rhythm->value] ?? ''),
            'notices'     => $notices,
            'nextUrl'     => $next === null ? null : $this->vatReturnUrl('form', $next),
            'nextLabel'   => $next === null ? '' : VatReturnLayout::periodLabel($next),
            'fySelection' => $refusal === null ? $this->vatReturnYearSelection(null, $year) : null,
            'base'        => $base,
            'fmt'         => static fn(?Money $m): string => AmountFormat::of($m),
        ]);
        // The action cell: «Nächste Abrechnung» — empty when every elapsed period is settled (decision 1).
        if ($next !== null) {
            $this->layoutManager->addPartials('act', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc1');
        }
        // The fiscal year holds for the whole area: the top of the rail (the dropdown the journal shares).
        $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', VatReturnLayout::NS, 'railSelect');

        return $response;
    }

    // ── one return: three views ──────────────────────────────────────────

    /** Tab «Formular»: the open points, then the form in field order. */
    protected function formAction(): HtmlResponse|RedirectResponse
    {
        return $this->vatReturnView('form');
    }

    /** Tab «Herkunft»: the tree field › origin › account (decision 8). */
    protected function originAction(): HtmlResponse|RedirectResponse
    {
        return $this->vatReturnView('origin');
    }

    /** Tab «Abstimmung»: turnover, input tax and VAT owed against the accounts. */
    protected function reconciliationAction(): HtmlResponse|RedirectResponse
    {
        return $this->vatReturnView('reconciliation');
    }

    /**
     * The entries of one form field (`?field=`), narrowed by `?origin=` and `?account=` (the
     * list's extras — the chips), searched and sorted by its columns; a fetch region.
     */
    protected function entriesAction(): HtmlResponse|RedirectResponse
    {
        $request  = DI::getRequest();
        $resolved = $this->vatReturnPeriod();
        if ($resolved === null) {
            return $this->redirect($this->vatReturnBase() . '/list');
        }
        [$period, $refusal] = $resolved;
        $fetch   = ListDefinition::isFetch($request);
        $field   = $this->vatReturnParameter('field');
        $preview = null;
        $own     = [];
        if ($period !== null && $refusal === null) {
            try {
                $preview = $this->vatReturns()->preview($period);
                $row     = null;
                foreach ($preview->fields as $candidate) {
                    $row = $candidate->field === $field ? $candidate : $row;
                }
                if ($row === null) {
                    return $this->redirect($this->vatReturnUrl('origin', $period));
                }
                $own = $this->vatReturnEntriesContext($period, $preview, $row, $request);
            } catch (VatReturnRefusedException $e) {
                $refusal = $e->refusal;
                $preview = null;
            }
        }

        if (!$fetch) {
            $this->help->attach('Backend/VatReturnController/entries.help', VatReturnLayout::NS, [], 'Hilfe: Buchungen einer Ziffer', toolbar: true);
        }
        $response = $this->html($own + $this->vatReturnFrame('origin', $period, $preview, $refusal) + ['crumbField' => $refusal === null ? $field : '']);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($refusal === null ? 'entries' : 'refused', 'Backend/VatReturnController', VatReturnLayout::NS);
        if (!$fetch) {
            $this->vatReturnSlots($refusal === null && $period !== null);
        }

        return $response;
    }

    /**
     * The return as a PDF (`?from=`): the facts, the form with the exact tax per rate field, the
     * difference declared vs. posted, the open points and the sentence that it is no filing.
     * Answered inline; nothing archived (that is stage S2). A refused period → its page.
     */
    protected function pdfAction(): BytesResponse|RedirectResponse
    {
        $resolved = $this->vatReturnPeriod();
        if ($resolved === null || $resolved[0] === null) {
            return $this->redirect($this->vatReturnBase() . '/list');
        }
        [$period, $refusal] = $resolved;
        if ($refusal !== null) {
            return $this->redirect($this->vatReturnUrl('form', $period));
        }
        try {
            $preview  = $this->vatReturns()->preview($period);
            $findings = $this->vatReturns()->findings($period);
        } catch (VatReturnRefusedException) {
            return $this->redirect($this->vatReturnUrl('form', $period));
        }
        $label = VatReturnLayout::periodLabel($period);
        $pdf   = VatReturnPdf::of($preview, $findings, $label, $this->vatReturnMandator(), (new \DateTimeImmutable())->format('d.m.Y H:i'));

        return $this->bytes($pdf->output(), VatReturnPdf::fileName($label), 'application/pdf');
    }

    // ── the frame of a return page ───────────────────────────────────────

    private function vatReturnView(string $tab): HtmlResponse|RedirectResponse
    {
        $resolved = $this->vatReturnPeriod();
        if ($resolved === null) {
            return $this->redirect($this->vatReturnBase() . '/list');
        }
        [$period, $refusal] = $resolved;
        $service = $this->vatReturns();
        $preview = null;
        $own     = [];
        if ($period !== null && $refusal === null) {
            try {
                $preview = $service->preview($period);
                $own     = match ($tab) {
                    'form'           => $this->vatReturnFormContext($period, $preview),
                    'origin'         => ['entriesUrl' => $this->vatReturnEntriesUrl($period)],
                    'reconciliation' => ['reconciliation' => $service->reconciliation($period), 'accountUrl' => $this->vatReturnAccountUrl($period)],
                };
            } catch (VatReturnRefusedException $e) {
                $refusal = $e->refusal;
                $preview = null;
                $own     = [];
            }
        }

        // The return's help (ADR-048): a page, not a list — its trigger stands in the top bar.
        $this->help->attach('Backend/VatReturnController/return.help', VatReturnLayout::NS, [], 'Hilfe: MWST-Abrechnung');
        $response = $this->html($own + $this->vatReturnFrame($tab, $period, $preview, $refusal) + ['crumbField' => '']);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($refusal === null ? $tab : 'refused', 'Backend/VatReturnController', VatReturnLayout::NS);
        $this->vatReturnSlots($refusal === null && $period !== null);

        return $response;
    }

    /**
     * What every return page shows: the period, its label, the preview (null when refused), the
     * refusal and its link, the tab links, the PDF, the fiscal-year dropdown.
     *
     * @return array<string, mixed>
     */
    private function vatReturnFrame(string $tab, ?VatPeriod $period, ?VatReturnPreview $preview, ?VatReturnRefusal $refusal): array
    {
        $base = $this->vatReturnBase();
        $from = $period?->from->format('Y-m-d');

        return [
            'base'        => $base,
            'tab'         => $tab,
            'tabs'        => VatReturnLayout::TABS,
            'tabUrl'      => static fn(string $view): string => $base . '/' . $view . '?' . http_build_query(['from' => $from]),
            'pdfUrl'      => $period === null ? null : $this->vatReturnUrl('pdf', $period),
            'period'      => $period,
            'periodLabel' => $period === null ? '' : VatReturnLayout::periodLabel($period),
            'preview'     => $preview,
            'refusal'     => $refusal,
            'fix'         => $refusal === null ? null : $this->vatReturnFixLink($refusal),
            'fySelection' => $this->vatReturnYearSelection($period),
            'fmt'         => static fn(?Money $m): string => AmountFormat::of($m),
        ];
    }

    /** The header slots of a return page: the crumb, the tabs (not on a refusal), the year dropdown. Never the action cell in S1. */
    private function vatReturnSlots(bool $tabs): void
    {
        $this->layoutManager->addPartials('crumb', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc3');
        if ($tabs) {
            $this->layoutManager->addPartials('tabs', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc2');
        }
        $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', VatReturnLayout::NS, 'railSelect');
    }

    /**
     * Tab «Formular»: the open points with the link each reference names, the posted tax beside the
     * total owed, the entries link of a field.
     *
     * @return array<string, mixed>
     */
    private function vatReturnFormContext(VatPeriod $period, VatReturnPreview $preview): array
    {
        $rows = [];
        foreach ($this->vatReturns()->findings($period) as $finding) {
            $rows[] = ['finding' => $finding, 'link' => $this->vatReturnReferenceLink($finding->reference, $period, $preview)];
        }

        return ['findingRows' => $rows, 'postedOwed' => $preview->postedTaxOwed()];
    }

    /**
     * The entries list of one field: the node's search narrowed by the list state, one page, the
     * totals, the documents behind the entries, the chips.
     *
     * @return array<string, mixed>
     */
    private function vatReturnEntriesContext(VatPeriod $period, VatReturnPreview $preview, VatReturnField $field, object $request): array
    {
        $service    = $this->vatReturns();
        $definition = VatReturnEntriesListing::definition();
        $state      = $definition->readRequest($request);
        $origin     = VatReturnEntriesListing::origin($state);
        $accountId  = VatReturnEntriesListing::accountId($state);
        $search     = VatReturnEntriesListing::search($state, $service->lineSearch($period, $field->field, $origin, $accountId));
        $paging     = $state->paging($service->countLines($search));
        $lines      = $service->lines($search, $paging->offset(), $definition->pageSize);

        // The chips name what «Herkunft» narrowed by — the labels come from the tree itself.
        $node         = $preview->breakdownOf($field->field);
        $originLabel  = $origin === null ? '' : ($node?->child($origin)?->label ?? $origin);
        $accountLabel = '';
        foreach ($accountId === null ? [] : ($node?->children ?? []) as $originNode) {
            foreach ($originNode->children as $accountNode) {
                if ($accountNode->accountId === $accountId) {
                    $accountLabel = $accountNode->key . ' ' . $accountNode->label;
                }
            }
        }

        return [
            'field'        => $field,
            'definition'   => $definition,
            'state'        => $state,
            'paging'       => $paging,
            'lines'        => $lines,
            'totals'       => $service->lineTotals($search),
            'documents'    => $this->vatReturnDocuments($lines),
            'originLabel'  => $originLabel,
            'accountLabel' => $accountLabel !== '' ? $accountLabel : ($accountId === null ? '' : 'Konto #' . $accountId),
            'listAction'   => $this->vatReturnBase() . '/entries',
            'keep'         => ['from' => $period->from->format('Y-m-d'), 'field' => $field->field],
            'journalBase'  => VatReturnLayout::JOURNAL_BASE,
        ];
    }

    // ── parameters, period, links ────────────────────────────────────────

    /** A GET parameter as a trimmed string — '' when absent or not a scalar. */
    private function vatReturnParameter(string $name): string
    {
        $value = DI::getRequest()->getGetParameter($name);

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /**
     * The period the request names (`?from=` a day `YYYY-MM-DD` inside it) — [period, null]; the
     * settings gate or a day outside the liability → [null, refusal]; no readable day → null.
     *
     * @return array{0: ?VatPeriod, 1: ?VatReturnRefusal}|null
     */
    private function vatReturnPeriod(): ?array
    {
        $service = $this->vatReturns();
        $gate    = $service->settingsRefusal();
        if ($gate !== null) {
            return [null, $gate];
        }
        $from = $this->vatReturnParameter('from');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        try {
            return [$service->periodOn(new \DateTimeImmutable($from)), null];
        } catch (VatReturnRefusedException $e) {
            return [null, $e->refusal];
        }
    }

    /** `/backend/finance/vat-return/{action}?from=…` of a period, plus $params. */
    private function vatReturnUrl(string $action, VatPeriod $period, array $params = []): string
    {
        return $this->vatReturnBase() . '/' . $action . '?' . http_build_query(['from' => $period->from->format('Y-m-d')] + $params);
    }

    /** (field, ?origin, ?accountId) → the entries list of that node of «Herkunft». */
    private function vatReturnEntriesUrl(VatPeriod $period): \Closure
    {
        return fn(string $field, ?string $origin = null, ?int $accountId = null): string
            => $this->vatReturnUrl('entries', $period, array_filter(['field' => $field, 'origin' => $origin, 'account' => $accountId], static fn($v) => $v !== null));
    }

    /**
     * account number → its account statement over the period. The report range lies inside ONE
     * fiscal year: the year holding the period's end, the period cut to it (a period spanning two
     * years shows its part in the later one).
     */
    private function vatReturnAccountUrl(VatPeriod $period): \Closure
    {
        $years = $this->vatReturnYears();
        $year  = $years->findByDate($period->to) ?? $years->findByDate($period->from);
        $base  = VatReturnLayout::REPORT_BASE . '/account-statement';
        if ($year === null) {
            return static fn(string $number): string => $base . '?' . http_build_query(['account' => $number]);
        }
        $from = max($period->from->format('Y-m-d'), $year->getStartDate()->format('Y-m-d'));
        $to   = min($period->to->format('Y-m-d'), $year->getEndDate()->format('Y-m-d'));

        return static fn(string $number): string => $base . '?' . http_build_query(['year' => $year->getCode(), 'from' => $from, 'to' => $to, 'account' => $number]);
    }

    /**
     * The place a refusal names ({@see VatReturnRefusal::$fix}) as a link, null when it names none.
     *
     * @return array{url: string, label: string}|null
     */
    private function vatReturnFixLink(VatReturnRefusal $refusal): ?array
    {
        [$kind, $key] = array_pad(explode(':', $refusal->fix, 2), 2, '');

        return match ($kind) {
            'mandator' => ['url' => VatReturnLayout::MANDATOR_URL, 'label' => $key === 'vat' ? 'Mandant öffnen — Reiter «UID und MWST»' : 'Mandant öffnen'],
            'tax-code' => ['url' => VatReturnLayout::TAX_CODES_URL, 'label' => 'MWST-Codes öffnen' . ($key !== '' ? ' — Code ' . $key : '')],
            default    => null,
        };
    }

    /**
     * The place a finding names ({@see \Z77\Module\Financial\VatReturn\VatReturnFinding::$reference})
     * as a link, null when there is none this module can name — another module's open work (the
     * Faktura-Journal, the Belegjournal, the Kreditorenjournal) says in its message where to go.
     *
     * @return array{url: string, label: string}|null
     */
    private function vatReturnReferenceLink(string $reference, VatPeriod $period, VatReturnPreview $preview): ?array
    {
        [$kind, $key] = array_pad(explode(':', $reference, 2), 2, '');

        return match (true) {
            $kind === 'account' && $key !== ''      => ['url' => ($this->vatReturnAccountUrl($period))($key), 'label' => 'Kontoblatt ' . $key],
            $kind === 'field' && $key !== ''        => $preview->breakdownOf($key) !== null
                ? ['url' => ($this->vatReturnEntriesUrl($period))($key), 'label' => 'Buchungen Ziffer ' . $key]
                : ['url' => $this->vatReturnUrl('form', $period) . '#ziffer-' . rawurlencode($key), 'label' => 'Ziffer ' . $key],
            $kind === 'reconciliation'              => ['url' => $this->vatReturnUrl('reconciliation', $period), 'label' => 'Abstimmung'],
            $kind === 'mandator'                    => ['url' => VatReturnLayout::MANDATOR_URL, 'label' => $key === 'accounts' ? 'Mandant — Reiter «Konten»' : 'Mandant'],
            // `bank-movement:{code}` — Close\BankReconciliationCheck's own reference (a bank account's code).
            $kind === 'bank-movement'               => ['url' => BankLayout::MOVEMENT_BASE . '/list' . ($key !== '' ? '?' . http_build_query(['account' => $key]) : ''), 'label' => 'Bankkonto'],
            default                                 => null,
        };
    }

    /**
     * The fiscal-year dropdown at the top of the rail: every year, the shown one current (on a
     * return page the year holding the period's end), each a link to the list of that year.
     *
     * @return array{years: list<FiscalYear>, current: FiscalYear, href: \Closure}|null
     */
    private function vatReturnYearSelection(?VatPeriod $period, ?FiscalYear $current = null): ?array
    {
        $years = $this->vatReturnYears();
        $all   = $years->allWithPeriods();
        if ($current === null && $period !== null) {
            $current = $years->findByDate($period->to) ?? $years->findByDate($period->from);
        }
        $current ??= FiscalYearSelection::of($years)->resolve('');
        if ($all === [] || $current === null) {
            return null;
        }
        $base = $this->vatReturnBase();

        return ['years' => $all, 'current' => $current, 'href' => static fn(string $code): string => $base . '/list?' . http_build_query(['year' => $code])];
    }

    /**
     * The document behind each entry of a page (invoice PDF, receipt photo, supplier PDF) from the
     * modules that posted them ({@see EntryDocuments}) — display only: a provider that fails is
     * logged and the page shows no document icon, as in the journal.
     *
     * @param list<VatReturnLine> $lines
     * @return array<int, \Z77\Module\Financial\Ledger\EntryDocument> entry id → document
     */
    private function vatReturnDocuments(array $lines): array
    {
        $queries = [];
        foreach ($lines as $line) {
            if ($line->sourceType !== null && $line->sourceRef !== null) {
                $queries[$line->entryId] = $line->documentQuery();
            }
        }
        if ($queries === []) {
            return [];
        }
        try {
            return EntryDocuments::fromModules(DI::getModuleManager())->documentsFor(array_values($queries));
        } catch (\Throwable $e) {
            error_log('vat return entry documents: ' . get_class($e) . ': ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The mandator for the PDF's facts, or null — a PDF never fails over it (the record cannot be
     * read: an upgrade half done); the facts then print without name and address.
     */
    private function vatReturnMandator(): ?Mandator
    {
        try {
            return (new CurrentMandator($this->em()))->find();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }
}
