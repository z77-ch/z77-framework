<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI;
use Z77\Core\Http\Response\BytesResponse;
use Z77\Core\Http\Response\FetchResponse;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Core\Http\Response\RedirectResponse;
use Z77\Core\Services\TemplateRenderer;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\FiscalYearCloseLog;
use Z77\Module\Financial\Entities\StoredVatYearOverview;
use Z77\Module\Financial\Pdf\VatYearArchive;
use Z77\Module\Financial\Pdf\VatYearPdf;
use Z77\Module\Financial\Repositories\FiscalYearCloseLogRepository;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\StoredVatYearOverviewRepository;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnFinding;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnRefusedException;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatTaxYear;
use Z77\Module\Financial\VatReturn\VatYearOverview;
use Z77\Module\Financial\VatReturn\VatYearService;
use Z77\Module\Financial\VatReturn\YearOverviewStore;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Shared\Attributes\Fetch;
use Z77\Shared\Attributes\HttpMethod;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «Finanzen › MWST-Abrechnungen › Jahresübersicht» — the yearly overview of the VAT return (stage S7
 * slices 7.2 and 7.5; owner S7-1 … S7-4, B-1 … B-5, C-D of 2026-11-25; boards MwstS7Einstieg,
 * MwstS7Seite, MwstS7Pdf). A REPORT: it posts nothing, the action cell stays EMPTY (backend-screen.md
 * §2). Mounted by a thin host controller in the backend (ADR-018 pattern); host side:
 * `use VatYearControllerTrait` + a one-line layout config delegating to {@see VatYearLayout::config()}.
 *
 * ONE tax year = ONE calendar year (S7-2). It is reached like every finance screen with
 * `?year=<fiscal-year code>` (the selection at the top of the rail) and shows the tax year whose
 * 31 December lies in that fiscal year (`VatTaxYear::forFiscalYear()`, owner B-3) — the tool
 * «Jahresübersicht» of the VAT return list and the link in «Jahr abschliessen» open
 * {@see VatYearLayout::yearUrl()}; the other year through the rail.
 *
 * The pages (GET) — every figure is the read model's ({@see VatYearService::overview()}):
 *
 *   - `corrections` (also `list`) — the returns of the year, the corrections reported for it per
 *     corrected period, the sum per field (declared · corrections · new, the exact value small under
 *     every tax amount — B-2), «Erfasst, noch nicht abgerechnet»;
 *   - `turnover` — the revenue accounts of the calendar year against the declaration (Art. 128
 *     para. 2 MWSTV), the corrections reported later beside it;
 *   - `input` — the VAT accounts (Art. 128 para. 3 MWSTV), 1179 and 2209 per 31.12. (B-1, with what of
 *     2209 is still open today), the input-tax corrections, the information block;
 *   - `pdf` — the same as a PDF, computed on request, «VORLÄUFIG» while not final;
 *   - `voucher?id=` — a STORED PDF, its SHA-256 verified (the protocol's icon opens it).
 *
 * The head: «vorläufig» (B-4) with its reasons, or «abgelegt» with «Abgelegt {Zeit} von {wer}» and
 * the stored PDF; «Seit der Ablage geändert — …» when the fingerprint differs from the newest storing
 * (B-5). The window `confirm-store` → `store` (Fetch POST): «Ablegen …», offered ONLY when the
 * overview is final and differs from the newest storing ({@see YearOverviewStore}).
 *
 * ACL: the finance controllers' module role (ADMIN) — who sees the VAT return list sees the overview
 * (board S7-1). The using class MUST provide (via its host base): `html()`, `fetch()`, `redirect()`,
 * `bytes()`, `em()`, `$layoutManager`, `$messageService`, `$help` (ADR-048).
 */
trait VatYearControllerTrait
{
    private ?VatYearService $vatYearService = null;

    private ?VatReturnService $vatYearReturnService = null;

    /** Entity-token scope of the window «Ablegen …» (the id is the tax year). */
    private const VAT_YEAR_STORE_TOKEN = 'vatYear.store';

    /** URL root of THIS mount — every link of the screen is built from it. */
    protected function vatYearBase(): string
    {
        return VatYearLayout::BASE;
    }

    /** The VAT return list — the crumb's parent and the return page of a period. */
    protected function vatYearReturnBase(): string
    {
        return VatReturnLayout::BASE;
    }

    /** The read model — one per request, sharing the request's return service. */
    protected function vatYearReads(): VatYearService
    {
        return $this->vatYearService ??= new VatYearService($this->em(), null, $this->vatYearReturns());
    }

    protected function vatYearReturns(): VatReturnService
    {
        return $this->vatYearReturnService ??= new VatReturnService($this->em());
    }

    /** The writer of «Ablegen …»; the actor is the session's identity. */
    protected function vatYearStore(): YearOverviewStore
    {
        return new YearOverviewStore($this->em());
    }

    // ── the page: three tabs ─────────────────────────────────────────────

    /** The mount's default action — the first tab. */
    protected function listAction(): HtmlResponse
    {
        return $this->vatYearView('corrections');
    }

    /** Tab «Korrekturen»: the returns, the corrections for the year, the sum per field, what is entered but not reported. */
    protected function correctionsAction(): HtmlResponse
    {
        return $this->vatYearView('corrections');
    }

    /** Tab «Umsatz»: the revenue accounts of the calendar year against the declaration (Art. 128 para. 2 MWSTV). */
    protected function turnoverAction(): HtmlResponse
    {
        return $this->vatYearView('turnover');
    }

    /** Tab «Vorsteuer»: the VAT accounts, 1179, 2209, the input-tax corrections, the information. */
    protected function inputAction(): HtmlResponse
    {
        return $this->vatYearView('input');
    }

    /**
     * The overview as a PDF (`?year=`), computed on request, nothing archived — «VORLÄUFIG» in the
     * title while it is not final. A refused year → its page.
     */
    protected function pdfAction(): BytesResponse|RedirectResponse
    {
        $resolved = $this->vatYearResolve();
        if ($resolved['overview'] === null) {
            return $this->redirect($this->vatYearUrl('corrections', $resolved['fiscalYear']));
        }
        $overview = $resolved['overview'];
        $pdf      = VatYearPdf::of($overview, $this->vatYearMandator(), new \DateTimeImmutable(), null, $this->vatYearRoundingAccount(), $this->vatYearClosedAt($overview));

        return $this->bytes($pdf->output(), VatYearPdf::fileName($overview->taxYear->year, $overview->final), 'application/pdf');
    }

    /**
     * A STORED overview (`?id=`) — the bytes written by «Ablegen …», verified against the row's
     * SHA-256; a missing or altered file is never served: the page says so.
     */
    protected function voucherAction(): BytesResponse|RedirectResponse
    {
        $id     = (int) $this->vatYearParameter('id');
        $stored = $id > 0 ? $this->vatYearStoredRows()->find($id) : null;
        if (!$stored instanceof StoredVatYearOverview) {
            return $this->redirect($this->vatYearBase() . '/corrections');
        }
        $bytes = (new VatYearArchive())->bytes($stored->getPdfPath(), $stored->getPdfSha256());
        if ($bytes === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Das abgelegte PDF der ' . VatYearLayout::title($stored->getTaxYear())
                . ' fehlt im Archiv oder wurde verändert (' . $stored->getPdfPath() . ') — aus der Datensicherung zurückholen.');

            return $this->redirect($this->vatYearUrl('corrections', $this->vatYearFiscalYears()->findByDate(new \DateTimeImmutable($stored->getTaxYear() . '-12-31'))));
        }

        return $this->bytes($bytes, $stored->getPdfName(), 'application/pdf');
    }

    // ── «Ablegen …» ──────────────────────────────────────────────────────

    /**
     * The window «Ablegen …» (`?year=`): what is stored — the tax year, «Stand», the corrections, the
     * fiscal year — and the one sentence what happens. Not final, or unchanged since the newest
     * storing → «Ablegen nicht möglich» with the reason, no button.
     */
    protected function confirmStoreAction(): HtmlResponse
    {
        $resolved = $this->vatYearResolve();
        $overview = $resolved['overview'];
        $refusal  = $resolved['refusal']?->message;
        $stored   = null;
        $fingerprint = '';
        if ($overview !== null) {
            $fingerprint = $this->vatYearReads()->fingerprint($overview->taxYear->year);
            $stored      = $this->vatYearStoredRows()->newest($overview->taxYear->year);
            if (!$overview->final) {
                $refusal = VatYearLayout::title($overview->taxYear->year) . ' ist vorläufig (' . implode(' · ', VatYearLayout::provisionalReasons($overview)) . ') — abgelegt wird nach dem Abschluss.';
            } elseif ($stored !== null && hash_equals($stored->getFingerprint(), $fingerprint)) {
                $refusal = VatYearLayout::title($overview->taxYear->year) . ' hat sich seit der Ablage vom ' . $stored->getStandAt()->format('d.m.Y') . ' nicht geändert.';
            }
        }

        $response = $this->html([
            'overview'    => $overview,
            'refusal'     => $refusal,
            'stand'       => new \DateTimeImmutable(),
            'closedAt'    => $overview === null ? null : $this->vatYearClosedAt($overview),
            'fingerprint' => $fingerprint,
            'entityCsrf'  => $overview === null ? '' : DI::getCsrfService()->generateEntityToken(self::VAT_YEAR_STORE_TOKEN, $overview->taxYear->year),
            'base'        => $this->vatYearBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmStore', 'Backend/VatYearController', VatYearLayout::NS);

        return $response;
    }

    /**
     * «Ablegen» (Fetch POST from the window): the tax year and the fingerprint the window showed →
     * {@see YearOverviewStore::store()}. Nothing is read before the service — it takes its locks first.
     * A refusal keeps the window and shows its sentence; success → the page, now «abgelegt».
     */
    #[Fetch, HttpMethod('POST')]
    protected function storeAction(): FetchResponse
    {
        $body    = DI::getRequest()->getJsonBody();
        $taxYear = (int) ($body['tax_year'] ?? 0);
        if ($taxYear <= 0 || !DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), self::VAT_YEAR_STORE_TOKEN, $taxYear)) {
            return $this->vatYearWindowRefusal('Das Fenster ist abgelaufen — schliessen und «Ablegen …» nochmals öffnen.');
        }
        try {
            $stored = $this->vatYearStore()->store($taxYear, is_string($body['fingerprint'] ?? null) ? $body['fingerprint'] : '');
        } catch (VatReturnRefusedException $e) {
            return $this->vatYearWindowRefusal($e->getMessage());
        }
        $this->messageService->pushFlashAfterRedirect('success', VatYearLayout::title($taxYear) . ' abgelegt — im Protokoll «Abschlüsse und MWST» vermerkt.');

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->setRedirect($this->vatYearUrl('corrections', $this->vatYearFiscalYears()->findByDate(new \DateTimeImmutable($stored->getTaxYear() . '-12-31'))));
    }

    // ── the frame ────────────────────────────────────────────────────────

    private function vatYearView(string $tab): HtmlResponse
    {
        $resolved = $this->vatYearResolve($tab);
        $overview = $resolved['overview'];
        $context  = [
            'fySelection' => $resolved['fySelection'],
            'tab'        => $tab,
            'tabs'       => VatYearLayout::TABS,
            'base'       => $this->vatYearBase(),
            'fiscalYear' => $resolved['fiscalYear'],
            'taxYear'    => $resolved['taxYear'],
            'overview'   => $overview,
            'refusal'    => $resolved['refusal'],
            'fix'        => $resolved['refusal'] === null ? null : $this->vatYearFixLink($resolved['refusal']),
            'notices'    => $resolved['notices'],
            'tabUrl'     => fn(string $action): string => $this->vatYearUrl($action, $resolved['fiscalYear']),
            'returnBase' => $this->vatYearReturnBase(),
            'fmt'        => static fn(?Money $m): string => AmountFormat::of($m),
        ];
        if ($overview !== null) {
            $context += $this->vatYearPageContext($overview, $resolved['fiscalYear']);
        }

        // The overview's help (ADR-048): a page, not a list — its trigger stands in the top bar.
        $this->help->attach('Backend/VatYearController/year.help', VatYearLayout::NS, [], 'Hilfe: MWST-Jahresübersicht');
        $response = $this->html($context);
        $this->layoutManager->addPartials('crumb', 'Backend/VatYearController', VatYearLayout::NS, 'hc3');
        if ($overview !== null) {
            $this->layoutManager->addPartials('tabs', 'Backend/VatYearController', VatYearLayout::NS, 'hc2');
        }
        if ($resolved['fySelection'] !== null) {
            $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', VatYearLayout::NS, 'railSelect');
        }

        return $response;
    }

    /**
     * What the page shows beside the overview: the moment of reading, the newest storing and whether
     * the overview changed since, the tools, the open points with their links, the link builders.
     *
     * @return array<string, mixed>
     */
    private function vatYearPageContext(VatYearOverview $overview, ?FiscalYear $fiscalYear): array
    {
        $year        = $overview->taxYear->year;
        $stored      = $this->vatYearStoredRows()->newest($year);
        $fingerprint = $this->vatYearReads()->fingerprint($year);
        $changed     = $stored !== null && !hash_equals($stored->getFingerprint(), $fingerprint);
        $accountUrl  = $this->vatYearAccountUrl($overview);
        $rows        = [];
        foreach ($overview->findings as $finding) {
            $rows[] = ['finding' => $finding, 'red' => self::vatYearIsRemainder($finding), 'link' => $this->vatYearFindingLink($finding, $fiscalYear, $accountUrl)];
        }

        return [
            'stand'           => new \DateTimeImmutable(),
            'closedAt'        => $this->vatYearClosedAt($overview),
            'stored'          => $stored,
            'changed'         => $changed,
            'storedUrl'       => $stored === null ? null : $this->vatYearBase() . '/voucher?' . http_build_query(['id' => $stored->getId()]),
            'pdfUrl'          => $this->vatYearUrl('pdf', $fiscalYear),
            // B-4 / B-5: «Ablegen …» only when final, and only when it shows something new.
            'storeUrl'        => $overview->final && ($stored === null || $changed) ? $this->vatYearUrl('confirm-store', $fiscalYear) : null,
            'findingRows'     => $rows,
            'accountUrl'      => $accountUrl,
            'returnUrl'       => fn(VatPeriod $period): string => $this->vatYearReturnBase() . '/form?' . http_build_query(['from' => $period->from->format('Y-m-d')]),
            'roundingAccount' => $this->vatYearRoundingAccount(),
            'inputFields'     => VatYearLayout::inputCorrectionFields($overview),
            'currency'        => $overview->payables->currency,
        ];
    }

    /**
     * The request's fiscal year (rail selection, `?year=`), its tax year (B-3) and the overview — or
     * why there is none (the settings gate, no fiscal year, a tax year without a VAT period).
     *
     * @param string $tab the tab the rail's year links keep
     * @return array{fiscalYear: ?FiscalYear, taxYear: ?int, overview: ?VatYearOverview, refusal: ?VatReturnRefusal, notices: list<string>, fySelection: ?array}
     */
    private function vatYearResolve(string $tab = 'corrections'): array
    {
        $years     = $this->vatYearFiscalYears();
        $selection = FiscalYearSelection::of($years);
        $code      = $this->vatYearParameter('year');
        $fiscal    = $selection->resolve($code);
        $notices   = [];
        if ($code !== '' && $fiscal?->getCode() !== $code) {
            $notices[] = "Geschäftsjahr «{$code}» gibt es nicht — das gewählte wird gezeigt.";
        }
        $result = ['fiscalYear' => $fiscal, 'taxYear' => null, 'overview' => null, 'refusal' => null, 'notices' => $notices, 'fySelection' => null];
        if ($fiscal !== null) {
            $base = $this->vatYearBase();
            $result['fySelection'] = ['years' => $selection->all(), 'current' => $fiscal, 'href' => static fn(string $c): string => $base . '/' . $tab . '?' . http_build_query(['year' => $c])];
        }
        $gate = $this->vatYearReturns()->settingsRefusal();
        if ($gate !== null) {
            return ['refusal' => $gate] + $result;
        }
        if ($fiscal === null) {
            return ['refusal' => new VatReturnRefusal(VatReturnRefusal::NO_PERIOD, 'Noch kein Geschäftsjahr eröffnet — ohne Geschäftsjahr gibt es keine MWST-Jahresübersicht.', 'fiscal-years')] + $result;
        }
        $result['taxYear'] = VatTaxYear::forFiscalYear($fiscal);
        try {
            $result['overview'] = $this->vatYearReads()->overview($result['taxYear']);
        } catch (VatReturnRefusedException $e) {
            $result['refusal'] = $e->refusal;
        }

        return $result;
    }

    // ── links, findings ──────────────────────────────────────────────────

    /** `/backend/finance/vat-year/{action}?year={code}` of a fiscal year (without one: the rail's selection). */
    private function vatYearUrl(string $action, ?FiscalYear $fiscalYear): string
    {
        return $this->vatYearBase() . '/' . $action . ($fiscalYear === null ? '' : '?' . http_build_query(['year' => $fiscalYear->getCode()]));
    }

    /**
     * account number → its account statement over the tax year. A report range lies inside ONE
     * fiscal year: the one holding 31 December, the calendar year cut to it.
     */
    private function vatYearAccountUrl(VatYearOverview $overview): \Closure
    {
        $year = $overview->yearEndFiscalYear;
        $base = VatReturnLayout::REPORT_BASE . '/account-statement';
        if ($year === null) {
            return static fn(string $number): string => $base . '?' . http_build_query(['account' => $number]);
        }
        $from = max($overview->taxYear->firstDay()->format('Y-m-d'), $year->getStartDate()->format('Y-m-d'));
        $to   = min($overview->taxYear->lastDay()->format('Y-m-d'), $year->getEndDate()->format('Y-m-d'));

        return static fn(string $number): string => $base . '?' . http_build_query(['year' => $year->getCode(), 'from' => $from, 'to' => $to, 'account' => $number]);
    }

    /** A remainder of the overview — red, never blocking (small rule 3 of S7). */
    private static function vatYearIsRemainder(VatReturnFinding $finding): bool
    {
        return in_array($finding->code, [VatReturnFinding::VAT_ACCOUNT_REMAINDER, VatReturnFinding::TURNOVER_REMAINDER, VatYearService::FINDING_INPUT_PENDING_BALANCE], true);
    }

    /**
     * The link of one open point: a VAT-account remainder opens the tab «Vorsteuer» at that account,
     * the turnover remainder the tab «Umsatz», an account its statement over the year, a refused
     * period the place its refusal names.
     *
     * @return array{url: string, label: string}|null
     */
    private function vatYearFindingLink(VatReturnFinding $finding, ?FiscalYear $fiscalYear, \Closure $accountUrl): ?array
    {
        [$kind, $key] = array_pad(explode(':', $finding->reference, 2), 2, '');

        return match (true) {
            $finding->code === VatReturnFinding::VAT_ACCOUNT_REMAINDER && $key !== '' => ['url' => $this->vatYearUrl('input', $fiscalYear) . '#vat-account-' . rawurlencode($key), 'label' => 'Vorsteuer'],
            $finding->code === VatReturnFinding::TURNOVER_REMAINDER                 => ['url' => $this->vatYearUrl('turnover', $fiscalYear), 'label' => 'Umsatz'],
            $kind === 'account' && $key !== ''                                        => ['url' => $accountUrl($key), 'label' => 'Kontoblatt ' . $key],
            default                                                                   => $this->vatYearFixLink(new VatReturnRefusal('', '', $finding->reference)),
        };
    }

    /**
     * The place a refusal names ({@see VatReturnRefusal::$fix}) as a link, null when it names none.
     *
     * @return array{url: string, label: string}|null
     */
    private function vatYearFixLink(VatReturnRefusal $refusal): ?array
    {
        [$kind, $key] = array_pad(explode(':', $refusal->fix, 2), 2, '');

        return match ($kind) {
            'mandator'                     => ['url' => VatReturnLayout::MANDATOR_URL, 'label' => $key === 'vat' ? 'Mandant öffnen — Reiter «UID und MWST»' : 'Mandant öffnen'],
            'tax-code'                     => ['url' => VatReturnLayout::TAX_CODES_URL, 'label' => 'MWST-Codes öffnen' . ($key !== '' ? ' — Code ' . $key : '')],
            'fiscal-years', 'fiscal-year'  => ['url' => VatReturnLayout::FISCAL_YEAR_URL, 'label' => 'Geschäftsjahre'],
            default                        => null,
        };
    }

    /**
     * A refusal of the window's POST: the window stays, its alert slot `[data-vat-year-store-refusal]`
     * shows the sentence (escaped by the template; the replacement keeps the slot's marker).
     */
    private function vatYearWindowRefusal(string $message): FetchResponse
    {
        $html = (new TemplateRenderer(VatYearLayout::NS))->partial('Backend/VatYearController/windowRefusal', ['message' => $message]);

        return $this->fetch()
            ->setStatus('error')
            ->addCommand('replace-html', ['target' => '[data-vat-year-store-refusal]', 'html' => $html]);
    }

    // ── reads ────────────────────────────────────────────────────────────

    /** A GET parameter as a trimmed string — '' when absent or not a scalar. */
    private function vatYearParameter(string $name): string
    {
        $value = DI::getRequest()->getGetParameter($name);

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    private function vatYearFiscalYears(): FiscalYearRepository
    {
        return $this->em()->getRepository(FiscalYear::class);
    }

    private function vatYearStoredRows(): StoredVatYearOverviewRepository
    {
        return $this->em()->getRepository(StoredVatYearOverview::class);
    }

    /** When the fiscal year holding 31 December was closed — the newest close of the protocol; null while open. */
    private function vatYearClosedAt(VatYearOverview $overview): ?\DateTimeImmutable
    {
        $year = $overview->yearEndFiscalYear;
        if ($year === null || !$year->isClosed()) {
            return null;
        }
        /** @var FiscalYearCloseLogRepository $log */
        $log = $this->em()->getRepository(FiscalYearCloseLog::class);

        return $log->lastClosedAt((int) $year->getId());
    }

    /** The mandator's rounding account of the VAT return (KMU 6945), null when none is named or the record cannot be read. */
    private function vatYearRoundingAccount(): ?string
    {
        try {
            return (new LedgerService($this->em()))->vatReturnAccount(LedgerService::VAT_ROUNDING_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    /** The mandator for the PDF's facts, or null — a PDF never fails over it. */
    private function vatYearMandator(): ?Mandator
    {
        try {
            return (new CurrentMandator($this->em()))->find();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }
}
