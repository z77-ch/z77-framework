<?php

namespace Z77\Module\Api\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Api\Entities\ApiDevice,
    Z77\Module\Api\Services\ApiDevices,
    Z77\Module\Api\Services\ApiDeviceScopes,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Entities\BackendUser,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › System › Geräte — the devices of the API device channel (ADR-057, board
 * «Geräte» approved 2026-11-12): who has which phone paired, what it may do, and the way to
 * pair a new one or block one. Mounted by a thin host controller in the backend (ADR-018
 * pattern): `use ApiDeviceControllerTrait` + a one-line layout config delegating to
 * {@see ApiDeviceLayout::config()}; URL `/backend/system/api-device/…`.
 *
 *   - `list` — a STANDARD LIST ({@see ApiDeviceListing}): tabs «Aktiv» · «Alle», the state icon
 *     opens the device WINDOW, the ⋮ the row's context menu. Action cell «+ Gerät koppeln»;
 *   - `pair` — the pairing WINDOW: owner, name, scope switches; a code is created as the window
 *     opens (GET) and again on «Neuer Code» (POST — the code shown before is withdrawn). Shows
 *     the code as text and as a QR of `<origin>/app/pair?code=…` (the app shell's pairing
 *     page), how long it lives, and «Wartet auf das Handy …»;
 *   - `pair-status?ticket=` — the window's LIVE LISTENER (fetch.md FETCH-LIVE-001, no script of
 *     this module): every 5 s the status box anew; once the phone redeemed the code the window
 *     turns into the new device's window and the list reloads. The ticket is a random key of
 *     THIS session — the code never travels in a URL;
 *   - `edit?id=` — the device WINDOW: name and scopes of a live device, the facts, «Sperren …»;
 *     a blocked device is read-only (E13: blocking is final, no «aktiv» switch);
 *   - `confirm-revoke?id=` / `revoke` — the confirm (in the window) and the block itself.
 *
 * Permissions: {@see ApiDeviceAccess} — every backend user his own devices, the user governor
 * (ADMIN and up) all of them. Every write goes through {@see ApiDevices}. The using class MUST
 * provide (via its host base): `html()`, `fetch()`, `fetchError()`, `redirect()`,
 * `$layoutManager`, `$messageService`, `$help`.
 */
trait ApiDeviceControllerTrait
{
    private const API_DEVICE_NS = 'Z77\\Module\\Api';

    /** Session key of the open pairing codes: ticket => [code, expires]. */
    private const API_DEVICE_PAIRING_SESSION = 'apiDevicePairing';

    /** Seconds between two questions of the pairing window (the live listener's floor). */
    private const API_DEVICE_PAIRING_POLL = 5;

    /** The toolbar tabs, view => label ({@see ApiDeviceListing::VIEWS}). */
    private const API_DEVICE_VIEWS = ['active' => 'Aktiv', 'all' => 'Alle'];

    /** URL root of THIS mount. */
    protected function apiDeviceBase(): string
    {
        return ApiDeviceLayout::BASE;
    }

    private function apiDevices(): ApiDevices
    {
        return new ApiDevices(DI::getUnifiedEntityManager());
    }

    private function apiDeviceScopes(): ApiDeviceScopes
    {
        return new ApiDeviceScopes(DI::getModuleManager());
    }

