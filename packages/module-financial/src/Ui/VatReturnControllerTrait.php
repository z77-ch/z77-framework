<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI;
use Z77\Core\Http\Response\BytesResponse;
use Z77\Core\Http\Response\FetchResponse;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Core\Http\Response\RedirectResponse;
use Z77\Core\Services\TemplateRenderer;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Financial\Entities\VatReturnLog;
use Z77\Module\Financial\Entities\VatReturnLogAction;
use Z77\Module\Financial\Ledger\EntryDocuments;
use Z77\Module\Financial\Pdf\VatReturnArchive;
use Z77\Module\Financial\Pdf\VatReturnPdf;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\VatReturnRepository;
use Z77\Module\Financial\VatReturn\VatAccountViolations;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Module\Financial\VatReturn\VatReturnField;
use Z77\Module\Financial\VatReturn\VatReturnFinding;
use Z77\Module\Financial\VatReturn\VatReturnLine;
use Z77\Module\Financial\VatReturn\VatReturnPostingService;
use Z77\Module\Financial\VatReturn\VatReturnPreview;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnRefusedException;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Financial\VatReturn\VatSettlementPlan;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Attributes\Fetch;
use Z77\Shared\Attributes\HttpMethod;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «Finanzen › MWST-Abrechnungen» — the VAT return (P5 part 2: the read-only screens of stage S1b,
 * the screens of «MWST buchen» and «Aufheben» of stage S2, owner decisions of 2026-11-24 and
 * 2026-11-25) — mounted by a thin host controller in the backend (ADR-018 pattern, like the
 * reports). Host side: `use VatReturnControllerTrait` + a one-line layout config delegating to
 * {@see VatReturnLayout::config()}.
 *
 * The pages (GET):
 *
 *   - `list` — the returns of the fiscal year chosen at the top of the rail (the periods that END
 *     in it), one row per period with its valid return; the action cell «Neue Abrechnung» opens
 *     the next elapsed open period; the tool «Jahresübersicht» in the toolbar (the yearly overview
 *     of the tax year the fiscal year opens — {@see VatYearLayout::linkFor()}, none while it would
 *     open nothing); the ⋮ on the newest posted row for who may lift; the last
 *     three protocol rows under the table; a settings refusal instead of the list when the
 *     mandator's VAT settings allow none;
 *   - `form` / `origin` / `reconciliation` — one period (`?from=` a day of it) in three views. An
 *     OPEN period: computed from the journal; the action cell «MWST buchen …» when the plan of
 *     {@see VatReturnPostingService::plan()} has no blocking finding, else empty — the reason is
 *     then the first row of «Offene Punkte». A POSTED period: «Formular» shows the STORED figures
 *     (`vat_return_figure`), the head who / when / the entry; the tools «Beleg» and, for who may
 *     lift the latest return, «Aufheben …»; «Herkunft» and «Abstimmung» are computed from the
 *     journal as before — with one hint line on top when they no longer match what was posted
 *     ({@see VatReturnService::changedSincePosting()}); «Abstimmung» lists under each VAT account
 *     the entries that do not fit the lock ({@see VatAccountViolations}), and a gap on a VAT account
 *     in «Offene Punkte» links there;
 *   - `revision` — one stored return by id (`?id=`), read only: a lifted revision (posted, lifted,
 *     the reason, the reversal) with its figures and its voucher;
 *   - `entries` — the coded lines of one form field (a standard list, {@see VatReturnEntriesListing});
 *   - `pdf` — the computed return of an open period as a PDF ({@see VatReturnPdf}); `voucher` —
 *     the ARCHIVED voucher of a posted return (`?id=`), its SHA-256 verified
 *     ({@see VatReturnArchive::bytes()});
 *   - `actions` — the ⋮ of a list row (a context menu): «Aufheben …».
 *
 * The windows (ADR-047 / ADR-049, a fetched dialog with its action row on top):
 *
 *   - `confirm-post` → `post` (Fetch POST): «MWST buchen» — the plan (period, payable amount,
 *     rounding, the settlement entry through the shared `entrySnapshot` partial, the warnings with
 *     ONE mandatory tick); the POST carries both fingerprints of the plan back to
 *     {@see VatReturnPostingService::post()}. A refusal keeps the window and shows its sentence
 *     (and its findings) in the window; success → the page, now posted;
 *   - `confirm-lift` → `lift` (Fetch POST): «Aufheben» — ADMIN only (the host's access config pins
 *     both actions, like «Wieder öffnen» of a fiscal year), the latest return only, a mandatory
 *     reason; id + version + reason to {@see VatReturnPostingService::lift()}.
 *
 * `post` reads nothing through the entity manager before it calls the service (no mandator, fiscal
 * year or tax code in the identity map — the service's step 6 must read them fresh): the period
 * comes from the body as from / to / rhythm ({@see VatPeriod::stored()}), the service checks it
 * against the calendar.
 *
 * Every figure is the services' — this trait resolves the period, picks the template and turns the
 * opaque places of refusals and findings into links. ACL: the finance controllers' module role
 * (ADMIN) for everything; `confirmLiftAction` / `liftAction` pinned to ADMIN in the backend's access
 * config. The using class MUST provide (via its host base): `html()`, `fetch()`, `redirect()`,
 * `bytes()`, `em()`, `$layoutManager`, `$messageService`, `$help` (ADR-048).
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

    /** The writer — «MWST buchen» and «Aufheben»; the actor is the session's identity. */
    protected function vatReturnPoster(): VatReturnPostingService
    {
        return new VatReturnPostingService($this->em());
    }

    /**
     * May the current user reach $action of THIS mount? `AuthService::canReach()` answers from the
     * host's access config (backend.md: a control's visibility is decided with canReach, never
     * with a hard-coded role) — «Aufheben …» is shown only to who may reach `lift`. Presentation
     * only: the dispatcher enforces the access config on the action itself.
     */
    protected function vatReturnCanReach(string $action): bool
    {
        $segments = explode('/', trim($this->vatReturnBase(), '/'));
        if (count($segments) !== 3) {
            return false;
        }
        $auth = DI::getAuthService();

        return $auth->canReach($auth->getCurrentUser(), $segments[0], $segments[1], $segments[2], $action);
    }

    private function vatReturnYears(): FiscalYearRepository
    {
        return $this->em()->getRepository(FiscalYear::class);
    }

    private function vatReturnRows(): VatReturnRepository
    {
        return $this->em()->getRepository(VatReturn::class);
    }

    /** Entity-token scopes per window: a token of «MWST buchen» does not authorise a lift. */
    private const VAT_RETURN_POST_TOKEN = 'vatReturn.post';
    private const VAT_RETURN_LIFT_TOKEN = 'vatReturn.lift';

    // ── the list ─────────────────────────────────────────────────────────

    /**
     * The returns of the selected fiscal year — or, when the mandator's VAT settings allow none,
     * that one refusal with the link to the place that clears it (the menu entry is hidden
     * only for a mandator not liable to VAT — {@see VatReturnMenuCondition}; a typed URL still
     * lands here and gets the refusal).
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
        $log  = [];
        if ($refusal === null && $year !== null) {
            $latest  = $service->settlement()->latest();
            $canLift = $latest !== null && $this->vatReturnCanReach('lift');
            foreach ($service->periods($year) as $summary) {
                $posted  = $summary->posted;
                $rows[]  = [
                    'summary'   => $summary,
                    'label'     => VatReturnLayout::periodLabel($summary->period),
                    'state'     => $posted === null ? 'open' : 'posted',
                    'url'       => $this->vatReturnUrl('form', $summary->period),
                    'pdfUrl'    => $posted !== null ? $this->vatReturnVoucherUrl($posted) : ($summary->refusal === null ? $this->vatReturnUrl('pdf', $summary->period) : null),
                    'pdfLabel'  => $posted !== null ? 'Beleg' : 'PDF',
                    'menuUrl'   => $canLift && $posted !== null && $posted->getId() === $latest->getId() ? $base . '/actions?' . http_build_query(['id' => $posted->getId()]) : null,
                    'liftedUrl' => $summary->lifted === null ? null : $this->vatReturnRevisionUrl($summary->lifted),
                    'fix'       => $summary->refusal === null ? null : $this->vatReturnFixLink($summary->refusal),
                ];
            }
            $next = $service->nextReturn();
            $log  = $this->vatReturnRows()->latestLog(VatReturnLayout::LOG_ROWS);
        }
        $hasMenu = array_filter($rows, static fn(array $r): bool => $r['menuUrl'] !== null) !== [];
        // The tool «Jahresübersicht» (board S7-1): the tax year whose 31.12. lies in the selected fiscal
        // year (owner B-3) — the same rule as the link in «Jahr abschliessen»; null = no tool.
        $overview = $year === null ? null : VatYearLayout::linkFor($year, $service);

        // The list's help (ADR-048, HELP-003): at the right end of the toolbar.
        $this->help->attach('Backend/VatReturnController/list.help', VatReturnLayout::NS, [], 'Hilfe: MWST-Abrechnungen', toolbar: true);
        $response = $this->html([
            'refusal'     => $refusal,
            'fix'         => $refusal === null ? null : $this->vatReturnFixLink($refusal),
            'year'        => $year,
            'rows'        => $rows,
            'hasMenu'     => $hasMenu,
            'log'         => $log,
            'logUrl'      => VatReturnLayout::PROTOCOL_URL,
            'rhythm'      => $rows === [] ? '' : (\Z77\Module\Mandator\Ui\MandatorLayout::VAT_RHYTHMS[$rows[0]['summary']->period->rhythm->value] ?? ''),
            'notices'     => $notices,
            'nextUrl'     => $next === null ? null : $this->vatReturnUrl('form', $next),
            'nextLabel'   => $next === null ? '' : VatReturnLayout::periodLabel($next),
            'fySelection' => $refusal === null ? $this->vatReturnYearSelection(null, $year) : null,
            'base'        => $base,
            'fmt'         => static fn(?Money $m): string => AmountFormat::of($m),
            'yearOverviewUrl' => $overview['url'] ?? null,
        ]);
        // The action cell: «Neue Abrechnung» — empty when every elapsed period is settled (owner decision 3 of 2026-11-25).
        if ($next !== null) {
            $this->layoutManager->addPartials('act', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc1');
        }
        // The toolbar: the one tool of the list, secondary — only when the tax year has a VAT period.
        if ($overview !== null) {
            $this->layoutManager->addPartials('listTools', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc2');
        }
        // The fiscal year holds for the whole area: the top of the rail (the dropdown the journal shares).
        $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', VatReturnLayout::NS, 'railSelect');

        return $response;
    }

    /**
     * The ⋮ of a list row — a context menu (backend-screen.md §4): «Aufheben …» on the latest posted
     * return for who may lift; else a muted line.
     */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $return = $this->vatReturnById();
        if ($return === null) {
            return $this->fetch()->setStatus('error');
        }
        $latest = $this->vatReturns()->settlement()->latest();
        $response = $this->html([
            'return'  => $return,
            'label'   => VatReturnLayout::periodLabel($return->period()),
            'canLift' => $latest !== null && $latest->getId() === $return->getId() && $this->vatReturnCanReach('lift'),
            'base'    => $this->vatReturnBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('actions', 'Backend/VatReturnController', VatReturnLayout::NS);

        return $response;
    }

    // ── one return: three views ──────────────────────────────────────────

    /** Tab «Formular»: the open points, then the form in field order (the stored figures of a posted period). */
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
     * One stored return by id (`?id=`), read only — what the list's hint «Fassung n aufgehoben»
     * opens: the head with posted / lifted / reason / reversal, the stored figures, the tool
     * «Beleg». A LIVE return is the period's page — redirected there.
     */
    protected function revisionAction(): HtmlResponse|RedirectResponse
    {
        $return = $this->vatReturnById();
        if ($return === null) {
            return $this->redirect($this->vatReturnBase() . '/list');
        }
        if ($return->isLive()) {
            return $this->redirect($this->vatReturnUrl('form', $return->period()));
        }
        $period = $return->period();

        $this->help->attach('Backend/VatReturnController/return.help', VatReturnLayout::NS, [], 'Hilfe: MWST-Abrechnung');
        $response = $this->html($this->vatReturnFrame('form', $period, null, null) + $this->vatReturnStoredContext($return) + [
            'crumbField'  => '',
            'findingRows' => [],
            'revision'    => true,
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('form', 'Backend/VatReturnController', VatReturnLayout::NS);
        $this->layoutManager->addPartials('crumb', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc3');
        $this->layoutManager->addPartials('tabs', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc2');
        $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', VatReturnLayout::NS, 'railSelect');

        return $response;
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
     * The return of an OPEN period as a PDF (`?from=`): the facts, the form with the exact tax per
     * rate field, the difference declared vs. posted, the open points and the sentence that it is
     * no filing. Answered inline, computed on every call, nothing archived. A posted period → its
     * archived voucher; a refused period → its page.
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
        $posted = $this->vatReturns()->settlement()->returnCovering($period->to);
        if ($posted !== null) {
            return $this->redirect($this->vatReturnVoucherUrl($posted));
        }
        try {
            $preview  = $this->vatReturns()->preview($period);
            $findings = $this->vatReturns()->findings($period);
        } catch (VatReturnRefusedException) {
            return $this->redirect($this->vatReturnUrl('form', $period));
        }
        $label = VatReturnLayout::periodLabel($period);
        $pdf   = VatReturnPdf::of($preview, $findings, $label, $this->vatReturnMandator(), (new \DateTimeImmutable())->format('d.m.Y H:i'), $this->vatReturnCorrections($period));

        return $this->bytes($pdf->output(), VatReturnPdf::fileName($label), 'application/pdf');
    }

    /**
     * The ARCHIVED voucher of a posted return (`?id=`) — the bytes written at posting, verified
     * against the row's SHA-256 ({@see VatReturnArchive::bytes()}); a lifted revision keeps its
     * voucher byte for byte (owner decision 10 of 2026-11-25). A file that is missing or altered is
     * never served: the page of the return says so.
     */
    protected function voucherAction(): BytesResponse|RedirectResponse
    {
        $return = $this->vatReturnById();
        if ($return === null) {
            return $this->redirect($this->vatReturnBase() . '/list');
        }
        $bytes = (new VatReturnArchive())->bytes($return->getVoucherPath(), $return->getVoucherSha256());
        if ($bytes === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Der Beleg der MWST-Abrechnung ' . VatReturnLayout::periodLabel($return->period())
                . ' fehlt im Archiv oder wurde verändert (' . $return->getVoucherPath() . ') — aus der Datensicherung zurückholen.');

            return $this->redirect($return->isLive() ? $this->vatReturnUrl('form', $return->period()) : $this->vatReturnRevisionUrl($return));
        }

        return $this->bytes($bytes, $return->getVoucherName(), 'application/pdf');
    }

    // ── «MWST buchen» ────────────────────────────────────────────────────

    /**
     * The window «MWST buchen» (`?from=`): the plan of {@see VatReturnPostingService::plan()} —
     * lock-free, nothing written. Blocked (something came up since the page was drawn) or refused
     * → the reason, no button.
     */
    protected function confirmPostAction(): HtmlResponse|FetchResponse
    {
        $resolved = $this->vatReturnPeriod();
        $period   = $resolved[0] ?? null;
        $plan     = null;
        $refusal  = $resolved[1] ?? null;
        if ($period !== null && $refusal === null) {
            try {
                $plan = $this->vatReturnPoster()->plan($period);
            } catch (VatReturnRefusedException $e) {
                $refusal = $e->refusal;
            }
        }
        if ($period === null && $refusal === null) {
            $refusal = new VatReturnRefusal(VatReturnRefusal::NOT_A_PERIOD, 'Keine MWST-Periode gewählt — die Liste der MWST-Abrechnungen neu laden.');
        }

        $names    = $plan === null ? [] : $this->vatReturnPlanAccounts($plan);
        $response = $this->html([
            'period'      => $period,
            'periodLabel' => $period === null ? '' : VatReturnLayout::periodLabel($period),
            'plan'        => $plan,
            'refusal'     => $refusal,
            'blocking'    => $plan?->blocking() ?? [],
            'warnings'    => $plan?->warnings() ?? [],
            'snapshot'    => $plan === null ? ['lines' => []] : self::vatReturnPlanSnapshot($plan, $names),
            'accounts'    => $names,
            'methodLabel' => $plan?->preview->methodLabel ?? '',
            'entityCsrf'  => $period === null ? '' : DI::getCsrfService()->generateEntityToken(self::VAT_RETURN_POST_TOKEN, $period->from->format('Y-m-d')),
            'base'        => $this->vatReturnBase(),
            'fmt'         => static fn(?Money $m): string => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmPost', 'Backend/VatReturnController', VatReturnLayout::NS);

        return $response;
    }

    /**
     * «MWST jetzt buchen» (Fetch POST from the window): from / to / rhythm of the period, the
     * figures fingerprint the window showed, the warnings fingerprint only with the tick. Nothing is
     * read before the service — it takes its locks first and reads fresh after them. A refusal
     * answers the window with the sentence (and the findings of `blocked` / `warnings-*`) in its
     * alert slot; success → the period's page.
     */
    #[Fetch, HttpMethod('POST')]
    protected function postAction(): FetchResponse
    {
        $body   = DI::getRequest()->getJsonBody();
        $from   = self::vatReturnDay($body['from'] ?? null);
        $to     = self::vatReturnDay($body['to'] ?? null);
        $rhythm = is_string($body['rhythm'] ?? null) ? VatRhythm::tryFrom($body['rhythm']) : null;
        if ($from === null || $to === null || $rhythm === null || $to < $from) {
            return $this->vatReturnWindowRefusal('post', 'Die MWST-Periode fehlt im Formular — das Fenster schliessen und die Abrechnung neu öffnen.');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), self::VAT_RETURN_POST_TOKEN, $from->format('Y-m-d'))) {
            return $this->vatReturnWindowRefusal('post', 'Das Fenster ist abgelaufen — schliessen und «MWST buchen …» nochmals öffnen.');
        }
        $period    = VatPeriod::stored($from, $to, $rhythm);
        $figures   = is_string($body['figures_fingerprint'] ?? null) ? $body['figures_fingerprint'] : '';
        // What the poster confirmed: the warnings' fingerprint the window SHOWED — only with the tick.
        $ticked    = in_array($body['confirm_warnings'] ?? false, [true, '1', 1], true);
        $warnings  = $ticked && is_string($body['warnings_fingerprint'] ?? null) && $body['warnings_fingerprint'] !== '' ? $body['warnings_fingerprint'] : null;

        try {
            $return = $this->vatReturnPoster()->post($period, $figures, $warnings);
        } catch (VatReturnRefusedException $e) {
            return $this->vatReturnWindowRefusal('post', $e->getMessage(), $e->findings);
        }
        $entry = $return->getSettlementEntry();
        $this->messageService->pushFlashAfterRedirect('success', 'MWST-Abrechnung ' . VatReturnLayout::periodLabel($return->period()) . ' gebucht — '
            . ($entry === null ? 'Nullabrechnung, keine Buchung.' : 'Buchung ' . VatReturnLayout::entryRef($entry) . '.'));

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->setRedirect($this->vatReturnUrl('form', $return->period()));
    }

    // ── «Aufheben» ───────────────────────────────────────────────────────

    /**
     * The window «Aufheben» (`?id=`): what is lifted (the return, its entry, the payable amount, the
     * reversal's date and lines) and the mandatory reason. Not the latest, or lifted already → the
     * sentence, no button. ADMIN only — the host's access config.
     */
    protected function confirmLiftAction(): HtmlResponse|FetchResponse
    {
        $return  = $this->vatReturnById();
        $refusal = null;
        if ($return === null) {
            $refusal = 'Diese MWST-Abrechnung gibt es nicht.';
        } elseif (!$return->isLive()) {
            $refusal = 'Die MWST-Abrechnung ' . VatReturnLayout::periodLabel($return->period()) . ' ist bereits aufgehoben (am '
                . $return->getLiftedAt()?->format('d.m.Y') . ' von ' . $return->getLiftedBy() . ').';
        } else {
            $latest = $this->vatReturns()->settlement()->latest();
            if ($latest !== null && $latest->getId() !== $return->getId()) {
                $refusal = 'Aufgehoben wird nur die neueste MWST-Abrechnung — zuerst die Abrechnung ' . VatReturnLayout::periodLabel($latest->period())
                    . ' aufheben, dann diese (' . VatReturnLayout::periodLabel($return->period()) . ').';
            }
        }
        $entry    = $return?->getSettlementEntry();
        $snapshot = ['lines' => []];
        if ($refusal === null && $entry !== null) {
            foreach ($entry->getLines() as $line) {
                // The reversal: every line on its other side.
                $snapshot['lines'][] = [
                    'account'      => $line->getAccount()->getNumber(),
                    'account_name' => $line->getAccount()->getName(),
                    'text'         => (string) $line->getText(),
                    'debit'        => $line->getCredit()->toDecimal(),
                    'credit'       => $line->getDebit()->toDecimal(),
                ];
            }
        }

        $response = $this->html([
            'return'       => $return,
            'refusal'      => $refusal,
            'periodLabel'  => $return === null ? '' : VatReturnLayout::periodLabel($return->period()),
            'entryRef'     => $entry === null ? '' : VatReturnLayout::entryRef($entry),
            'entry'        => $entry,
            'snapshot'     => $snapshot,
            'reasonLength' => VatReturn::REASON_LENGTH,
            'entityCsrf'   => $return === null ? '' : DI::getCsrfService()->generateEntityToken(self::VAT_RETURN_LIFT_TOKEN, (int) $return->getId()),
            'base'         => $this->vatReturnBase(),
            'fmt'          => static fn(?Money $m): string => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmLift', 'Backend/VatReturnController', VatReturnLayout::NS);

        return $response;
    }

    /**
     * «Aufheben» (Fetch POST from the window): id, the version the window showed, the reason → the
     * service's lift. The reason's own refusals mark the field; every refusal keeps the window and
     * shows its sentence; success → the period's page (open again).
     */
    #[Fetch, HttpMethod('POST')]
    protected function liftAction(): FetchResponse
    {
        $body    = DI::getRequest()->getJsonBody();
        $id      = (int) ($body['id'] ?? 0);
        $version = (int) ($body['version'] ?? 0);
        if ($id <= 0 || !DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), self::VAT_RETURN_LIFT_TOKEN, $id)) {
            return $this->vatReturnWindowRefusal('lift', 'Das Fenster ist abgelaufen — schliessen und «Aufheben …» nochmals öffnen.');
        }
        try {
            $return = $this->vatReturnPoster()->lift($id, $version, is_string($body['reason'] ?? null) ? $body['reason'] : '');
        } catch (VatReturnRefusedException $e) {
            // `corrected-by-entry` names the correction entry to change first (`journal-entry:{year}/{nr}`) — its link in the window.
            $response = $this->vatReturnWindowRefusal('lift', $e->getMessage(), [], self::vatReturnJournalLink($e->refusal->fix));

            return in_array($e->refusal->reason, [VatReturnRefusal::NO_REASON, VatReturnRefusal::REASON_TOO_LONG], true)
                ? $response->setField('reason', false, $e->getMessage())
                : $response;
        }
        $reversal = $return->getReversalEntry();
        $this->messageService->pushFlashAfterRedirect('success', 'MWST-Abrechnung ' . VatReturnLayout::periodLabel($return->period()) . ' aufgehoben'
            . ($reversal === null ? '' : ' — Storno ' . VatReturnLayout::entryRef($reversal)) . '; die Periode ist wieder offen.');

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->setRedirect($this->vatReturnUrl('form', $return->period()));
    }

    // ── the frame of a return page ───────────────────────────────────────

    private function vatReturnView(string $tab): HtmlResponse|RedirectResponse
    {
        $resolved = $this->vatReturnPeriod();
        if ($resolved === null) {
            return $this->redirect($this->vatReturnBase() . '/list');
        }
        [$period, $refusal] = $resolved;
        $service  = $this->vatReturns();
        $preview  = null;
        $own      = [];
        $postable = false;
        if ($period !== null && $refusal === null) {
            try {
                $preview = $service->preview($period);
                $posted  = $preview->posted;
                $own     = match ($tab) {
                    'form'           => $posted !== null ? $this->vatReturnStoredContext($posted) : $this->vatReturnFormContext($period, $preview, $postable),
                    // The correction entries dated in the period are no part of the tree — named apart under it (board S3-2).
                    'origin'         => ['entriesUrl' => $this->vatReturnEntriesUrl($period), 'corrections' => $this->vatReturnCorrections($period), 'journalBase' => VatReturnLayout::JOURNAL_BASE],
                    'reconciliation' => [
                        'reconciliation' => $service->reconciliation($period),
                        'accountUrl'     => $this->vatReturnAccountUrl($period),
                        // The entries that do not fit the lock of the VAT accounts, listed under their account (owner S4-5).
                        'violations'     => (new VatAccountViolations($this->em()))->forPeriod($period, $preview->currency),
                        'journalBase'    => VatReturnLayout::JOURNAL_BASE,
                    ],
                };
                if ($posted !== null && $tab !== 'form') {
                    $own += $this->vatReturnStoredTools($posted);
                    // The recomputed view no longer matches the voucher → one hint line on top (FIN-VAT-POSTED-VIEWS-001).
                    $own['changedSince'] = $service->changedSincePosting($posted, $tab) ? $posted->getPostedAt() : null;
                }
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
        // The action cell: «MWST buchen …» while the open period's plan has no blocking finding (owner decisions 4, 5).
        if ($postable) {
            $this->layoutManager->addPartials('postAct', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc1');
        }

        return $response;
    }

    /**
     * What every return page shows: the period, its label, the preview (null when refused or a
     * stored revision), the refusal and its link, the tab links, the PDF, the fiscal-year dropdown.
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
            // Not `tabs`: the backend shell prints a context value of that name as its own tab slot.
            'vatReturnTabs' => VatReturnLayout::TABS,
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

    /** The header slots of a return page: the crumb, the tabs (not on a refusal), the year dropdown. */
    private function vatReturnSlots(bool $tabs): void
    {
        $this->layoutManager->addPartials('crumb', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc3');
        if ($tabs) {
            $this->layoutManager->addPartials('tabs', 'Backend/VatReturnController', VatReturnLayout::NS, 'hc2');
        }
        $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', VatReturnLayout::NS, 'railSelect');
    }

    /**
     * Tab «Formular» of an OPEN period: the open points of the PLAN (the posting preconditions
     * first — `not-elapsed`, `not-next`, … —, then the return's findings and `settlement-unbalanced`)
     * with the link each reference names; the posted tax beside the total owed; whether «MWST buchen
     * …» belongs into the action cell ($postable — no blocking finding).
     *
     * @return array<string, mixed>
     */
    private function vatReturnFormContext(VatPeriod $period, VatReturnPreview $preview, bool &$postable): array
    {
        try {
            $findings = $this->vatReturnPoster()->plan($period)->findings;
        } catch (VatReturnRefusedException $e) {
            // `period-overlaps` and the like — the page stays, the reason is its first open point.
            $findings = array_merge([new VatReturnFinding(true, $e->refusal->reason, $e->refusal->message, $e->refusal->fix)], $this->vatReturns()->findings($period));
        }
        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = ['finding' => $finding, 'link' => $this->vatReturnFindingLink($finding, $period, $preview)];
        }
        $postable = array_filter($findings, static fn(VatReturnFinding $f): bool => $f->blocking) === [];

        return [
            'findingRows' => $rows,
            'fields'      => $preview->fields,
            'postedTax'   => $preview->form->postedTax,
            'postedOwed'  => $preview->postedTaxOwed(),
            'stored'      => null,
            'corrections' => $this->vatReturnCorrections($period),
            'journalBase' => VatReturnLayout::JOURNAL_BASE,
        ];
    }

    /**
     * The block «Korrekturen früherer Perioden» of an OPEN period, computed from the journal
     * ({@see VatReturnService::correctionBlocks()}). A refusal of the block (an unknown or unmapped
     * code of a correction entry) is the return's own open point already — the page shows no block
     * then rather than failing.
     *
     * @return list<\Z77\Module\Financial\VatReturn\VatReturnCorrectionBlock>
     */
    private function vatReturnCorrections(VatPeriod $period): array
    {
        try {
            return $this->vatReturns()->correctionBlocks($period);
        } catch (VatReturnRefusedException) {
            return [];
        }
    }

    /**
     * Tab «Formular» of a posted return or a lifted revision: the STORED figures, the head facts
     * (posted by / at, the entry, a lift), the tools «Beleg» and — admin, latest — «Aufheben …».
     *
     * @return array<string, mixed>
     */
    private function vatReturnStoredContext(VatReturn $return): array
    {
        $fields     = $this->vatReturns()->declaredFields($return);
        $inputTotal = null;
        foreach ($fields as $field) {
            $inputTotal = $field->kind === FieldKind::InputTotal ? $field->rounded : $inputTotal;
        }

        return [
            'findingRows' => [],
            'fields'      => $fields,
            'postedTax'   => $return->getPostedTax(),
            'postedOwed'  => $inputTotal === null ? $return->getPostedTax() : $return->getPostedTax()->add($inputTotal),
            // The block as it was posted — the stored rows, never recomputed (S2 decision 6).
            'corrections' => $this->vatReturns()->declaredCorrectionBlocks($return),
            'journalBase' => VatReturnLayout::JOURNAL_BASE,
        ] + $this->vatReturnStoredTools($return);
    }

    /**
     * The facts and tools of a stored return: who / when, the entry and the reversal by number, the
     * voucher's address, the lift window for who may lift the latest return.
     *
     * @return array<string, mixed>
     */
    private function vatReturnStoredTools(VatReturn $return): array
    {
        $latest = $this->vatReturns()->settlement()->latest();
        // The block's line of the protocol row «MWST gebucht» («Korrekturen früherer Perioden 40.46 (3. Quartal 2026)») —
        // written once at posting (VatReturnPostingService::correctionsSummary()), read here, never recomputed.
        $posting = $this->em()->getRepository(VatReturnLog::class)->findOneBy(['vatReturnId' => $return->getId(), 'action' => VatReturnLogAction::Post->value]);

        return [
            'stored'      => $return,
            'storedFacts' => [
                'method'      => VatReturnLayout::storedMethodLabel($return),
                'entry'       => $return->getSettlementEntry() === null ? '' : VatReturnLayout::entryRef($return->getSettlementEntry()),
                'reversal'    => $return->getReversalEntry() === null ? '' : VatReturnLayout::entryRef($return->getReversalEntry()),
                'corrections' => $posting instanceof VatReturnLog ? (string) ($posting->getDetail()['corrections'] ?? '') : '',
            ],
            'voucherUrl'  => $this->vatReturnVoucherUrl($return),
            'liftUrl'     => $return->isLive() && $latest?->getId() === $return->getId() && $this->vatReturnCanReach('lift')
                ? $this->vatReturnBase() . '/confirm-lift?' . http_build_query(['id' => $return->getId()])
                : null,
        ];
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

    /**
     * The settlement entry of a plan as an entry snapshot (`Backend/partials/entrySnapshot`, the
     * one rendering of entry lines): account, its name, the line text, debit, credit.
     *
     * @param array<string, string> $names account number → name
     * @return array{lines: list<array<string, string>>}
     */
    private static function vatReturnPlanSnapshot(VatSettlementPlan $plan, array $names): array
    {
        $lines = [];
        foreach ($plan->settlement?->lines ?? [] as $line) {
            $lines[] = [
                'account'      => $line->account,
                'account_name' => $names[$line->account] ?? '',
                'text'         => (string) $line->text,
                'debit'        => $line->debit->toDecimal(),
                'credit'       => $line->credit->toDecimal(),
            ];
        }

        return ['lines' => $lines];
    }

    /**
     * account number → name of every account the plan's settlement lines name.
     *
     * @return array<string, string>
     */
    private function vatReturnPlanAccounts(VatSettlementPlan $plan): array
    {
        $numbers = array_values(array_unique(array_filter(array_column($plan->lines, 'account'), static fn(string $n): bool => $n !== '')));
        $names   = [];
        foreach ($numbers === [] ? [] : $this->em()->getRepository(Account::class)->findBy(['number' => $numbers]) as $account) {
            $names[$account->getNumber()] = $account->getName();
        }

        return $names;
    }

    /**
     * A refusal of a window's POST: the window stays, its alert slot (`[data-vat-{$slot}-refusal]`)
     * shows the sentence and — for `blocked` / `warnings-*` — the findings it refers to. Escaped by
     * the template; the replacement keeps the slot's marker, so a second refusal replaces the first.
     *
     * @param 'post'|'lift'          $slot
     * @param list<VatReturnFinding> $findings
     * @param array{url: string, label: string}|null $link the place the refusal names, when this module can name it
     */
    private function vatReturnWindowRefusal(string $slot, string $message, array $findings = [], ?array $link = null): FetchResponse
    {
        $html = (new TemplateRenderer(VatReturnLayout::NS))->partial('Backend/VatReturnController/windowRefusal', ['slot' => $slot, 'message' => $message, 'findings' => $findings, 'link' => $link]);

        return $this->fetch()
            ->setStatus('error')
            ->addCommand('replace-html', ['target' => '[data-vat-' . $slot . '-refusal]', 'html' => $html]);
    }

    // ── parameters, period, links ────────────────────────────────────────

    /** A GET parameter as a trimmed string — '' when absent or not a scalar. */
    private function vatReturnParameter(string $name): string
    {
        $value = DI::getRequest()->getGetParameter($name);

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /** A `YYYY-MM-DD` value as a day, or null when it is not a real calendar date. */
    private static function vatReturnDay(mixed $value): ?\DateTimeImmutable
    {
        $value = is_string($value) ? trim($value) : '';
        $day   = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    /** The stored return `?id=` names, or null. */
    private function vatReturnById(): ?VatReturn
    {
        $id = (int) $this->vatReturnParameter('id');

        return $id > 0 ? $this->vatReturnRows()->find($id) : null;
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
        $from = self::vatReturnDay($this->vatReturnParameter('from'));
        if ($from === null) {
            return null;
        }
        try {
            return [$service->periodOn($from), null];
        } catch (VatReturnRefusedException $e) {
            return [null, $e->refusal];
        }
    }

    /** `/backend/finance/vat-return/{action}?from=…` of a period, plus $params. */
    private function vatReturnUrl(string $action, VatPeriod $period, array $params = []): string
    {
        return $this->vatReturnBase() . '/' . $action . '?' . http_build_query(['from' => $period->from->format('Y-m-d')] + $params);
    }

    /** The archived voucher of a stored return. */
    private function vatReturnVoucherUrl(VatReturn $return): string
    {
        return $this->vatReturnBase() . '/voucher?' . http_build_query(['id' => $return->getId()]);
    }

    /** The read-only page of a stored (lifted) revision. */
    private function vatReturnRevisionUrl(VatReturn $return): string
    {
        return $this->vatReturnBase() . '/revision?' . http_build_query(['id' => $return->getId()]);
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
     * The link of one open point: a gap on a VAT account (`vat-account-remainder`) opens the tab
     * «Abstimmung» at that account, where the entries that do not fit are listed one by one (owner
     * S4-5) — the anchor `vat-account-{number}` of `reconciliation.tpl.php`; every other finding
     * the place its reference names.
     *
     * @return array{url: string, label: string}|null
     */
    private function vatReturnFindingLink(VatReturnFinding $finding, VatPeriod $period, VatReturnPreview $preview): ?array
    {
        [$kind, $key] = array_pad(explode(':', $finding->reference, 2), 2, '');
        if ($finding->code === VatReturnFinding::VAT_ACCOUNT_REMAINDER && $kind === 'account' && $key !== '') {
            return ['url' => $this->vatReturnUrl('reconciliation', $period) . '#vat-account-' . rawurlencode($key), 'label' => 'Abstimmung'];
        }

        return $this->vatReturnReferenceLink($finding->reference, $period, $preview);
    }

    /**
     * The place a finding names ({@see VatReturnFinding::$reference}) as a link, null when there is
     * none this module can name — another module's open work (the Faktura-Journal, the
     * Belegjournal, the Kreditorenjournal) says in its message where to go.
     *
     * @return array{url: string, label: string}|null
     */
    private function vatReturnReferenceLink(string $reference, VatPeriod $period, VatReturnPreview $preview): ?array
    {
        [$kind, $key] = array_pad(explode(':', $reference, 2), 2, '');
        $other = $kind === 'vat-return' ? self::vatReturnDay($key) : null;

        return match (true) {
            $kind === 'journal-entry'               => self::vatReturnJournalLink($reference),
            $kind === 'account' && $key !== ''      => ['url' => ($this->vatReturnAccountUrl($period))($key), 'label' => 'Kontoblatt ' . $key],
            $kind === 'field' && $key !== ''        => $preview->breakdownOf($key) !== null
                ? ['url' => ($this->vatReturnEntriesUrl($period))($key), 'label' => 'Buchungen Ziffer ' . $key]
                : ['url' => $this->vatReturnUrl('form', $period) . '#ziffer-' . rawurlencode($key), 'label' => 'Ziffer ' . $key],
            $kind === 'reconciliation'              => ['url' => $this->vatReturnUrl('reconciliation', $period), 'label' => 'Abstimmung'],
            $kind === 'mandator'                    => ['url' => VatReturnLayout::MANDATOR_URL, 'label' => $key === 'accounts' ? 'Mandant — Reiter «Konten»' : ($key === 'vat' ? 'Mandant — Reiter «UID und MWST»' : 'Mandant')],
            // `vat-return:{from}` — the period to settle first (`not-next`).
            $other !== null                         => ['url' => $this->vatReturnBase() . '/form?' . http_build_query(['from' => $other->format('Y-m-d')]), 'label' => 'Zu dieser Abrechnung'],
            $kind === 'fiscal-years' || $kind === 'fiscal-year' => ['url' => VatReturnLayout::FISCAL_YEAR_URL, 'label' => 'Geschäftsjahre'],
            // `bank-movement:{code}` — Close\BankReconciliationCheck's own reference (a bank account's code).
            $kind === 'bank-movement'               => ['url' => BankLayout::MOVEMENT_BASE . '/list' . ($key !== '' ? '?' . http_build_query(['account' => $key]) : ''), 'label' => 'Bankkonto'],
            default                                 => null,
        };
    }

    /**
     * A reference `journal-entry:{year}/{number}` (a correction entry the lift refusal
     * `corrected-by-entry` names) as the link to that journal entry — the journal resolves `?ref=`;
     * null for any other reference.
     *
     * @return array{url: string, label: string}|null
     */
    private static function vatReturnJournalLink(string $reference): ?array
    {
        if (preg_match('#^journal-entry:([0-9A-Za-z-]{1,16}/[1-9]\d{0,8})$#', $reference, $m) !== 1) {
            return null;
        }

        return ['url' => VatReturnLayout::JOURNAL_BASE . '/detail?' . http_build_query(['ref' => $m[1]]), 'label' => 'Buchung ' . $m[1]];
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
