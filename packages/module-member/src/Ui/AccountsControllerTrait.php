<?php
namespace Z77\Module\Member\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Member\Entities\MemberGrant,
    Z77\Module\Member\Services\InvitationFlow,
    Z77\Module\Member\Services\MemberAccounts,
    Z77\Module\Member\Services\MemberGrants,
    Z77\Module\Member\Services\RegistrationFlow,
    Z77\Persistence\Resolver\DataSourceResolver,
    Z77\Persistence\Resolver\UnifiedEntityManager,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The member accounts surface (B7 backend handgrip) — mounted by a thin host
 * controller in the backend (dms Drive pattern, ADR-018): the host provides
 * route + auth + shell, all logic and templates live here in module-member.
 * Host side: `use AccountsControllerTrait` + a one-line layout config
 * delegating to {@see AccountsLayout::config()}.
 *
 * Actions on an account follow the spec exactly: «Freischalten» (confirmed →
 * active, fires the project hook, sends the activation mail) and «Ablehnen»
 * (delete, NO automatic mail). A failing hook leaves the account 'confirmed'
 * and surfaces as an error flash — no active account without its project side.
 */
trait AccountsControllerTrait
{
    private const MEMBER_NS = 'Z77\\Module\\Member';

    private function memberAccounts(): MemberAccounts
    {
        return new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])));
    }

    private function memberFlow(): RegistrationFlow
    {
        return RegistrationFlow::create($this->memberAbsoluteUrl('/member/main/confirm'));
    }

    private function memberGrants(): MemberGrants
    {
        return new MemberGrants(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])));
    }

    /** The invitation story — its grant handgrips (activate, reject) live there. */
    private function memberInvites(): InvitationFlow
    {
        return InvitationFlow::create($this->memberAbsoluteUrl('/member/main/register'));
    }

    /** Mail links (activation, confirm) — origin from the configured canonical base URL, not the Host header. */
    private function memberAbsoluteUrl(string $path): string
    {
        return DI::getRequest()->getBaseUrl() . $path;
    }

    /**
     * The set this mount shows. A host narrows it: AXO3 mounts one list per way
     * in — open registration, invitation, rejected — so the operator never sorts
     * one long list by eye to find what waits for HIM (B10 v1.17.0).
     *
     * @return list<\Z77\Module\Member\Entities\MemberAccount>
     */
    protected function memberListRows(): array
    {
        return $this->memberAccounts()->all();
    }

    /** Heading of the list — a narrowed mount says what it shows. */
    protected function memberListTitle(): string
    {
        return 'Member-Konten';
    }

    /** The sentence for the empty list. Belongs to the mount, not to the trait. */
    protected function memberListEmpty(): string
    {
        return 'Keine Registrierungen vorhanden.';
    }

    /**
     * URL root of THIS mount — every row button and every modal form is built
     * from it.
     *
     * ⚠️ It used to be hard-coded as '/backend/service/member-accounts' in four
     * templates. With a second mount that is a silent trap: the deed lands (the
     * action is the same code), but the `reload` afterwards refreshes the FIRST
     * mount's list — the operator watches a page that never changes and clicks
     * again.
     */
    protected function memberListBase(): string
    {
        return '/backend/service/member-accounts';
    }

    /**
     * One short sentence per account that the MOUNT wants on the row, keyed by
     * account id. Empty by default — the module has nothing to say about an
     * account beyond its state.
     *
     * The seam exists because a waiting decision is better made with the
     * project's knowledge than without it: AXO3 marks the registrations that
     * brought a drawing along, so the operator activates knowing there is a
     * package hanging on that account (B5 stage D4). The template renders the
     * note as an intent badge and escapes it; nothing here interprets it.
     *
     * @param  list<\Z77\Module\Member\Entities\MemberAccount> $rows
     * @return array<string,string>
     */
    protected function memberRowNotes(array $rows): array
    {
        return [];
    }

    /**
     * The GRANTS this mount shows (ADR-037) — every one by default, waiting
     * first. A narrowed mount decides for itself: a list of open registrations
     * has no business showing grants and returns `[]`; a list of invitations
     * shows exactly the waiting ones. Same reasoning as memberListRows().
     *
     * @return list<MemberGrant>
     */
    protected function memberGrantRows(): array
    {
        return $this->memberGrants()->all();
    }

    protected function listAction(): HtmlResponse
    {
        // Waiting decisions first: confirmed accounts are the operator's queue.
        $order = ['confirmed' => 0, 'registered' => 1, 'active' => 2];
        $rows  = $this->memberListRows();
        usort($rows, static fn($a, $b) =>
            [$order[$a->getState()] ?? 9, $a->getCreatedAt()] <=> [$order[$b->getState()] ?? 9, $b->getCreatedAt()]);

        return $this->html([
            'accounts'     => $rows,
            'tenantLabels' => $this->memberTenantLabels($rows),
            // ⚠️ Deliberately NOT named `title`/`path`: TemplateRenderer does
            // extract(..., EXTR_SKIP), and a context key that collides with an
            // existing variable is dropped WITHOUT a word.
            'rowNotes'     => $this->memberRowNotes($rows),
            'grants'       => $this->memberGrantRowsPrepared($this->memberGrantRows()),
            'actionBase'   => $this->memberListBase(),
            'listTitle'    => $this->memberListTitle(),
            'listEmpty'    => $this->memberListEmpty(),
        ]);
    }

    /**
     * Grant rows the template can print without knowing the store: the
     * account behind the grant (a grant on a vanished account is skipped — the
     * cleanup takes it), the tenant it attaches TO and the account's HOME by
     * name, the state, and the id for the two handgrips. Waiting first.
     *
     * The home is named on purpose: the operator is about to let a person of
     * one customer work for another, and that is the sentence he has to read
     * before he does.
     *
     * @param  list<MemberGrant> $grants
     * @return list<array{id:string, email:string, name:string, tenantName:string, homeName:string,
     *               inviter:string, state:string, waiting:bool, suspended:bool, createdAt:string, activatedAt:string}>
     */
    private function memberGrantRowsPrepared(array $grants): array
    {
        $accounts = $this->memberAccounts();
        $rows     = [];

        foreach ($grants as $grant) {
            $account = $accounts->findById($grant->getAccountId());
            if ($account === null) {
                continue;
            }
            $inviter = $accounts->findById((string)$grant->getInvitedBy());

            $rows[] = [
                'id'          => (string)$grant->getId(),
                'email'       => $account->getEmail(),
                'name'        => trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? '')),
                'tenantName'  => $this->memberTenantName($grant->getTenantRef()),
                'homeName'    => $this->memberTenantName(trim((string)$account->getTenantRef())),
                'inviter'     => $inviter?->getEmail() ?? '',
                'state'       => $grant->getState(),
                'waiting'     => $grant->isConfirmed(),
                'suspended'   => $grant->isSuspended(),
                'createdAt'   => (string)$grant->getCreatedAt(),
                'activatedAt' => (string)$grant->getActivatedAt(),
            ];
        }

        usort($rows, static fn(array $a, array $b): int =>
            [$a['waiting'] ? 0 : 1, $a['createdAt']] <=> [$b['waiting'] ? 0 : 1, $b['createdAt']]);

        return $rows;
    }

    /** The project's readable name for a reference, or the bare reference. */
    private function memberTenantName(string $ref): string
    {
        if ($ref === '') {
            return '';
        }
        $hook = (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', self::MEMBER_NS)
            ->get('tenantLabelHook', '');

        try {
            return $hook !== '' ? ((string)(new $hook())($ref) ?: $ref) : $ref;
        } catch (\Throwable) {
            return $ref;
        }
    }

    /**
     * Name and master of every project reference occurring in the list (B7
     * v1.1.0). The row needs both, because a waiting activation either CREATES
     * a reference (open registration) or ATTACHES to an existing one (an
     * invitation) — and that is precisely the difference nobody can see any
     * more once it has been decided wrongly.
     *
     * ⚠️ «Wer eingeladen hat» is DERIVED, not stored: only the master may
     * invite, so the inviter of any invited account is the master of that
     * reference. Storing it a second time would be a field that can disagree
     * with the rule — and the invitation token, which does carry `invitedBy`,
     * is deleted by the daily cleanup once it has been used.
     *
     * @param  list<\Z77\Module\Member\Entities\MemberAccount> $rows
     * @return array<string,array{name:string,master:string}>
     */
    private function memberTenantLabels(array $rows): array
    {
        $hook   = (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', self::MEMBER_NS)
            ->get('tenantLabelHook', '');
        $labels = [];

        foreach ($rows as $account) {
            $ref = trim((string)$account->getTenantRef());
            if ($ref === '' || isset($labels[$ref])) {
                continue;
            }

            $master = null;
            foreach ($this->memberAccounts()->findByTenant($ref) as $candidate) {
                if ($candidate->isMaster()) {
                    $master = $candidate;
                    break;
                }
            }

            $labels[$ref] = [
                'name'   => $hook !== '' ? (string)(new $hook())($ref) : $ref,
                'master' => $master?->getEmail() ?? '',
            ];
        }

        return $labels;
    }

    /** Confirm modal for activation (entity-token guarded, like the reset modals). */
    protected function confirmActivateAction(): HtmlResponse|FetchResponse
    {
        return $this->memberConfirmModal('confirmActivate');
    }

    /** Confirm modal for rejection. */
    protected function confirmRejectAction(): HtmlResponse|FetchResponse
    {
        return $this->memberConfirmModal('confirmReject');
    }

    /** Confirm modal for the 2FA reset (lost device — B8 spec handgrip). */
    protected function confirmTotpResetAction(): HtmlResponse|FetchResponse
    {
        return $this->memberConfirmModal('confirmTotpReset');
    }

    #[Fetch, HttpMethod('POST')]
    protected function totpResetAction(): FetchResponse
    {
        [$account, $error] = $this->memberAccountFromPost();
        if ($error !== null) {
            return $error;
        }
        if (!$account->hasTotp() && !$account->hasPendingTotpSetup()) {
            return $this->fetchError('Für dieses Konto ist kein Zwei-Faktor-Schutz eingerichtet');
        }

        \Z77\Module\Member\Services\TotpSetup::create()->resetByOperator($account);
        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Zwei-Faktor-Schutz für «' . $account->getEmail() . '» zurückgesetzt — der Kunde richtet neu ein.'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    #[Fetch, HttpMethod('POST')]
    protected function activateAction(): FetchResponse
    {
        [$account, $error] = $this->memberAccountFromPost();
        if ($error !== null) {
            return $error;
        }
        if (!$account->isConfirmed()) {
            return $this->fetchError('Nur bestätigte Konten können freigeschaltet werden');
        }

        try {
            $this->memberFlow()->activate($account, $this->memberAbsoluteUrl($this->memberEntryPath()));
        } catch (\Throwable $e) {
            // Hook failed — account stays 'confirmed' (MemberAccounts contract).
            $this->messageService->pushFlashAfterRedirect(
                'error',
                'Freischaltung fehlgeschlagen — das Konto bleibt «bestätigt»: ' . $e->getMessage()
            );

            return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
        }

        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Konto «' . $account->getEmail() . '» freigeschaltet — die Mail an den Kunden ist unterwegs.'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    #[Fetch, HttpMethod('POST')]
    protected function rejectAction(): FetchResponse
    {
        [$account, $error] = $this->memberAccountFromPost();
        if ($error !== null) {
            return $error;
        }
        if ($account->isActive()) {
            return $this->fetchError('Aktive Konten können nicht abgelehnt werden');
        }

        $this->memberFlow()->reject($account);
        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Konto «' . $account->getEmail() . '» gelöscht — es wird keine automatische Mail versandt.'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    // ── grants (ADR-037) ───────────────────────────────────────────────────

    /** Confirm modal for activating a grant — the last screen before a person of one customer may work for another. */
    protected function confirmGrantActivateAction(): HtmlResponse|FetchResponse
    {
        return $this->memberGrantModal('confirmGrantActivate');
    }

    /** Confirm modal for rejecting a grant. */
    protected function confirmGrantRejectAction(): HtmlResponse|FetchResponse
    {
        return $this->memberGrantModal('confirmGrantReject');
    }

    /**
     * confirmed → active. ⚠️ NO activation hook — nothing is created, the
     * account and the tenant both exist; the grant only ties them together.
     * The person gets a mail (InvitationFlow::activateGrant()).
     */
    #[Fetch, HttpMethod('POST')]
    protected function grantActivateAction(): FetchResponse
    {
        [$grant, $error] = $this->memberGrantFromPost();
        if ($error !== null) {
            return $error;
        }
        if (!$grant->isConfirmed()) {
            return $this->fetchError('Nur wartende Zugänge können freigeschaltet werden');
        }

        $this->memberInvites()->activateGrant($grant, $this->memberAbsoluteUrl($this->memberEntryPath()));
        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Zugang zu «' . $this->memberTenantName($grant->getTenantRef()) . '» freigeschaltet — die Mail an den Kunden ist unterwegs.'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    /** The grant disappears; the account behind it stays where it lives. NO automatic mail. */
    #[Fetch, HttpMethod('POST')]
    protected function grantRejectAction(): FetchResponse
    {
        [$grant, $error] = $this->memberGrantFromPost();
        if ($error !== null) {
            return $error;
        }

        $this->memberInvites()->rejectGrant($grant);
        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Zugang zu «' . $this->memberTenantName($grant->getTenantRef()) . '» entfernt — das Konto bleibt bestehen, es wird keine automatische Mail versandt.'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    private function memberGrantModal(string $template): HtmlResponse|FetchResponse
    {
        $id    = trim((string)DI::getRequest()->getGetParameter('id'));
        $grant = $id !== '' ? $this->memberGrants()->findById($id) : null;
        $rows  = $grant === null ? [] : $this->memberGrantRowsPrepared([$grant]);
        if ($rows === []) {
            return $this->fetchError('Zugang nicht gefunden');
        }

        $response = $this->html([
            'grant'      => $rows[0],
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('memberGrant', $id),
            'actionBase' => $this->memberListBase(),
        ]);
        $this->layoutManager->addPartials($template, 'Backend/AccountsController', self::MEMBER_NS);

        return $response;
    }

    /** @return array{0: ?MemberGrant, 1: ?FetchResponse} */
    private function memberGrantFromPost(): array
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = trim((string)($body['grant_id'] ?? ''));
        if ($id === '') {
            return [null, $this->fetchError('Zugangs-Id fehlt')];
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string)($body['entity_csrf'] ?? '')), 'memberGrant', $id)) {
            return [null, $this->fetchError('Invalid token')];
        }
        $grant = $this->memberGrants()->findById($id);
        if ($grant === null) {
            return [null, $this->fetchError('Zugang nicht gefunden')];
        }

        return [$grant, null];
    }

    // ── shared plumbing ────────────────────────────────────────────────────

    private function memberConfirmModal(string $template): HtmlResponse|FetchResponse
    {
        $id      = trim((string)DI::getRequest()->getGetParameter('id'));
        $account = $id !== '' ? $this->memberAccounts()->findById($id) : null;
        if ($account === null) {
            return $this->fetchError('Konto nicht gefunden');
        }

        $response = $this->html([
            'account'    => $account,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('memberAccount', $id),
            // The activation modal has to repeat the create-or-attach sentence:
            // it is the last screen before the irreversible half of the decision.
            'tenantLabels' => $this->memberTenantLabels([$account]),
            'actionBase'   => $this->memberListBase(),
        ]);
        $this->layoutManager->addPartials($template, 'Backend/AccountsController', self::MEMBER_NS);

        return $response;
    }

    /** @return array{0: ?\Z77\Module\Member\Entities\MemberAccount, 1: ?FetchResponse} */
    private function memberAccountFromPost(): array
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = trim((string)($body['account_id'] ?? ''));
        if ($id === '') {
            return [null, $this->fetchError('Konto-Id fehlt')];
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string)($body['entity_csrf'] ?? '')), 'memberAccount', $id)) {
            return [null, $this->fetchError('Invalid token')];
        }
        $account = $this->memberAccounts()->findById($id);
        if ($account === null) {
            return [null, $this->fetchError('Konto nicht gefunden')];
        }

        return [$account, null];
    }

    private function memberEntryPath(): string
    {
        return (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', self::MEMBER_NS)
            ->get('memberEntryPath', '/');
    }
}