    /** @return array<int, BackendUser> id => user */
    private function apiDeviceUsers(): array
    {
        $users = [];
        foreach (DI::getUnifiedEntityManager()->getRepository(BackendUser::class)->findAll() as $user) {
            if ($user instanceof BackendUser && $user->getId() !== null) {
                $users[(int) $user->getId()] = $user;
            }
        }

        return $users;
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = ApiDeviceListing::definition();
        $state      = $definition->readRequest($request);
        $access     = ApiDeviceAccess::current();
        $users      = $this->apiDeviceUsers();
        $service    = $this->apiDevices();
        $devices    = $access->governs() ? $service->listAll() : $service->listFor($access->actorId());
        $rows       = ApiDeviceListing::apply($devices, $state, static fn(ApiDevice $d): string => ApiDeviceAccess::ownerName($users[$d->getOwnerId()] ?? null));
        $paging     = $state->paging(count($rows));
        $scopes     = $this->apiDeviceScopes();

        $page = [];
        foreach (array_slice($rows, $paging->offset(), $paging->pageSize) as $device) {
            $page[] = $this->apiDeviceRowContext($device, $users, $scopes);
        }

        $isFetch = ListDefinition::isFetch($request);
        if (!$isFetch) {
            // The list's help closes its toolbar (HELP-003) — for the page, not for a region load.
            $this->help->attach('Backend/ApiDeviceController/list.help', self::API_DEVICE_NS, [], 'Hilfe: Geräte', toolbar: true);
        }
        $response = $this->html([
            'rows'       => $page,
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'governs'    => $access->governs(),
            'actionBase' => $this->apiDeviceBase(),
        ]);
        // The fragment owns its header slots: «+ Gerät koppeln» (the entry's most frequent action)
        // and the view tabs. A FETCH of the region wants the list alone (listing.md).
        if (!$isFetch) {
            $this->layoutManager->addPartials('act', 'Backend/ApiDeviceController', self::API_DEVICE_NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/ApiDeviceController', self::API_DEVICE_NS, 'hc2');
        }

        return $response;
    }

    /** The row's ⋮ — «Öffnen», and «Sperren …» while the device is live and the actor may. */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $access = ApiDeviceAccess::current();
        $device = $this->apiDevices()->find((int) DI::getRequest()->getGetParameter('id'));
        if ($device === null || !$access->sees($device)) {
            return $this->fetchError('Gerät nicht gefunden');
        }
        $users    = $this->apiDeviceUsers();
        $response = $this->html([
            'device'     => $device,
            'canRevoke'  => !$device->isRevoked() && $access->mayActFor($users[$device->getOwnerId()] ?? null),
            'region'     => ApiDeviceListing::definition()->region(),
            'actionBase' => $this->apiDeviceBase(),
        ]);
        // Appended, not swapped: opened as a PAGE the kernel answers 303 to the list (MODAL-PAGE-001).
        $this->layoutManager->addPartials('actions', 'Backend/ApiDeviceController', self::API_DEVICE_NS);

        return $response;
    }

    // ── pairing (window) ─────────────────────────────────────────────────

