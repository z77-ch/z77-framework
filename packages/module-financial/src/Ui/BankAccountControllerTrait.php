<?php

namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Financial\Bank\BankAccountHints,
    Z77\Module\Financial\Bank\BankAccountService,
    Z77\Module\Financial\Bank\InvalidBankAccountException,
    Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Entities\BankAccount,
    Z77\Module\Financial\Entities\BankStatement,
    Z77\Module\Financial\Services\LedgerService,
    Z77\Module\Financial\Validators\BankAccountValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › Finanzen › «Bankkonten» (ADR-060 decisions 3, 10; board «Bankkonten», slim form,
 * owner-approved 2026-11-17) — the one place that ties an IBAN to a ledger account. Built like
 * «Zahlungsarten»; mounted by a thin host controller in the backend (ADR-018 pattern): URL
 * `/backend/finance/bank-account/…`, role `admin` (BANK-ACL-001).
 *
 *   - `list` — a STANDARD LIST ({@see BankAccountListing}): state icon, Bezeichnung, IBAN, Konto,
 *     Kontoauszug («nein» or «bis <date>»), the «Aktiv» switch. Action cell: «+ Bankkonto»;
 *   - `add` / `edit` — the WINDOW: Bezeichnung, Kürzel (fixed after creation), IBAN (fixed once a
 *     statement was read), Konto, the switch «Kontoauszug einlesen» and the date «Abgleich ab»
 *     (ADR-061 decision 1, stage C4 — both set = bank-driven from that day); a contradiction with another
 *     module's record for the same IBAN is ONE short line ({@see BankAccountHints});
 *   - `toggle-active` — the inline switch. There is NO delete.
 *
 * The screen carries labels and refusals only — every explanation is in the help
 * (backend-screen.md §5, owner 2026-11-17). Every write goes through {@see BankAccountService}.
 * No JavaScript of its own (Rule 7). The using class MUST provide (via its host base): `html()`,
 * `fetch()`, `fetchError()`, `redirect()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait BankAccountControllerTrait
{
    /** URL root of THIS mount. */
    protected function bankAccountBase(): string
    {
        return BankLayout::ACCOUNT_BASE;
    }

    private function bankAccounts(): BankAccountService
    {
        return new BankAccountService($this->em());
    }

    /**
     * bank account id → «eingelesen bis» (`d.m.Y`) for the accounts that hold a statement.
     *
     * @param list<BankAccount> $accounts
     * @return array<int, string>
     */
    private function bankAccountCovered(array $accounts): array
    {
        $covered = [];
        foreach ($accounts as $account) {
            $latest = $account->getId() === null ? null : $this->em()->getRepository(BankStatement::class)->latestOf((int) $account->getId());
            if ($latest !== null) {
                $covered[(int) $account->getId()] = $latest->getToDate()->format('d.m.Y');
            }
        }

        return $covered;
    }

    /** number → name of the ledger accounts the rows name. */
    private function bankAccountLedgerNames(array $accounts): array
    {
        $names = [];
        foreach ($accounts as $account) {
            $number         = $account->getLedgerAccount();
            $names[$number] ??= $this->em()->getRepository(Account::class)->findOneBy(['number' => $number])?->getName() ?? '';
        }

        return $names;
    }

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = BankAccountListing::definition();
        $state      = $definition->readRequest($request);
        $rows       = $this->bankAccounts()->all();
        if ($state->descending) {
            $rows = array_reverse($rows);
        }
        $fetch = ListDefinition::isFetch($request);
        if (!$fetch) {
            $this->help->attach('Backend/BankAccountController/list.help', BankLayout::NS, [], 'Hilfe: Bankkonten', toolbar: true);
        }
        $response = $this->html([
            'rows'       => $rows,
            'covered'    => $this->bankAccountCovered($rows),
            'ledger'     => $this->bankAccountLedgerNames($rows),
            'definition' => $definition,
            'state'      => $state,
            'actionBase' => $this->bankAccountBase(),
        ]);
        if (!$fetch) {
            $this->layoutManager->addPartials('act', 'Backend/BankAccountController', BankLayout::NS, 'hc1');
        }

        return $response;
    }

    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->bankAccountWindow(new BankAccount());
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $account = $this->bankAccountOf();
        if ($account === null) {
            return $this->bankAccountRefuse('Bankkonto nicht gefunden');
        }

        return $this->bankAccountWindow($account);
    }

    /** The bank account window (ADR-047): GET renders it, POST saves through the service. */
    private function bankAccountWindow(BankAccount $account): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request   = DI::getRequest();
        $isNew     = $account->getId() === null;
        $entry     = $account;
        $validator = new BankAccountValidator($account);

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!$isNew && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'bankAccount', (int) $account->getId())) {
                return $this->bankAccountRefuse('Das Bankkonto wurde inzwischen geändert — bitte neu laden.');
            }
            // Only what the form posted (a disabled field posts nothing and keeps its value).
            $values = array_intersect_key(BodyCleaner::cleanFor(BankAccount::class, $post), $post);
            // The list's switch owns `active`; the currency is the base currency (ADR-060 decision 8);
            // an unticked switch posts nothing, so its absence means «aus».
            unset($values['active'], $values['id'], $values['currency']);
            $values['statement_import'] = !empty($post['statement_import']);
            $values['mixed_use']        = !empty($post['mixed_use']);   // «gemischt geführt» (owner 2026-11-19)
            // «Abgleich ab» (ADR-061 decision 1): the date as posted — '' = none; the entity reads it, the validator
            // names a non-date. A form without the field leaves the date alone.
            unset($values['reconcile_from'], $values['reconcile_from_input']);
            if (array_key_exists('reconcile_from', $post)) {
                $values['reconcile_from'] = is_scalar($post['reconcile_from']) ? trim((string) $post['reconcile_from']) : '';
            }
            try {
                if ($isNew) {
                    $account->mapFromArray($values);
                    $this->bankAccounts()->save($account);
                } else {
                    unset($values['code']);   // fixed after creation
                    $this->bankAccounts()->update($account, $values);
                }

                return $this->bankAccountSaved('Bankkonto «' . $account->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert'));
            } catch (InvalidBankAccountException $e) {
                $validator = $e->validator;
                $entry     = $e->draft;
            }
        }

        $this->help->attach('Backend/BankAccountController/edit.help', BankLayout::NS, [], 'Hilfe: Bankkonto');
        $response = $this->html([
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'entry'      => $entry,
            'isNew'      => $isNew,
            'entryId'    => $isNew ? null : (int) $account->getId(),
            'validator'  => $validator,
            'accounts'   => array_values(array_filter($this->em()->getRepository(Account::class)->allInOrder(), static fn(Account $a) => $a->isPostable() && $a->isActive())),
            'ledger'     => $this->bankAccountLedgerNames([$entry]),
            'currency'   => LedgerService::baseCurrency(),
            'ibanFixed'  => !$isNew && isset($this->bankAccountCovered([$account])[(int) $account->getId()]),
            'hints'      => $isNew ? [] : BankAccountHints::fromModules(DI::getModuleManager())->hintsFor($account, $this->em()),
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('bankAccount', (int) $account->getId()),
            'actionBase' => $this->bankAccountBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/BankAccountController', BankLayout::NS);

        return $response;
    }

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $account = $this->bankAccountOf();
        if ($account === null) {
            return $this->fetchError('Bankkonto nicht gefunden');
        }
        $body = DI::getRequest()->getJsonBody();
        $this->bankAccounts()->setActive($account, (bool) ($body['value'] ?? !$account->isActive()));
        $id   = (int) $account->getId();
        $html = (new TemplateRenderer(BankLayout::NS))->partial('Backend/BankAccountController/_row', [
            'row'        => $account,
            'covered'    => $this->bankAccountCovered([$account])[$id] ?? null,
            'ledgerName' => $this->bankAccountLedgerNames([$account])[$account->getLedgerAccount()] ?? '',
            'actionBase' => $this->bankAccountBase(),
        ]);

        return $this->fetch()->setStatus('success')->setData(['id' => $id])->replaceRow('bankAccount', $id, $html);
    }

    private function bankAccountOf(): ?BankAccount
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $account = $id > 0 ? $this->em()->getRepository(BankAccount::class)->find($id) : null;

        return $account instanceof BankAccount ? $account : null;
    }

    /** Close the window and reload the list region it came from; without the script: flash + the list. */
    private function bankAccountSaved(string $flash): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->bankAccountBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        }

        return $response;
    }

    private function bankAccountRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->bankAccountBase() . '/list', 303);
    }
}
