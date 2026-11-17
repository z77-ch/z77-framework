<?php

namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Financial\Bank\BankAccountService,
    Z77\Module\Financial\Bank\BankImportResult,
    Z77\Module\Financial\Bank\BankLinkService,
    Z77\Module\Financial\Bank\BankRefusedException,
    Z77\Module\Financial\Bank\BankStatementService,
    Z77\Module\Financial\Entities\BankAccount,
    Z77\Module\Financial\Entities\BankMovement,
    Z77\Module\Financial\Entities\BankStatement,
    Z77\Module\Financial\Repositories\BankMovementRepository,
    Z77\Module\Financial\Services\LedgerService,
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
 * (column «Zuordnung», e.g. «Rechnung 4711 · Muster AG» — the owner's label of `BankLink`) and
 * the toolbar offers the view «Ohne Zuordnung» (`?link=free`). The link itself is set and
 * released by the owning module (creditor: «Zahlungen suchen …»), never here.
 * Mounted by a thin host controller in the backend (ADR-018 pattern): URL
 * `/backend/finance/bank-movement/…`, role `admin` (BANK-ACL-001).
 *
 *   - `list` — a STANDARD LIST ({@see BankMovementListing}): Datum, Text, Betrag, Original,
 *     Zuordnung, Saldo; searched by date, text and amount in the database; the header says
 *     «eingelesen bis … · Saldo …». Two views in the toolbar, no row window, no state. The bank account is chosen at the top of the
 *     rail (only accounts with «Kontoauszug einlesen»; `?account=<code>`). Action cell: the
 *     upload — the shared upload component in its `cell` shape, the work area is the drop target;
 *   - `upload` — POST, one file per request (`file`), XML or ZIP: answers with ONE short sentence
 *     ({@see BankImportResult::message()}) or the service's refusal sentence — per file in fetch
 *     mode, as a flash on a page.
 *
 * The screen carries labels and answers only; every explanation is in the help
 * (backend-screen.md §5). Bank text is foreign input: every value is escaped by the templates.
 * No JavaScript of its own (Rule 7). The using class MUST provide (via its host base): `html()`,
 * `json()`, `redirect()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
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
            $this->help->attach('Backend/BankMovementController/list.help', BankLayout::NS, [], 'Hilfe: Bankkonto', toolbar: true);
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
        $paging = $state->paging(0);
        if ($account !== null) {
            $id      = (int) $account->getId();
            $latest  = $this->em()->getRepository(BankStatement::class)->latestOf($id);
            $first   = $this->em()->getRepository(BankStatement::class)->firstOf($id);
            /** @var BankMovementRepository $movements */
            $movements = $this->em()->getRepository(BankMovement::class);
            $date      = $state->parsed('f_date');
            $args      = [$id, $date[0] ?? null, $date[1] ?? null, $state->parsed('f_text'), $state->parsed('f_amount')];
            $unlinked  = $state->extra('link') === BankMovementListing::LINK_FREE;
            $total     = $movements->countSearch(...[...$args, $unlinked]);
            $paging    = $state->paging($total);
            $rows      = $total === 0 ? [] : $movements->search(...[...$args, $state->sort, $state->descending, $paging->offset(), $paging->pageSize, $unlinked]);
            // What each movement of the page is linked to — one read (ADR-061 stage C1).
            $links     = (new BankLinkService($this->em()))->ofMovements(array_map(static fn(BankMovement $m) => (int) $m->getId(), $rows));
        }

        return [
            'accounts'    => $accounts,
            'account'     => $account,
            'latest'      => $latest,
            'first'       => $first,
            'movements'   => $rows,
            'links'       => $links,
            'definition'  => $definition,
            'state'       => $state,
            'paging'      => $paging,
            'actionBase'  => $base,
            'accountHref' => static fn(string $code): string => $base . '/list?' . http_build_query(['account' => $code]),
        ];
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
        $account = null;
        foreach ($files as $entry) {
            $prefix = $isZip ? $entry['name'] . ': ' : '';
            try {
                foreach ($this->bankStatements()->import($entry['xml'], $entry['name']) as $result) {
                    $done[]  = $prefix . $result->message();
                    $account = $result->bankAccount;
                }
            } catch (BankRefusedException $e) {
                $refused[] = $prefix . $e->getMessage();
            }
        }

        return $this->bankUploadAnswer($fetch, $name, $done, $refused, $account);
    }

    /**
     * One answer for one uploaded file: ok when nothing was refused, else an error naming every
     * refusal (what was read stays read). In fetch mode the list of the account just read is
     * replaced in place, so the rows of the upload stay readable.
     *
     * @param list<string> $done
     * @param list<string> $refused
     */
    private function bankUploadAnswer(bool $fetch, string $name, array $done, array $refused, ?BankAccount $account): RedirectResponse|JsonResponse
    {
        $message = implode(' · ', [...$done, ...$refused]);
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
        $this->messageService->pushFlashAfterRedirect($refused === [] ? 'success' : 'error', ($name !== '' ? $name . ': ' : '') . $message);

        return $this->redirect($this->bankMovementBase() . '/list' . ($account !== null ? '?' . http_build_query(['account' => $account->getCode()]) : ''), 303);
    }
}
