<?php
namespace Z77\Module\Member\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Member\Services\MemberAccounts,
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

    /** Mail links (activation, confirm) — origin from the configured canonical base URL, not the Host header. */
    private function memberAbsoluteUrl(string $path): string
    {
        return DI::getRequest()->getBaseUrl() . $path;
    }

    protected function listAction(): HtmlResponse
    {
        // Waiting decisions first: confirmed accounts are the operator's queue.
        $order = ['confirmed' => 0, 'registered' => 1, 'active' => 2];
        $rows  = $this->memberAccounts()->all();
        usort($rows, static fn($a, $b) =>
            [$order[$a->getState()] ?? 9, $a->getCreatedAt()] <=> [$order[$b->getState()] ?? 9, $b->getCreatedAt()]);

        return $this->html(['accounts' => $rows]);
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
