<?php

namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\BytesResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Financial\Bank\BankAccountService,
    Z77\Module\Financial\Bank\BankImportResult,
    Z77\Module\Financial\Bank\BankLinkService,
    Z77\Module\Financial\Bank\BankMovementStates,
    Z77\Module\Financial\Bank\BankPostingRefusedException,
    Z77\Module\Financial\Bank\BankPostingService,
    Z77\Module\Financial\Bank\BankReconciliation,
    Z77\Module\Financial\Bank\BankRefusedException,
    Z77\Module\Financial\Bank\BankStatementArchive,
    Z77\Module\Financial\Bank\BankStatementService,
    Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Entities\BankAccount,
    Z77\Module\Financial\Entities\BankMovement,
    Z77\Module\Financial\Entities\BankPosting,
    Z77\Module\Financial\Entities\BankStatement,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Repositories\BankMovementRepository,
    Z77\Module\Financial\Services\LedgerService,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Payment\Camt\CamtArchive,
    Z77\Shared\Payment\Camt\CamtFormatException,
    Z77\Shared\Upload\UploadPolicy
;

/**
 * Finanzen › «Bankkonto» (ADR-060 decisions 16–18; boards «Kontoauszug einlesen» and «Liste
 * Bewegungen», slim form, owner-approved 2026-11-17): the camt.053 upload and the ONE list
 * «Bewegungen» of a bank account. Since ADR-061 stage C1 a movement shows what it is LINKED to
 * (column «Zuordnung», e.g. «Rechnung 4711 · Muster AG» — the owner's label of `BankLink`); a
 * link of another module is set and released by that module (creditor: «Zahlungen suchen …»),
 * never here. Since stage C2 every movement has a STATE — «zu prüfen», «geschäftlich», «privat»
 * (derived, `BankMovementStates`) — and the office decides it here.
 * Mounted by a thin host controller in the backend (ADR-018 pattern): URL
 * `/backend/finance/bank-movement/…`, role `admin` (BANK-ACL-001).
 *
 *   - `list` — a STANDARD LIST ({@see BankMovementListing}): Datum, Text, Betrag, Original,
 *     Stand, Zuordnung, Saldo; searched by date, text and amount in the database; the header
 *     says «eingelesen bis … · Saldo …». Four views in the toolbar («Alle» · «Zu prüfen» ·
 *     «Geschäftlich» · «Privat», `?state=`); above the list the selection's «Privat …» and,
 *     while a document made a movement of a private pattern business, the open question of
 *     ADR-061 decision 8. The bank account is chosen at the top of the
 *     rail (only accounts with «Kontoauszug einlesen»; `?account=<code>`). Action cell: the
 *     upload — the shared upload component in its `cell` shape, the work area is the drop target;
 *   - `upload` — POST, one file per request (`file`), XML or ZIP: answers with ONE short sentence
 *     ({@see BankImportResult::message()}) or the service's refusal sentence — per file in fetch
 *     mode, as a flash on a page. A file of accounts that are no bank account here is SKIPPED and
 *     summed up in one neutral line («4 Konten übersprungen (nicht als Bankkonto angelegt): …»);
 *   - `upload-info` — the WINDOW a click on the action cell opens before the file dialog: per bank
 *     account «eingelesen bis» and «Laden ab» (the last stored day — a file must overlap it), and
 *     «Dateien wählen …»;
 *   - `edit` — the WINDOW of one movement (ADR-047), what it offers follows the state: «Geschäftlich
 *     buchen …» (contra account or «Privat» / «Übertrag eigenes Konto», optional tax code, text,
 *     the file; `BankPostingService::post()`), «Privat» (the pattern, or this movement only),
 *     back to «zu prüfen», «Muster aufheben», the answer to decision 8, «Buchung zurücknehmen …»
 *     (`BankPostingService::takeBack()`). One POST, the hidden `op` says which;
 *   - `confirm-private` — «Privat …» for the ticked rows: a window that names them, its POST marks
 *     them (`BankMovementStates::markPrivateMany()`);
 *   - `voucher` — the archived file of a direct posting; `statement` — the archived camt.053 a
 *     movement came with (the voucher of a posting without a tax code);
 *   - `reconcile` — the WINDOW «Abgleich …» of a BANK-DRIVEN bank account (ADR-061 stage C4, the
 *     toolbar tool): the anchor «Bank X · Fibu Y · Differenz Z» with «Differenz bestätigen», the
 *     check per period of a fiscal year, the postings without a movement, the transfer account,
 *     «Vorsteuer ohne Beleg» — every figure is `BankReconciliation`'s.
 *
 * Since stage C4 the states apply only to the bank-driven time of an account («Kontoauszug
 * einlesen» on, from «Abgleich ab»): a movement before it, or of an account without the date, is
 * INFORMATION ONLY — no state, no checkbox, a window that offers nothing to decide.
 *
 * Every rule is the services' — the screen only calls them and shows their German sentence.
 *
 * The screen carries labels and answers only; every explanation is in the help
 * (backend-screen.md §5). Bank text is foreign input: every value is escaped by the templates.
 * No JavaScript of its own (Rule 7). The using class MUST provide (via its host base): `html()`,
 * `fetch()`, `fetchError()`, `json()`, `bytes()`, `redirect()`, `em()`, `$layoutManager`,
 * `$messageService`, `$help`.
 */
