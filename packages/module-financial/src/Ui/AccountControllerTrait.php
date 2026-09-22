<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Entities\AccountType,
    Z77\Module\Financial\Repositories\AccountRepository,
    Z77\Module\Financial\Services\AccountNumberChangedException,
    Z77\Module\Financial\Services\AccountService,
    Z77\Module\Financial\Services\ChartNotEmptyException,
    Z77\Module\Financial\Services\InvalidAccountException,
    Z77\Module\Financial\Validators\AccountValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The chart-of-accounts surface (plan §5.1) — mounted by a thin host
 * controller in the backend (ADR-018 pattern, like the tax codes next to
 * it): the host provides route + auth + shell, all logic and templates live
 * here in module-financial. Host side: `use AccountControllerTrait` + a
 * one-line layout config delegating to {@see AccountLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list the chart in number order, indented by its groups;
 *   - add an account or a group; edit name, type, group and «bebuchbar» —
 *     the NUMBER is fixed once the account exists;
 *   - activate / deactivate (inline switch). There is NO delete: journal
 *     lines reference an account;
 *   - «KMU-Kontenrahmen übernehmen» — offered ONLY while the chart is empty
 *     (owner, 2026-09-22); the service refuses it otherwise.
 *
 * Every write goes through {@see AccountService}; a MANAGED account is
 * never mutated here before validation (ADR-039 decision 9) — the cleaned
 * values go to the service, a refused change comes back as the exception's
 * draft. No JavaScript of its own (shared `core.js` wiring). The using class
 * MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`,
 * `em()`, `$layoutManager`, `$messageService`.
 */
trait AccountControllerTrait
{
    private const ACCOUNT_NS = 'Z77\\Module\\Financial';

    /** German display labels — PRESENTATION ONLY (the `ROLE_LABELS` pattern). The type SET is {@see AccountType}. */
    private const ACCOUNT_TYPE_LABELS = [
        'asset'     => 'Aktiven',
        'liability' => 'Fremdkapital',
        'equity'    => 'Eigenkapital',
        'expense'   => 'Aufwand',
        'revenue'   => 'Ertrag',
    ];

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function accountListBase(): string
    {
        return '/backend/finance/account';
    }

    private function accounts(): AccountRepository
    {
        return $this->em()->getRepository(Account::class);
    }

    private function accountService(): AccountService
    {
        return new AccountService($this->em());
    }

    /** @return array<string,string> type value → German label, for selects and the list */
    private function accountTypeLabels(): array
    {
        $labels = [];
        foreach (AccountType::cases() as $type) {
            $labels[$type->value] = self::ACCOUNT_TYPE_LABELS[$type->value] ?? $type->value;
        }

        return $labels;
    }

    /**
     * The form's values, cleaned (`postable` comes from a select, `1` / `0`).
     * `number` only for a new account (fixed
     * afterwards), `active` never (own switch), and the group arrives as
     * `parent_id` and is resolved to the entity — a raw `parent` key from
     * the body is dropped.
     *
     * @return array<string, mixed>|string the values, or an error message
     */
    private function accountValues(array $body, bool $isNew): array|string
    {
        $values = BodyCleaner::cleanFor(Account::class, $body);
        unset($values['id'], $values['active'], $values['parent']);
        if (!$isNew) {
            unset($values['number']);
        }

        $parentId = (int) ($body['parent_id'] ?? 0);
        if ($parentId === 0) {
            $values['parent'] = null;
        } else {
            $parent = $this->accounts()->find($parentId);
            if ($parent === null) {
                return 'Übergeordnete Gruppe nicht gefunden';
            }
            $values['parent'] = $parent;
        }

        return $values;
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $accounts = $this->accounts()->allInOrder();

        return $this->html([
            'accounts'   => $accounts,
            'depths'     => self::accountDepths($accounts),
            'typeLabels' => $this->accountTypeLabels(),
            'actionBase' => $this->accountListBase(),
        ]);
    }

    /**
     * How deep each account sits in its group chain (0 = top) — the list's
     * indentation. Walks parents already in the Identity Map.
     *
     * @param list<Account> $accounts
     * @return array<int, int> account id → depth
     */
    private static function accountDepths(array $accounts): array
    {
        $depths = [];
        foreach ($accounts as $account) {
            $depth = 0;
            for ($p = $account->getParent(); $p !== null && $depth < 32; $p = $p->getParent()) {
                $depth++;
            }
            $depths[(int) $account->getId()] = $depth;
        }

        return $depths;
    }

    // ── add / edit ───────────────────────────────────────────────────────

    /** «Konto anlegen» — nothing is managed before the service persists it, so the entity is built from the body. */
    protected function addAction(): HtmlResponse|FetchResponse
    {
        $account   = new Account(['type' => AccountType::Asset->value]);
        $validator = null;

        if (DI::getRequest()->isPost()) {
            $values = $this->accountValues(DI::getRequest()->getJsonBody(), true);
            if (is_string($values)) {
                return $this->fetchError($values);
            }
            $account->mapFromArray($values);

            try {
                $this->accountService()->save($account);
                $this->messageService->pushFlashAfterRedirect('success', 'Konto «' . $account->label() . '» angelegt');

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $account->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            } catch (InvalidAccountException $e) {
                $validator = $e->validator;
            }
        }

        return $this->renderAccountForm($account, $validator, null);
    }

    /**
     * «Konto bearbeiten»: the managed account is NOT mutated here — the
     * cleaned values go to `AccountService::update()`, which validates a
     * detached draft first. On refusal the draft is what the form shows.
     */
    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $account = $id ? $this->accounts()->find($id) : null;
        if ($account === null) {
            return $this->fetchError('Konto nicht gefunden');
        }
        $shown     = $account;
        $validator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), 'account', $account->getId())) {
                return $this->fetchError('Invalid token');
            }
            $values = $this->accountValues($body, false);
            if (is_string($values)) {
                return $this->fetchError($values);
            }

            try {
                $this->accountService()->update($account, $values);
                $this->messageService->pushFlashAfterRedirect('success', 'Konto «' . $account->label() . '» gespeichert');

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $account->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            } catch (InvalidAccountException $e) {
                $validator = $e->validator;
                $shown     = $e->draft;
            } catch (AccountNumberChangedException) {
                return $this->fetchError('Die Kontonummer ist nach dem Anlegen fix — für eine andere Nummer ein neues Konto anlegen.');
            }
        }

        return $this->renderAccountForm($shown, $validator, $account);
    }

    /** @param Account|null $stored the managed account on edit — excluded from the group select */
    private function renderAccountForm(Account $shown, ?AccountValidator $validator, ?Account $stored): HtmlResponse
    {
        $isNew  = $shown->getId() === null;
        $groups = [];
        foreach ($this->accounts()->allInOrder() as $candidate) {
            if ($candidate->isPostable() || $candidate === $stored) {
                continue;
            }
            // Active groups are offered; an inactive one only when it is the current group.
            if ($candidate->isActive() || $candidate === $shown->getParent()) {
                $groups[] = $candidate;
            }
        }

        $response = $this->html([
            'entry'      => $shown,
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('account', $shown->getId()),
            'validator'  => $validator ?? new AccountValidator($shown),
            'typeLabels' => $this->accountTypeLabels(),
            'groups'     => $groups,
            'actionBase' => $this->accountListBase(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/AccountController', self::ACCOUNT_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $account = $id ? $this->accounts()->find($id) : null;
        if ($account === null) {
            return $this->fetchError('Konto nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        try {
            $this->accountService()->setActive($account, (bool) ($body['value'] ?? !$account->isActive()));
        } catch (InvalidAccountException) {
            return $this->fetchError('Konto ist unvollständig — zuerst bearbeiten.');
        }

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    // ── KMU chart ────────────────────────────────────────────────────────

    /** Confirmation modal for «KMU-Kontenrahmen übernehmen» — only while the chart is empty. */
    protected function confirmAdoptKmuChartAction(): HtmlResponse|FetchResponse
    {
        if (!$this->accounts()->isEmpty()) {
            return $this->fetchError('Der Kontenplan ist nicht leer — der KMU-Kontenrahmen wird nur in einen leeren Kontenplan übernommen.');
        }

        $response = $this->html(['actionBase' => $this->accountListBase()]);
        $this->layoutManager->addPartials('confirmAdoptKmuChart', 'Backend/AccountController', self::ACCOUNT_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function adoptKmuChartAction(): FetchResponse
    {
        try {
            $created = $this->accountService()->adoptKmuChart();
        } catch (ChartNotEmptyException) {
            return $this->fetchError('Der Kontenplan ist nicht leer — der KMU-Kontenrahmen wird nur in einen leeren Kontenplan übernommen.');
        }
        $this->messageService->pushFlashAfterRedirect('success', 'KMU-Kontenrahmen übernommen: ' . $created . ' Konten und Gruppen');

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }
}