    /**
     * «+ Gerät koppeln»: GET opens the window with a fresh code for the actor, every scope on
     * (today's targets are few and each is narrow); POST — «Neuer Code» — withdraws the code
     * shown before and creates one for the posted owner, name and scopes. Owner and scopes are
     * IN the code, so a change needs a new one (board «Geräte»).
     */
    #[Csrf]
    protected function pairAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $access  = ApiDeviceAccess::current();
        $owners  = $access->ownerChoices($this->apiDeviceUsers());
        if ($owners === []) {
            return $this->apiDeviceRefuse('Nur ein angemeldeter Backend-Benutzer kann ein Gerät koppeln.');
        }
        $scopes  = $this->apiDeviceScopes();
        $ownerId = $access->actorId();
        $label   = '';
        $picked  = array_keys($scopes->all());
        $errors  = [];

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            $this->apiDeviceWithdraw(is_string($post['ticket'] ?? null) ? $post['ticket'] : '');
            $ownerId = (int) ($post['owner_id'] ?? 0);
            if (!isset($owners[$ownerId])) {
                $errors['owner_id'] = 'Für diesen Benutzer darfst du kein Gerät koppeln.';
                $ownerId = $access->actorId();
            }
            $label  = mb_substr(trim(is_string($post['label'] ?? null) ? $post['label'] : ''), 0, ApiDevices::LABEL_LENGTH);
            $picked = $scopes->pick($post['scopes'] ?? []);
        }

        $pairing = null;
        if ($errors === []) {
            $now     = time();
            $code    = $this->apiDevices()->createPairingCode($ownerId, $label, $picked, $now);
            $pairing = $this->apiDevicePairingContext($code, $this->apiDeviceOpenTicket($code, $now + ApiDevices::PAIRING_TTL_SECONDS), $now + ApiDevices::PAIRING_TTL_SECONDS, $now);
        }

        $this->help->attach('Backend/ApiDeviceController/pair.help', self::API_DEVICE_NS, [], 'Hilfe: Gerät koppeln');
        $response = $this->html([
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'owners'     => $owners,
            'ownerId'    => $ownerId,
            'label'      => $label,
            'scopes'     => $scopes->all(),
            'picked'     => $picked,
            'errors'     => $errors,
            'pairing'    => $pairing,
            'actionBase' => $this->apiDeviceBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('pair', 'Backend/ApiDeviceController', self::API_DEVICE_NS);

        return $response;
    }

    /**
     * The pairing window's live listener. Asks only about a code of THIS session (the ticket);
     * answers with the status box anew, or — paired — turns the window into the device window.
     */
    #[Fetch]
    protected function pairStatusAction(): FetchResponse
    {
        $ticket  = (string) DI::getRequest()->getGetParameter('ticket');
        $open    = $this->apiDeviceTickets()[$ticket] ?? null;
        $now     = time();
        $target  = '[data-api-device-pair="' . (preg_match('/^[0-9a-f]{32}$/', $ticket) === 1 ? $ticket : 'x') . '"]';
        $state   = $open === null ? ['state' => 'gone', 'deviceId' => null] : $this->apiDevices()->pairingState($open['code'], $now);
        $device  = $state['state'] === 'paired' ? $this->apiDevices()->find((int) $state['deviceId']) : null;

        if ($device !== null) {
            $this->apiDeviceWithdraw($ticket);
            $this->messageService->pushFlash('success', 'Gekoppelt — ' . $device->getLabel() . ($device->getAgent() !== '' && $device->getAgent() !== $device->getLabel() ? ' · ' . $device->getAgent() : ''));

            return $this->fetch()
                ->setStatus('success')
                ->addCommand('open-window', ['url' => $this->apiDeviceBase() . '/edit?id=' . (int) $device->getId(), 'replace' => true])
                ->addCommand('refresh-region', ['name' => ApiDeviceListing::definition()->region()]);
        }

        // `gone` before the code's time is up: a redemption in this very moment — ask again.
        $waiting = $open !== null && ($state['state'] === 'waiting' || $now < $open['expires']);
        if (!$waiting) {
            $this->apiDeviceWithdraw($ticket);
        }
        $html = (new TemplateRenderer(self::API_DEVICE_NS))->partial('Backend/ApiDeviceController/_pairStatus', [
            'ticket'    => $ticket,
            'waiting'   => $waiting,
            'expiresAt' => (int) ($open['expires'] ?? 0),
            'now'       => $now,
            'statusUrl' => $this->apiDeviceBase() . '/pair-status?ticket=' . $ticket,
            'every'     => self::API_DEVICE_PAIRING_POLL,
        ]);

        return $this->fetch()->addCommand('replace-html', ['target' => $target, 'html' => $html]);
    }

    // ── device window ────────────────────────────────────────────────────

    /**
     * The device WINDOW: GET shows it, POST saves name and scopes through ApiDevices::update().
     * Fetched, the save closes the window and reloads the list region it came from; without the
     * script it is a page and the save redirects to the list.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $access  = ApiDeviceAccess::current();
        $service = $this->apiDevices();
        $device  = $service->find((int) $request->getGetParameter('id'));
        if ($device === null || !$access->sees($device)) {
            return $this->apiDeviceRefuse('Gerät nicht gefunden');
        }
        $users   = $this->apiDeviceUsers();
        $owner   = $users[$device->getOwnerId()] ?? null;
        $canEdit = !$device->isRevoked() && $access->mayActFor($owner);
        $scopes  = $this->apiDeviceScopes();

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!$canEdit) {
                return $this->apiDeviceRefuse($device->isRevoked() ? 'Das Gerät ist gesperrt — es lässt sich nicht mehr ändern.' : 'Dieses Gerät darfst du nicht ändern.');
            }
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'apiDevice', (int) $device->getId())) {
                return $this->apiDeviceRefuse('Das Fenster ist veraltet — bitte neu öffnen.');
            }
            $label   = is_string($post['label'] ?? null) ? $post['label'] : '';
            $updated = $service->update((int) $device->getId(), $label, $scopes->pick($post['scopes'] ?? [], $device->getScopes()));
            if ($updated === null) {
                return $this->apiDeviceRefuse('Das Gerät ist inzwischen gesperrt — es lässt sich nicht mehr ändern.');
            }

            return $this->apiDeviceSaved('Gerät «' . $updated->getLabel() . '» gespeichert');
        }

        $this->help->attach('Backend/ApiDeviceController/edit.help', self::API_DEVICE_NS, [], 'Hilfe: Gerät');
        $response = $this->html([
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'device'     => $device,
            'ownerName'  => ApiDeviceAccess::ownerName($owner),
            'canEdit'    => $canEdit,
            'scopes'     => $scopes->choices($device->getScopes()),
            'entityCsrf' => $canEdit ? DI::getCsrfService()->generateEntityToken('apiDevice', (int) $device->getId()) : '',
            'actionBase' => $this->apiDeviceBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/ApiDeviceController', self::API_DEVICE_NS);

        return $response;
    }

    /** «Sperren …» — the question, in the device window (or as its own window from the ⋮). */
    protected function confirmRevokeAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $access  = ApiDeviceAccess::current();
        $device  = $this->apiDevices()->find((int) $request->getGetParameter('id'));
        if ($device === null || !$access->sees($device)) {
            return $this->apiDeviceRefuse('Gerät nicht gefunden');
        }
        $owner  = $this->apiDeviceUsers()[$device->getOwnerId()] ?? null;
        $reason = $this->apiDeviceRevokeRefusal($device, $owner, $access);

        $response = $this->html([
            'window'      => ListDefinition::isFetch($request),
            'origin'      => WindowOrigin::of($request),
            'device'      => $device,
            'ownerName'   => ApiDeviceAccess::ownerName($owner),
            'blockReason' => $reason,
            'entityCsrf'  => $reason === null ? DI::getCsrfService()->generateEntityToken('apiDeviceRevoke', (int) $device->getId()) : '',
            'actionBase'  => $this->apiDeviceBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmRevoke', 'Backend/ApiDeviceController', self::API_DEVICE_NS);

        return $response;
    }

    /**
     * Blocks the device: its next request is a 401 — final (E13). The rule is asked again here,
     * the menu and the confirm prove nothing. Fetched, the window shows the blocked device and
     * the list reloads.
     */
    #[Csrf]
    protected function revokeAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->getPostParameters();
        $id      = (int) ($post['id'] ?? 0);
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'apiDeviceRevoke', $id)) {
            return $this->apiDeviceRefuse('Das Fenster ist veraltet — bitte neu öffnen.');
        }
        $access  = ApiDeviceAccess::current();
        $service = $this->apiDevices();
        $device  = $service->find($id);
        if ($device === null || !$access->sees($device)) {
            return $this->apiDeviceRefuse('Gerät nicht gefunden');
        }
        $reason = $this->apiDeviceRevokeRefusal($device, $this->apiDeviceUsers()[$device->getOwnerId()] ?? null, $access);
        if ($reason !== null) {
            return $this->apiDeviceRefuse($reason);
        }
        $service->revoke($id);

        $flash = 'Gerät «' . $device->getLabel() . '» gesperrt';
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->apiDeviceBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('open-window', ['url' => $this->apiDeviceBase() . '/edit?id=' . $id, 'replace' => true])
            ->addCommand('refresh-region', ['name' => ApiDeviceListing::definition()->region()]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Null = the actor may block this device now. */
    private function apiDeviceRevokeRefusal(ApiDevice $device, ?BackendUser $owner, ApiDeviceAccess $access): ?string
    {
        if ($device->isRevoked()) {
            return 'Das Gerät ist bereits gesperrt.';
        }

        return $access->mayActFor($owner) ? null : 'Dieses Gerät darfst du nicht sperren — sein Besitzer hat eine höhere Rolle als du.';
    }

    /**
     * What one row shows — the single source for the list and any row answer.
     *
     * @param array<int, BackendUser> $users
     * @return array<string, mixed>
     */
    private function apiDeviceRowContext(ApiDevice $device, array $users, ApiDeviceScopes $scopes): array
    {
        $owner = $users[$device->getOwnerId()] ?? null;

        return [
            'device'      => $device,
            'ownerName'   => ApiDeviceAccess::ownerName($owner),
            'scopeLabels' => array_map(static fn(string $s): string => $scopes->label($s), $device->getScopes()),
        ];
    }

    /**
     * Code, ticket, QR and the words of the pairing window. The QR names the app shell's pairing
     * page on the installation's canonical origin — the origin the phone must use, since its
     * cookie is bound to it (falls back to the address this request came in on when no
     * `canonicalBaseUrl` is configured: a development box).
     *
     * @return array<string, mixed>
     */
    private function apiDevicePairingContext(string $code, string $ticket, int $expiresAt, int $now): array
    {
        $plain = str_replace('-', '', $code);
        $base  = ApiDevicePairing::origin(DI::getRequest());
        $url   = ApiDevicePairing::appUrl($base, $plain);

        return [
            'code'      => $code,
            'ticket'    => $ticket,
            'expiresAt' => $expiresAt,
            'now'       => $now,
            'url'       => $url,
            'appUrl'    => ApiDevicePairing::shortAppUrl($base),
            'qrSvg'     => ApiDevicePairing::qrSvg($url),
            'statusUrl' => $this->apiDeviceBase() . '/pair-status?ticket=' . $ticket,
            'every'     => self::API_DEVICE_PAIRING_POLL,
        ];
    }

    /** @return array<string, array{code: string, expires: int}> the open codes of this session */
    private function apiDeviceTickets(): array
    {
        $tickets = DI::getSessionManager()->get(self::API_DEVICE_PAIRING_SESSION);

        return is_array($tickets) ? $tickets : [];
    }

    /** Remembers a code for its window's status questions; drops the session's expired ones. */
    private function apiDeviceOpenTicket(string $code, int $expires): string
    {
        $now     = time();
        $tickets = array_filter($this->apiDeviceTickets(), static fn($t): bool => is_array($t) && (int) ($t['expires'] ?? 0) >= $now);
        $ticket  = bin2hex(random_bytes(16));
        $tickets[$ticket] = ['code' => $code, 'expires' => $expires];
        DI::getSessionManager()->set(self::API_DEVICE_PAIRING_SESSION, $tickets);

        return $ticket;
    }

    /** Forgets a ticket of this session and withdraws its code if it still waits. */
    private function apiDeviceWithdraw(string $ticket): void
    {
        $tickets = $this->apiDeviceTickets();
        if (!isset($tickets[$ticket])) {
            return;
        }
        $this->apiDevices()->cancelPairingCode((string) ($tickets[$ticket]['code'] ?? ''));
        unset($tickets[$ticket]);
        DI::getSessionManager()->set(self::API_DEVICE_PAIRING_SESSION, $tickets);
    }

    /** Close the window and reload the list region it came from; without the script: flash + list. */
    private function apiDeviceSaved(string $flash): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->apiDeviceBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        $response->addCommand('refresh-region', ['name' => str_starts_with($origin, 'region:') ? substr($origin, strlen('region:')) : ApiDeviceListing::definition()->region()]);

        return $response;
    }

    private function apiDeviceRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->apiDeviceBase() . '/list', 303);
    }
}