trait BankMovementControllerTrait
{
    /** Upload size cap — a year's camt.053 of an SME is below 2 MB. */
    private const BANK_STATEMENT_FILE_LIMIT = 5 * 1024 * 1024;

    /** URL root of THIS mount. */
    protected function bankMovementBase(): string
    {
        return BankLayout::MOVEMENT_BASE;
    }

    /** The import as the logged-in user — a harness host overrides it with a named actor. */
    private function bankStatements(): BankStatementService
    {
        return new BankStatementService($this->em());
    }

    private function bankStatementUploadPolicy(): UploadPolicy
    {
        return new UploadPolicy(
            endpoint:   $this->bankMovementBase() . '/upload',
            field:      'file',
            multiple:   true,
            accept:     ['.xml', '.zip'],
            maxBytes:   self::BANK_STATEMENT_FILE_LIMIT,
            onConflict: UploadPolicy::CONFLICT_ERROR,
            label:      'camt.053 Upload',
            hint:       'XML oder ZIP · bis 5 MB · mehrere',
            // A click on the cell opens «what to download» first (owner 2026-11-18); a drop is read at once.
            before:     $this->bankMovementBase() . '/upload-info',
        );
    }

    /** @return list<BankAccount> the active bank accounts with «Kontoauszug einlesen», by label */
    private function bankImportAccounts(): array
    {
        return array_values(array_filter(
            (new BankAccountService($this->em()))->all(),
            static fn(BankAccount $a) => $a->isActive() && $a->isStatementImport()
        ));
    }

    protected function listAction(): HtmlResponse
    {
        $request  = DI::getRequest();
        $fetch    = ListDefinition::isFetch($request);
        $accounts = $this->bankImportAccounts();
        $context  = $this->bankMovementContext($accounts, $request);
        if (!$fetch) {
            $this->help->attach('Backend/BankMovementController/list.help', BankLayout::NS, ['voucherFrom' => $this->bankVoucherFrom()], 'Hilfe: Bankkonto', toolbar: true);
        }
        $response = $this->html($context + [
            'uploadPolicy' => $this->bankStatementUploadPolicy(),
            'accountsBase' => BankLayout::ACCOUNT_BASE,
        ]);
        if (!$fetch && $accounts !== []) {
            // The action cell IS the upload; the bank account is a choice for the whole entry → top of the rail.
            $this->layoutManager->addPartials('act', 'Backend/BankMovementController', BankLayout::NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/BankMovementController', BankLayout::NS, 'hc2');
            if (count($accounts) > 1) {
                $this->layoutManager->addPartials('bankAccountSelect', 'Backend/partials', BankLayout::NS, 'railSelect');
            }
        }

        return $response;
    }

    /**
     * What the list shows for the selected bank account — also rendered alone after an upload.
     *
     * @param list<BankAccount> $accounts
     * @return array<string, mixed>
     */
    private function bankMovementContext(array $accounts, object $request, ?BankAccount $selected = null): array
    {
        $definition = BankMovementListing::definition(LedgerService::baseCurrency());
        $state      = $definition->readRequest($request);
        $base       = $this->bankMovementBase();
        $account    = $selected;
        foreach ($accounts as $candidate) {
            if ($account === null && $candidate->getCode() === $state->extra('account')) {
                $account = $candidate;
            }
        }
        $account ??= $accounts[0] ?? null;

        $latest = null;
        $first  = null;
        $total  = 0;
        $rows   = [];
        $links  = [];
        $states = [];
        $counts = array_fill_keys(BankMovementRepository::STATES, 0);
        $questions = [];
        $view   = in_array($state->extra('state'), BankMovementRepository::STATES, true) ? $state->extra('state') : null;
        $paging = $state->paging(0);
        if ($account !== null) {
            $id      = (int) $account->getId();
            $latest  = $this->em()->getRepository(BankStatement::class)->latestOf($id);
            $first   = $this->em()->getRepository(BankStatement::class)->firstOf($id);
            /** @var BankMovementRepository $movements */
            $movements = $this->em()->getRepository(BankMovement::class);
            $date      = $state->parsed('f_date');
            $args      = [$id, $date[0] ?? null, $date[1] ?? null, $state->parsed('f_text'), $state->parsed('f_amount')];
            $total     = $movements->countSearch(...[...$args, $view]);
            $paging    = $state->paging($total);
            $rows      = $total === 0 ? [] : $movements->search(...[...$args, $state->sort, $state->descending, $paging->offset(), $paging->pageSize, $view]);
            $pageIds   = array_map(static fn(BankMovement $m) => (int) $m->getId(), $rows);
            // What each movement of the page is linked to (ADR-061 stage C1) and its state (C2) — one read each.
            $links     = (new BankLinkService($this->em()))->ofMovements($pageIds);
            $states    = $movements->statesOf($pageIds);
            $counts    = $movements->stateCounts($id);
            $questions = (new BankMovementStates($this->em()))->openQuestions($id);
        }

        return [
            'accounts'    => $accounts,
            'account'     => $account,
            'latest'      => $latest,
            'first'       => $first,
            'movements'   => $rows,
            'links'       => $links,
            'states'      => $states,
            'counts'      => $counts,
            'questions'   => $questions,
            // Bank-driven and the anchor not confirmed yet (ADR-061 decision 22): the list says so once, above its rows.
            'anchor'      => $account !== null && $account->isBankDriven() ? $this->bankReconciliation()->anchor($account) : null,
            'view'        => $view,
            'definition'  => $definition,
            'state'       => $state,
            'paging'      => $paging,
            'actionBase'  => $base,
            'accountHref' => static fn(string $code): string => $base . '/list?' . http_build_query(['account' => $code]),
        ];
    }

    /**
     * The window before the file dialog (owner 2026-11-18): every bank account that takes
     * statements with «eingelesen bis» and the day to download from. Read-only.
     */
    protected function uploadInfoAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $statements = $this->em()->getRepository(BankStatement::class);
        $rows       = [];
        foreach ($this->bankImportAccounts() as $account) {
            $rows[] = ['account' => $account, 'latest' => $statements->latestOf((int) $account->getId())];
        }
        $this->help->attach('Backend/BankMovementController/list.help', BankLayout::NS, ['voucherFrom' => $this->bankVoucherFrom()], 'Hilfe: Bankkonto');
        $response = $this->html([
            'window'       => ListDefinition::isFetch($request),
            'rows'         => $rows,
            'uploadPolicy' => $this->bankStatementUploadPolicy(),
            'actionBase'   => $this->bankMovementBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('uploadInfo', 'Backend/BankMovementController', BankLayout::NS);

        return $response;
    }

    /**
     * The upload (POST, ONE file per request in `file`) — two answers for one body: in fetch mode
     * (the upload component, one request per file) the per-file envelope `{status, name, message,
     * commands}`; in page mode (no JavaScript) the flash and the list. The gate is
     * {@see UploadPolicy::check()} in both cases. A ZIP is unpacked ({@see CamtArchive}) and every
     * XML inside read on its own.
     */
    #[Csrf]
    protected function uploadAction(): RedirectResponse|JsonResponse
    {
        $request = DI::getRequest();
        $fetch   = $request->getMode() === RequestMode::Fetch;
        if (!$request->isPost()) {
            return $fetch
                ? $this->json(['status' => 'error', 'name' => '', 'message' => 'POST erwartet'], 405)
                : $this->redirect($this->bankMovementBase() . '/list', 303);
        }
        $file = $request->getUploadedFile('file');
        if ($file === null) {
            return $this->bankUploadAnswer($fetch, '', [], ['Keine Datei empfangen.'], null);
        }
        $name    = $file->originalName;
        $refusal = $this->bankStatementUploadPolicy()->check($file);
        if ($refusal !== null) {
            return $this->bankUploadAnswer($fetch, $name, [], [$refusal], null);
        }

        $files = [['name' => $name, 'xml' => (string) $file->bytes()]];
        $isZip = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
        if ($isZip) {
            try {
                $files = CamtArchive::xmlFiles($files[0]['xml']);
            } catch (CamtFormatException $e) {
                return $this->bankUploadAnswer($fetch, $name, [], [$e->getMessage()], null);
            }
        }

        $done    = [];
        $refused = [];
        $skipped = [];
        $account = null;
        foreach ($files as $entry) {
            $prefix = $isZip ? $entry['name'] . ': ' : '';
            try {
                foreach ($this->bankStatements()->import($entry['xml'], $entry['name']) as $result) {
                    $done[]  = $prefix . $result->message();
                    $account = $result->bankAccount;
                }
            } catch (BankRefusedException $e) {
                if ($e->reason === BankRefusedException::NOT_BANK_ACCOUNT) {
                    // Not an account of this installation (a bank's ZIP carries every account of the contract): skipped, named once below.
                    array_push($skipped, ...$e->subjects);
                    continue;
                }
                $refused[] = $prefix . $e->getMessage();
            }
        }
        $skipped = array_values(array_unique($skipped));
        // Neutral and LAST: a forgotten business account must still be noticed, but it is no error.
        $note = $skipped === [] ? '' : (count($skipped) === 1 ? '1 Konto' : count($skipped) . ' Konten') . ' übersprungen (nicht als Bankkonto angelegt): ' . implode(', ', $skipped);

        return $this->bankUploadAnswer($fetch, $name, $done, $refused, $account, $note);
    }

    /**
     * One answer for one uploaded file: ok when nothing was refused, else an error naming every
     * refusal (what was read stays read). In fetch mode the list of the account just read is
     * replaced in place, so the rows of the upload stay readable.
     *
     * @param list<string> $done
     * @param list<string> $refused
     * @param string       $note    a neutral last line that is neither read nor refused (the accounts skipped)
     */
    private function bankUploadAnswer(bool $fetch, string $name, array $done, array $refused, ?BankAccount $account, string $note = ''): RedirectResponse|JsonResponse
    {
        $message = implode(' · ', array_filter([...$done, ...$refused, $note], static fn(string $part) => $part !== ''));
        if ($fetch) {
            $answer = ['status' => $refused === [] ? 'ok' : 'error', 'name' => $name, 'message' => $message];
            if ($account !== null) {
                $accounts           = $this->bankImportAccounts();
                $answer['commands'] = [[
                    'action' => 'replace-html',
                    'target' => '[data-bank-movements]',
                    'html'   => (new TemplateRenderer(BankLayout::NS))->partial(
                        'Backend/BankMovementController/_list',
                        // The list as it opens: first page, no search — of the account just read.
                        $this->bankMovementContext($accounts, new class($account->getCode()) {
                            public function __construct(private string $code) {}
                            public function getGetParameter(string $p): mixed { return $p === 'account' ? $this->code : null; }
                        }, $account)
                    ),
                ]];
            }

            return $this->json($answer);
        }
        $this->messageService->pushFlashAfterRedirect($refused !== [] ? 'error' : ($done === [] && $note !== '' ? 'info' : 'success'), ($name !== '' ? $name . ': ' : '') . $message);

        return $this->redirect($this->bankMovementBase() . '/list' . ($account !== null ? '?' . http_build_query(['account' => $account->getCode()]) : ''), 303);
    }

    // ── the movement window (ADR-061 stage C2) ───────────────────────────

    /** The services as the logged-in user — a harness host overrides them with a named actor. */
    private function bankMovementStates(): BankMovementStates
    {
        return new BankMovementStates($this->em());
    }

    private function bankPostings(): BankPostingService
    {
        return new BankPostingService($this->em());
    }

    /** The attachment threshold for the help texts — null when the config does not carry it (the posting then says so). */
    private function bankVoucherFrom(): ?\Z77\Shared\Money\Money
    {
        try {
            return BankPostingService::voucherFromGross();
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * The voucher of «Geschäftlich buchen …» — one file that travels WITH the booking form
     * (`partials/uploadField`, owner 2026-11-19); the server's own check stays in
     * `BankPostingService` (sniffed type, size) and in {@see editAction()}.
     */
    private function bankVoucherPolicy(): UploadPolicy
    {
        return new UploadPolicy(
            endpoint: $this->bankMovementBase() . '/edit',
            field:    'voucher',
            multiple: false,
            accept:   ['.pdf', '.jpg', '.jpeg', '.png'],
            maxBytes: BankPostingService::VOUCHER_MAX_BYTES,
            onConflict: UploadPolicy::CONFLICT_ERROR,
            shape:    'field',
            hint:     'PDF, JPG, PNG · bis 10 MB',
        );
    }

    private function bankMovementOf(): ?BankMovement
    {
        $id       = (int) DI::getRequest()->getGetParameter('id');
        $movement = $id > 0 ? $this->em()->getRepository(BankMovement::class)->find($id) : null;

        return $movement instanceof BankMovement ? $movement : null;
    }

    /**
     * The window of ONE movement: GET renders what its state offers, POST runs the one thing the
     * hidden `op` names — `book`, `private`, `review`, `lift`, `answer`, `take-back`. Each is a
     * call into `BankPostingService` / `BankMovementStates`; a refusal that belongs to a field
     * re-renders the window with the sentence under it, any other is the error flash.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request  = DI::getRequest();
        $movement = $this->bankMovementOf();
        if ($movement === null) {
            return $this->bankMovementRefuse('Diese Bankbewegung gibt es nicht mehr — die Liste neu laden.');
        }
        if (!$request->isPost()) {
            return $this->bankMovementWindow($movement, null, []);
        }
        $post = $request->getPostParameters();
        $id   = (int) $movement->getId();
        $flip = match ((string) ($post['flip'] ?? '')) { 'yes' => true, 'no' => false, default => null };
        try {
            switch ((string) ($post['op'] ?? '')) {
                case 'book':
                    $file = $request->getUploadedFile('voucher');
                    if ($file !== null && (!$file->isOk() || $file->size < 1 || $file->size > BankPostingService::VOUCHER_MAX_BYTES)) {
                        // A file was chosen but did not arrive whole (or is too large): never book without it silently.
                        return $this->bankMovementWindow($movement, $post, ['voucher' => 'Der Beleg ist nicht angekommen oder zu gross (höchstens 10 MB) — nichts gebucht. Bitte die Datei nochmals wählen.']);
                    }
                    $voucher = $file !== null ? ['name' => $file->originalName, 'bytes' => (string) $file->bytes()] : null;
                    $posting = $this->bankPostings()->post($id, (string) ($post['kind'] ?? ''), (string) ($post['contra'] ?? ''), (string) ($post['tax_code'] ?? ''), (string) ($post['text'] ?? ''), $voucher, $flip);
                    $ref     = $posting->entryRef();

                    return $this->bankMovementDone('Gebucht: Buchung ' . $ref?->fiscalYear . '/' . $ref?->number . '.');
                case 'private':
                    $done = $this->bankMovementStates()->markPrivate($id, !empty($post['only_this']), !empty($post['near_amount']), !empty($post['near_day']));

                    return $this->bankMovementDone($done['pattern'] === null
                        ? 'Privat — nur diese Bewegung.'
                        : 'Privat: «' . $done['pattern']->getLabel() . '»' . ($done['others'] > 0 ? ' — damit ' . ($done['others'] === 1 ? 'ist 1 weitere Bewegung' : 'sind ' . $done['others'] . ' weitere Bewegungen') . ' privat.' : '.'));
                case 'review':
                    $this->bankMovementStates()->setReview($id);

                    return $this->bankMovementDone('Die Bewegung ist wieder «zu prüfen».');
                case 'lift':
                    $count = $this->bankMovementStates()->liftPattern($id);

                    return $this->bankMovementDone('Muster aufgehoben — ' . ($count === 1 ? '1 Bewegung ist' : $count . ' Bewegungen sind') . ' wieder «zu prüfen».');
                case 'answer':
                    if ($flip === null) {
                        return $this->bankMovementWindow($movement, $post, ['flip' => 'Bitte eine Antwort wählen: «' . BankMovementStates::ANSWER_YES . '» oder «' . BankMovementStates::ANSWER_NO . '».']);
                    }
                    $this->bankMovementStates()->answer($id, $flip);

                    return $this->bankMovementDone($flip ? 'Das Muster ist jetzt «gemischt» — seine Bewegungen sind «zu prüfen».' : 'Diese Bewegung ist eine Ausnahme — das Muster bleibt privat.');
                case 'take-back':
                    $reversal = $this->bankPostings()->takeBack($id);

                    return $this->bankMovementDone('Buchung zurückgenommen (Storno ' . $reversal->fiscalYear . '/' . $reversal->number . ') — die Bewegung ist wieder «zu prüfen».');
            }
        } catch (BankPostingRefusedException $e) {
            return $this->bankMovementWindow($movement, $post, [$e->field ?? '' => $e->getMessage()]);
        } catch (BankRefusedException $e) {
            if ($e->reason === BankRefusedException::PATTERN_QUESTION) {
                return $this->bankMovementWindow($movement, $post, ['flip' => 'Bitte eine Antwort wählen: «' . BankMovementStates::ANSWER_YES . '» oder «' . BankMovementStates::ANSWER_NO . '».']);
            }

            return $this->bankMovementRefuse($e->getMessage());
        }

        return $this->bankMovementRefuse('Unbekannte Aktion.');
    }

    /**
     * Renders the movement window.
     *
     * @param array<string, mixed>|null $posted what a refused POST carried (kept in the form)
     * @param array<string, string>     $errors field → German sentence; '' = the whole form
     */
    private function bankMovementWindow(BankMovement $movement, ?array $posted, array $errors): HtmlResponse
    {
        $request  = DI::getRequest();
        $em       = $this->em();
        $id       = (int) $movement->getId();
        $states   = $this->bankMovementStates();
        $postings = $this->bankPostings();
        $pattern  = $states->privatePatternOf($movement);
        $proposal = $postings->proposalFor($movement);
        $named    = [];
        foreach ([BankPostingService::KIND_PRIVATE, BankPostingService::KIND_TRANSFER] as $kind) {
            $number       = $postings->namedAccount($kind);
            $named[$kind] = $number === '' ? '' : ($em->getRepository(Account::class)->findOneBy(['number' => $number])?->label() ?? $number);
        }
        $form = [
            'kind'     => (string) ($posted['kind'] ?? $proposal['kind']),
            'contra'   => (string) ($posted['contra'] ?? $proposal['contra']),
            'tax_code' => (string) ($posted['tax_code'] ?? $proposal['taxCode']),
            'text'     => (string) ($posted['text'] ?? $proposal['text']),
            'flip'     => (string) ($posted['flip'] ?? ''),
        ];

        $this->help->attach('Backend/BankMovementController/edit.help', BankLayout::NS, ['voucherFrom' => $this->bankVoucherFrom()], 'Hilfe: Bankbewegung');
        $response = $this->html([
            'window'      => ListDefinition::isFetch($request),
            'origin'      => WindowOrigin::of($request),
            'movement'    => $movement,
            'state'       => $states->stateOf($id) ?? BankMovementRepository::STATE_NONE,
            'link'        => (new BankLinkService($em))->ofMovements([$id])[$id] ?? null,
            'posting'     => $postings->currentOf($id),
            'pattern'     => $pattern,
            'keyPattern'  => $states->patternOf($movement),
            // Decision 8 is asked only where the movement has a state — not for one that is information only (stage C4).
            'questionOpen' => $movement->getBankAccount()->drives($movement->getBookingDate()) && $states->isQuestionOpen($movement),
            'question'    => $pattern === null ? null : BankMovementStates::question($pattern, $states->othersOf($movement, $pattern)),
            'form'        => $form,
            'errors'      => $errors,
            'accounts'    => array_values(array_filter($em->getRepository(Account::class)->allInOrder(), static fn(Account $a) => $a->isPostable() && $a->isActive())),
            'taxCodes'    => $em->getRepository(TaxCode::class)->selectable($form['tax_code'] !== '' ? [$form['tax_code']] : []),
            'named'       => $named,
            'voucherPolicy' => $this->bankVoucherPolicy(),
            'actionBase'  => $this->bankMovementBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/BankMovementController', BankLayout::NS);

        return $response;
    }

    /**
     * «Privat …» for the rows ticked in the list (`mv[]`): GET names them in a window, its POST
     * marks them — each as the single «Privat» does (the pattern, where the movement has one).
     */
    #[Csrf]
    protected function confirmPrivateAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $raw     = $request->isPost() ? ($request->getPostParameters()['mv'] ?? []) : $request->getGetParameter('mv');
        $ids     = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []), static fn(int $id) => $id > 0)));
        if ($request->isPost() && $ids !== []) {
            $done  = $this->bankMovementStates()->markPrivateMany($ids);
            $flash = ($done['private'] === 1 ? '1 Bewegung' : $done['private'] . ' Bewegungen') . ' privat'
                . ($done['patterns'] !== [] ? ' · Muster: ' . implode(', ', $done['patterns']) : '')
                . ($done['refused'] > 0 ? ' · ' . $done['refused'] . ' geschäftlich — unverändert' : '') . '.'
                // A movement another module claims (debtor's collective credit) stays as it is — with that module's sentence.
                . ($done['claimed'] > 0 ? ' ' . $done['claimed'] . ' nicht privat gesetzt: ' . implode(' ', $done['claims']) : '');

            return $this->bankMovementDone($flash, $done['claimed'] > 0 ? 'error' : ($done['refused'] > 0 ? 'info' : 'success'));
        }
        /** @var BankMovementRepository $movements */
        $movements = $this->em()->getRepository(BankMovement::class);
        $rows      = $ids === [] ? [] : $movements->findBy(['id' => array_slice($ids, 0, BankMovementListing::LIMIT)]);
        $response  = $this->html([
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'movements'  => $rows,
            'states'     => $movements->statesOf(array_map(static fn(BankMovement $m) => (int) $m->getId(), $rows)),
            'actionBase' => $this->bankMovementBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmPrivate', 'Backend/BankMovementController', BankLayout::NS);

        return $response;
    }

    // ── «Abgleich …» (ADR-061 stage C4) ──────────────────────────────────

    /** The reconciliation as the logged-in user — a harness host overrides it with a named actor. */
    private function bankReconciliation(): BankReconciliation
    {
        return new BankReconciliation($this->em());
    }

    /**
     * The window «Abgleich …» of the bank account `?account=<code>` (bank-driven only), for the
     * fiscal year `?year=<code>` (default: the year a screen opens on). POST confirms the anchor
     * the window showed (`as_of`, `difference`) and renders the window again with the answer.
     */
    #[Csrf]
    protected function reconcileAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $account = (new BankAccountService($this->em()))->find((string) $request->getGetParameter('account'));
        if ($account === null || !$account->isBankDriven()) {
            return $this->bankMovementRefuse('Für dieses Bankkonto ist kein Abgleich eingeschaltet — «Kontoauszug einlesen» und «Abgleich ab» unter Stammdaten › Finanzen › Bankkonten setzen.');
        }
        $reconciliation = $this->bankReconciliation();
        $error          = '';
        if ($request->isPost()) {
            $post = $request->getPostParameters();
            $asOf = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($post['as_of'] ?? ''));
            try {
                if ($asOf === false) {
                    throw new BankRefusedException(BankRefusedException::ANCHOR, 'Nichts zu bestätigen — das Fenster neu öffnen.');
                }
                $reconciliation->confirm($account, $asOf, (string) ($post['difference'] ?? ''));
                $this->messageService->pushFlash('success', 'Differenz per ' . $asOf->format('d.m.Y') . ' bestätigt.');
            } catch (BankRefusedException $e) {
                $error = $e->getMessage();
            }
        }

        /** @var \Z77\Module\Financial\Repositories\FiscalYearRepository $yearRepo */
        $yearRepo = $this->em()->getRepository(FiscalYear::class);
        $base     = $account->getReconcileFrom();
        // The years the driven time reaches into, newest first.
        $years = array_values(array_filter(
            $yearRepo->allWithPeriods(),
            static fn(FiscalYear $y): bool => $y->getEndDate() !== null && $y->getEndDate()->format('Y-m-d') >= $base->format('Y-m-d')
        ));
        $year = null;
        foreach ($years as $candidate) {
            if ($candidate->getCode() === (string) $request->getGetParameter('year')) {
                $year = $candidate;
            }
        }
        $current = $yearRepo->currentOrLatest();
        foreach ($years as $candidate) {
            if ($year === null && $current !== null && $candidate->getCode() === $current->getCode()) {
                $year = $candidate;
            }
        }
        $year ??= $years[0] ?? null;

        $periods = [];
        $total   = null;
        if ($year !== null) {
            $total   = $reconciliation->check($account, $year->getStartDate(), $year->getEndDate());
            $horizon = max((new \DateTimeImmutable('today'))->format('Y-m-d'), (string) $total?->coveredTo?->format('Y-m-d'));
            foreach ($year->getPeriods() as $period) {
                if ($period->getStartDate()->format('Y-m-d') > $horizon) {
                    continue;   // a month that has not begun and is not read: nothing to say yet
                }
                $check = $reconciliation->check($account, $period->getStartDate(), $period->getEndDate());
                if ($check !== null) {
                    $periods[] = ['label' => $period->getStartDate()->format('m.Y'), 'check' => $check];
                }
            }
        }

        $this->help->attach('Backend/BankMovementController/reconcile.help', BankLayout::NS, [], 'Hilfe: Abgleich');
        $response = $this->html([
            'window'      => ListDefinition::isFetch($request),
            'origin'      => WindowOrigin::of($request),
            'account'     => $account,
            'anchor'      => $reconciliation->anchor($account, $year?->getStartDate()),
            'years'       => $years,
            'year'        => $year,
            'periods'     => $periods,
            'total'       => $total,
            'transfer'    => $total !== null ? $reconciliation->transferBalance($total->to) : null,
            'tax'         => $total !== null ? $reconciliation->taxWithoutVoucher($total->from, $total->to) : null,
            'error'       => $error,
            'actionBase'  => $this->bankMovementBase(),
            'journalBase' => '/backend/finance/journal',
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('reconcile', 'Backend/BankMovementController', BankLayout::NS);

        return $response;
    }

    /** The archived file of a direct posting (`?id=` the posting) — shown in the browser. */
    protected function voucherAction(): BytesResponse|FetchResponse|RedirectResponse
    {
        try {
            $voucher = $this->bankPostings()->voucher((int) DI::getRequest()->getGetParameter('id'));
        } catch (BankPostingRefusedException $e) {
            return $this->bankMovementRefuse($e->getMessage());
        }

        return $this->bytes($voucher['bytes'], $voucher['name'] !== '' ? $voucher['name'] : 'beleg', $voucher['type']);
    }

    /**
     * The archived camt.053 file a movement came with (`?id=` the movement), its checksum verified —
     * the voucher of a posting without a tax code (ADR-061 decision 16). A download.
     */
    protected function statementAction(): BytesResponse|FetchResponse|RedirectResponse
    {
        $movement = $this->bankMovementOf();
        if ($movement === null) {
            return $this->bankMovementRefuse("Diese Bankbewegung gibt es nicht mehr — die Liste neu laden.");
        }
        try {
            $bytes = (new BankStatementArchive())->bytes($movement->getStatement());
        } catch (BankRefusedException $e) {
            return $this->bankMovementRefuse($e->getMessage());
        }

        return $this->bytes($bytes, basename($movement->getStatement()->getArchivePath()), "application/xml", false);
    }

    /** Close the window and reload the list region it came from; without the script: flash + the list. */
    private function bankMovementDone(string $flash, string $type = 'success'): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect($type, $flash);

            return $this->redirect($this->bankMovementBase() . '/list', 303);
        }
        $this->messageService->pushFlash($type, $flash);
        $origin = WindowOrigin::of($request);

        return $this->fetch()->setStatus('success')->addCommand('close-window')->addCommand('refresh-region', [
            'name' => str_starts_with($origin, 'region:') ? substr($origin, strlen('region:')) : BankMovementListing::definition(LedgerService::baseCurrency())->region(),
        ]);
    }

    private function bankMovementRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->bankMovementBase() . '/list', 303);
    }
}
