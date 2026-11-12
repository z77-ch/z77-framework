<?php

namespace Z77\Module\Api\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Api\Entities\ApiAppUser,
    Z77\Module\Api\Entities\ApiDevice,
    Z77\Module\Api\Services\ApiAppUsers,
    Z77\Module\Api\Services\ApiDevices,
    Z77\Module\Api\Services\ApiDeviceScopes,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Entities\BackendUser,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › System › App-Benutzer (ADR-058, app-user plan step 5, board «App-Benutzer» approved
 * 2026-11-12) — who uses the z77 App, linked to which person, with which functions; replaces the
 * transitional «Geräte». Mounted by a thin host controller in the backend (ADR-018 pattern):
 * `use ApiAppUserControllerTrait` + a one-line layout config delegating to
 * {@see ApiAppUserLayout::config()}; URL `/backend/system/api-app-user/…`.
 *
 *   - `list` — a STANDARD LIST ({@see ApiAppUserListing}): tabs «Aktiv» · «Alle», the state icon
 *     opens the app-user WINDOW, the ⋮ the row's context menu. Action cell «+ App-Benutzer»;
 *   - `add` — the window of a NEW app user: first the person (a GET form that re-renders the
 *     window, `data-window-form="change"`), then the app e-mail — proposed from the person's
 *     backend e-mail — and the function switches;
 *   - `edit?id=` — the window of an existing one: person (fixed), app e-mail, functions, the
 *     devices with «Sperren …», «Deaktivieren …» / «Aktivieren …»;
 *   - `confirm-deactivate` / `deactivate`, `confirm-activate` / `activate` — the questions (in the
 *     window, or a window of their own from the ⋮) and the change itself;
 *   - `confirm-revoke?id=<device>` / `revoke` — block one device (final, E13).
 *
 * Permissions: ADMIN only (A9 — the host's role; the service refuses anyone else). The actor rule
 * (ADR-058 §4, {@see ApiDeviceAccess::mayActFor()}): an app user linked to a person above the actor
 * is listed but its window is READ-ONLY — the screen does not offer what the service would refuse.
 * Every write goes through {@see ApiAppUsers} / {@see ApiDevices}; the service's refusal lands on
 * the field it concerns ({@see ApiAppUserWindow::explain()}). No JavaScript of its own (Rule 7).
 *
 * The using class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`,
 * `redirect()`, `$layoutManager`, `$messageService`, `$help` — and {@see appUserRoleLabels()}.
 */
trait ApiAppUserControllerTrait
{
    private const API_APP_USER_NS = 'Z77\\Module\\Api';

    /** The window's mask (ADR-047) — the confirms load into the same window. */
    private const API_APP_USER_MASK = 'api-app-user';

    /**
     * German role labels, role => label — the host's one source (module-api owns none).
     *
     * @return array<string, string>
     */
    abstract protected function appUserRoleLabels(): array;

    /** URL root of THIS mount. */
    protected function apiAppUserBase(): string
    {
        return ApiAppUserLayout::BASE;
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = ApiAppUserListing::definition();
        $state      = $definition->readRequest($request);
        $users      = $this->appUserBackendUsers();
        $access     = ApiDeviceAccess::current();
        $all        = $this->appUserService()->all();
        $rows       = ApiAppUserListing::apply($all, $state, fn(ApiAppUser $u): string => ApiAppUserWindow::personName($u, $this->appUserPerson($u, $users)));
        $paging     = $state->paging(count($rows));
        $counts     = $this->appUserLiveDeviceCounts();

        $page = [];
        foreach (array_slice($rows, $paging->offset(), $paging->pageSize) as $appUser) {
            $page[] = $this->appUserRowContext($appUser, $users, $access, $counts);
        }

        $isFetch = ListDefinition::isFetch($request);
        if (!$isFetch) {
            // The list's help closes its toolbar (HELP-003) — for the page, not for a region load.
            $this->help->attach('Backend/ApiAppUserController/list.help', self::API_APP_USER_NS, [], 'Hilfe: App-Benutzer', toolbar: true);
        }
        $response = $this->html([
            'rows'       => $page,
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'actionBase' => $this->apiAppUserBase(),
        ]);
        // The fragment owns its header slots: the action cell and the view tabs. A FETCH of the
        // region wants the list alone (listing.md).
        if (!$isFetch) {
            $this->layoutManager->addPartials('act', 'Backend/ApiAppUserController', self::API_APP_USER_NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/ApiAppUserController', self::API_APP_USER_NS, 'hc2');
        }

        return $response;
    }

    /** The row's ⋮ — «Öffnen», and «Deaktivieren …» / «Aktivieren …» where the actor may. */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $appUser = $this->appUserService()->find((int) DI::getRequest()->getGetParameter('id'));
        if ($appUser === null) {
            return $this->fetchError('App-Benutzer nicht gefunden');
        }
        $rights   = $this->appUserRights($appUser, $this->appUserBackendUsers(), ApiDeviceAccess::current());
        $response = $this->html([
            'appUser'       => $appUser,
            'personName'    => $rights['personName'],
            'canDeactivate' => $rights['canAct'] && $appUser->isActive(),
            'canActivate'   => $rights['canEdit'] && !$appUser->isActive(),
            'region'        => ApiAppUserListing::definition()->region(),
            'actionBase'    => $this->apiAppUserBase(),
        ]);
        // Appended, not swapped: opened as a PAGE the kernel answers 303 to the list (MODAL-PAGE-001).
        $this->layoutManager->addPartials('actions', 'Backend/ApiAppUserController', self::API_APP_USER_NS);

        return $response;
    }

    // ── the window ───────────────────────────────────────────────────────

    /**
     * A NEW app user. GET `?linked_id=` (the person GET form) re-renders the window for that
     * person: the app e-mail proposed from his backend e-mail, the switches by his role. POST
     * creates through ApiAppUsers::create(); a refusal re-renders with the error on its field.
     */
    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $users   = $this->appUserBackendUsers();
        $access  = ApiDeviceAccess::current();
        $linked  = $this->appUserLinkedIds();
        $isPost  = $request->isPost();
        $post    = $isPost ? $request->getPostParameters() : [];
        $rawId   = $isPost ? ($post['linked_id'] ?? '') : $request->getGetParameter('linked_id');
        $id      = is_scalar($rawId) && preg_match('/^[1-9]\d{0,9}$/', (string) $rawId) === 1 ? (int) $rawId : 0;
        $person  = $users[$id] ?? null;
        $errors  = [];
        if ($id > 0 && !ApiAppUserWindow::choosable($person, $linked, $access)) {
            $errors['linked_id'] = $person === null ? 'Diese Person gibt es nicht (mehr).'
                : (isset($linked[(string) $id]) ? 'Diese Person hat schon einen App-Benutzer.' : 'Diese Person hat eine höhere Rolle als du.');
            $person = null;
        }

        $appUser = new ApiAppUser();
        $appUser->setLinkedRealm(ApiAppUser::REALM_BACKEND);
        $appUser->setLinkedId($person !== null ? (string) $person->getId() : '');
        $appUser->setEmail($person?->getEmail() ?? '');
        $tool     = $this->appUserWindowTool();
        $name     = ApiAppUserWindow::personName($appUser, $person);
        $actor    = $this->appUserActorRoles($users);
        $switches = $tool->functions([], ApiAppUser::REALM_BACKEND, $person, $name, $actor, $person !== null);

        if ($isPost) {
            $email = is_string($post['email'] ?? null) ? $post['email'] : '';
            $appUser->setEmail($email);
            $scopes = ApiAppUserWindow::keep($post['scopes'] ?? [], $switches);
            if ($person === null && $errors === []) {
                $errors['linked_id'] = 'Bitte eine Person wählen.';
            }
            if ($errors === []) {
                try {
                    $created = $this->appUserService()->create($access->actorId(), $email, ApiAppUser::REALM_BACKEND, (string) $person->getId(), $scopes);

                    return $this->appUserSaved('App-Benutzer für ' . ApiAppUserWindow::personName($created, $person) . ' angelegt');
                } catch (\RuntimeException|\InvalidArgumentException $e) {   // ApiAppUserDenied, bad data, the store's lock
                    $errors = $this->appUserErrors($e);
                }
            }
            $switches = $tool->functions($scopes, ApiAppUser::REALM_BACKEND, $person, $name, $actor, $person !== null);
        }

        return $this->appUserWindow($appUser, $person, $switches, $errors, [
            'personChoices' => $tool->personChoices($users, $linked, $access),
            'canEdit'       => true,
        ]);
    }

    /**
     * An existing app user. POST saves the app e-mail and the switches through
     * ApiAppUsers::update(); read-only when the actor may not act for the person (the service
     * would refuse anyway — the screen does not offer it).
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $appUser = $this->appUserService()->find((int) $request->getGetParameter('id'));
        if ($appUser === null) {
            return $this->appUserRefuse('App-Benutzer nicht gefunden');
        }
        $users    = $this->appUserBackendUsers();
        $rights   = $this->appUserRights($appUser, $users, ApiDeviceAccess::current());
        $person   = $rights['person'];
        $tool     = $this->appUserWindowTool();
        $actor    = $this->appUserActorRoles($users);
        $switches = $tool->functions($appUser->getScopes(), $appUser->getLinkedRealm(), $person, $rights['personName'], $actor, $rights['canEdit']);
        $errors   = [];

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!$rights['canEdit']) {
                return $this->appUserRefuse($rights['readOnly']);
            }
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'apiAppUser', (int) $appUser->getId())) {
                return $this->appUserRefuse('Das Fenster ist veraltet — bitte neu öffnen.');
            }
            $email  = is_string($post['email'] ?? null) ? $post['email'] : '';
            $scopes = ApiAppUserWindow::keep($post['scopes'] ?? [], $switches);
            try {
                $this->appUserService()->update(ApiDeviceAccess::current()->actorId(), (int) $appUser->getId(), $email, $scopes);

                return $this->appUserSaved('App-Benutzer ' . $rights['personName'] . ' gespeichert');
            } catch (\RuntimeException|\InvalidArgumentException $e) {   // ApiAppUserDenied, bad data, the store's lock
                $errors = $this->appUserErrors($e);
                // Show what was typed, not what is stored (the entity is reloaded on the next GET).
                $appUser = clone $appUser;
                $appUser->setEmail($email);
                $switches = $tool->functions($scopes, $appUser->getLinkedRealm(), $person, $rights['personName'], $actor, $rights['canEdit']);
            }
        }

        return $this->appUserWindow($appUser, $person, $switches, $errors, [
            'canEdit'       => $rights['canEdit'],
            'readOnly'      => $rights['readOnly'],
            'canDeactivate' => $rights['canAct'] && $appUser->isActive(),
            'canActivate'   => $rights['canEdit'] && !$appUser->isActive(),
            'devices'       => $this->appUserDeviceRows($appUser, $rights['canAct']),
            'facts'         => $this->appUserFacts($appUser, $users),
            'entityCsrf'    => $rights['canEdit'] ? DI::getCsrfService()->generateEntityToken('apiAppUser', (int) $appUser->getId()) : '',
        ]);
    }

    // ── deactivate / activate ────────────────────────────────────────────

    /** «Deaktivieren …» — the question: every device is signed out for good. */
    protected function confirmDeactivateAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->appUserConfirmState('deactivate');
    }

    /** «Aktivieren …» — the question: active again, every phone signs in anew by code. */
    protected function confirmActivateAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->appUserConfirmState('activate');
    }

    #[Csrf]
    protected function deactivateAction(): FetchResponse|RedirectResponse
    {
        return $this->appUserChangeState('deactivate');
    }

    #[Csrf]
    protected function activateAction(): FetchResponse|RedirectResponse
    {
        return $this->appUserChangeState('activate');
    }

    // ── block a device ───────────────────────────────────────────────────

    /** «Sperren …» of one device — the question, in the app user's window. */
    protected function confirmRevokeAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        [$device, $appUser] = $this->appUserDevice((int) $request->getGetParameter('id'));
        if ($device === null || $appUser === null) {
            return $this->appUserRefuse('Gerät nicht gefunden');
        }
        $rights = $this->appUserRights($appUser, $this->appUserBackendUsers(), ApiDeviceAccess::current());
        $reason = $device->isRevoked() ? 'Das Gerät ist bereits gesperrt.' : ($rights['canAct'] ? null : $rights['readOnly']);

        $response = $this->html([
            'window'      => ListDefinition::isFetch($request),
            'origin'      => WindowOrigin::of($request),
            'mask'        => self::API_APP_USER_MASK,
            'appUser'     => $appUser,
            'device'      => $device,
            'deviceWords' => ApiAppUserWindow::deviceWords($device),
            'personName'  => $rights['personName'],
            'blockReason' => $reason,
            'entityCsrf'  => $reason === null ? DI::getCsrfService()->generateEntityToken('apiDeviceRevoke', (int) $device->getId()) : '',
            'actionBase'  => $this->apiAppUserBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmRevoke', 'Backend/ApiAppUserController', self::API_APP_USER_NS);

        return $response;
    }

    /**
     * Blocks the device: its next request is a 401 — final (E13). The rule is asked again here,
     * the window and the confirm prove nothing. Fetched, the window shows the app user again.
     */
    #[Csrf]
    protected function revokeAction(): FetchResponse|RedirectResponse
    {
        $post = DI::getRequest()->getPostParameters();
        $id   = (int) ($post['id'] ?? 0);
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'apiDeviceRevoke', $id)) {
            return $this->appUserRefuse('Das Fenster ist veraltet — bitte neu öffnen.');
        }
        [$device, $appUser] = $this->appUserDevice($id);
        if ($device === null || $appUser === null) {
            return $this->appUserRefuse('Gerät nicht gefunden');
        }
        $rights = $this->appUserRights($appUser, $this->appUserBackendUsers(), ApiDeviceAccess::current());
        if (!$rights['canAct']) {
            return $this->appUserRefuse($rights['readOnly']);
        }
        if (!$this->appUserDevices()->revoke($id)) {
            return $this->appUserRefuse('Das Gerät ist bereits gesperrt.');
        }

        $words = ApiAppUserWindow::deviceWords($device);

        return $this->appUserShowAgain($appUser, $words['device'] . ' von ' . $rights['personName'] . ' gesperrt');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function appUserService(): ApiAppUsers
    {
        return new ApiAppUsers(DI::getUnifiedEntityManager(), $this->appUserScopes(), $this->appUserDevices());
    }

    private function appUserDevices(): ApiDevices
    {
        return new ApiDevices(DI::getUnifiedEntityManager(), $this->appUserScopes());
    }

    private function appUserScopes(): ApiDeviceScopes
    {
        return new ApiDeviceScopes(DI::getModuleManager());
    }

    private function appUserWindowTool(): ApiAppUserWindow
    {
        return new ApiAppUserWindow($this->appUserScopes(), $this->appUserRoleLabels());
    }

    /** @return array<int, BackendUser> id => user, as stored now */
    private function appUserBackendUsers(): array
    {
        $users = [];
        foreach (DI::getUnifiedEntityManager()->getRepository(BackendUser::class)->findAll() as $user) {
            if ($user instanceof BackendUser && $user->getId() !== null) {
                $users[(int) $user->getId()] = $user;
            }
        }

        return $users;
    }

    /**
     * The actor's roles FROM THE STORE (never the session's — the user list's rule).
     *
     * @param array<int, BackendUser> $users
     * @return list<string>
     */
    private function appUserActorRoles(array $users): array
    {
        return array_values(($users[ApiDeviceAccess::current()->actorId()] ?? null)?->getRoles() ?? []);
    }

    /** @param array<int, BackendUser> $users */
    private function appUserPerson(ApiAppUser $appUser, array $users): ?BackendUser
    {
        return $appUser->getLinkedRealm() === ApiAppUser::REALM_BACKEND && ctype_digit($appUser->getLinkedId())
            ? ($users[(int) $appUser->getLinkedId()] ?? null)
            : null;
    }

    /** @return array<string, true> the backend user ids that have an app user */
    private function appUserLinkedIds(): array
    {
        $ids = [];
        foreach ($this->appUserService()->all() as $appUser) {
            if ($appUser->getLinkedRealm() === ApiAppUser::REALM_BACKEND) {
                $ids[$appUser->getLinkedId()] = true;
            }
        }

        return $ids;
    }

    /**
     * What the actor may do with this app user — the single place the screen decides it:
     * `canAct` (the actor rule: deactivate, block a device), `canEdit` (also the person exists:
     * e-mail, functions, activate), and the sentence a read-only window shows.
     *
     * @param array<int, BackendUser> $users
     * @return array{person: ?BackendUser, personName: string, canAct: bool, canEdit: bool, readOnly: string}
     */
    private function appUserRights(ApiAppUser $appUser, array $users, ApiDeviceAccess $access): array
    {
        $person   = $this->appUserPerson($appUser, $users);
        $name     = ApiAppUserWindow::personName($appUser, $person);
        $canAct   = $access->governs() && $access->mayActFor($person);
        $canEdit  = $canAct && $person !== null;
        $readOnly = match (true) {
            !$access->governs() => 'Nur ein Admin verwaltet App-Benutzer.',
            !$canAct            => $name . ' hat eine höhere Rolle als du — du kannst diesen App-Benutzer ansehen, nicht ändern.',
            !$canEdit           => 'Die verknüpfte Person gibt es nicht mehr — der App-Benutzer lässt sich nicht mehr ändern.',
            default             => '',
        };

        return ['person' => $person, 'personName' => $name, 'canAct' => $canAct, 'canEdit' => $canEdit, 'readOnly' => $readOnly];
    }

    /** @return array<int, int> app user id => devices that can still sign in (not blocked, not expired) */
    private function appUserLiveDeviceCounts(): array
    {
        $counts = [];
        foreach ($this->appUserDevices()->listAll() as $device) {
            if ($device->getAppUserId() > 0 && ApiAppUserWindow::deviceState($device) === 'live') {
                $counts[$device->getAppUserId()] = ($counts[$device->getAppUserId()] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * What one row shows — the single source for the list and any row answer.
     *
     * @param array<int, BackendUser> $users
     * @param array<int, int> $counts
     * @return array<string, mixed>
     */
    private function appUserRowContext(ApiAppUser $appUser, array $users, ApiDeviceAccess $access, array $counts): array
    {
        $rights = $this->appUserRights($appUser, $users, $access);
        $scopes = $this->appUserScopes();
        $live   = $scopes->filter($appUser->getScopes(), $appUser->getLinkedRealm(), array_values($rights['person']?->getRoles() ?? []));

        return [
            'appUser'     => $appUser,
            'personName'  => $rights['personName'],
            'functions'   => array_map(static fn(string $s): array => ['label' => $scopes->label($s), 'live' => in_array($s, $live, true)], $appUser->getScopes()),
            'deviceCount' => $counts[(int) $appUser->getId()] ?? 0,
            'canAct'      => $rights['canAct'],
        ];
    }

    /** @return list<array<string, mixed>> the devices of the window, newest use first */
    private function appUserDeviceRows(ApiAppUser $appUser, bool $canAct): array
    {
        $rows = [];
        foreach ($this->appUserDevices()->listForAppUser((int) $appUser->getId()) as $device) {
            $state  = ApiAppUserWindow::deviceState($device);
            $rows[] = [
                'device'    => $device,
                'words'     => ApiAppUserWindow::deviceWords($device),
                'state'     => $state,
                'canRevoke' => $canAct && $state !== 'revoked',
            ];
        }

        return $rows;
    }

    /**
     * The muted facts line under the window: created, changed (by whom), last sign-in.
     *
     * @param array<int, BackendUser> $users
     * @return list<string>
     */
    private function appUserFacts(ApiAppUser $appUser, array $users): array
    {
        $by = static fn(int $id): string => $id === 0 ? 'System' : (($users[$id] ?? null)?->getUsername() ?? 'gelöscht');

        return array_values(array_filter([
            $appUser->getCreatedAt() !== '' ? 'angelegt ' . ApiAppUserListing::dateTime($appUser->getCreatedAt()) . ' von ' . $by($appUser->getCreatedBy()) : '',
            $appUser->getChangedAt() !== '' ? 'geändert ' . ApiAppUserListing::dateTime($appUser->getChangedAt()) . ' von ' . $by($appUser->getChangedBy()) : '',
            'zuletzt angemeldet ' . ApiAppUserListing::when($appUser->getLastLoginAt()),
        ]));
    }

    /**
     * Renders the window (ADR-047): fetched, a window with the mask and the record; else a page.
     *
     * @param list<array<string, mixed>> $switches
     * @param array<string, string>      $errors field ('' = the window) => message
     * @param array<string, mixed>       $extra
     */
    private function appUserWindow(ApiAppUser $appUser, ?BackendUser $person, array $switches, array $errors, array $extra): HtmlResponse
    {
        $request = DI::getRequest();
        $isNew   = $appUser->getId() === null;
        $this->help->attach('Backend/ApiAppUserController/edit.help', self::API_APP_USER_NS, [], 'Hilfe: App-Benutzer');
        $response = $this->html($extra + [
            'window'        => ListDefinition::isFetch($request),
            'origin'        => WindowOrigin::of($request),
            'mask'          => self::API_APP_USER_MASK,
            'isNew'         => $isNew,
            'appUser'       => $appUser,
            'person'        => $person,
            'personName'    => ApiAppUserWindow::personName($appUser, $person),
            'personRole'    => $person === null ? '' : $this->appUserWindowTool()->roleLabel(ApiAppUserWindow::highestRole($person->getRoles())),
            'switches'      => $switches,
            'errors'        => $errors,
            'personChoices' => [],
            'readOnly'      => '',
            'canDeactivate' => false,
            'canActivate'   => false,
            'devices'       => [],
            'facts'         => [],
            'entityCsrf'    => '',
            'actionBase'    => $this->apiAppUserBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/ApiAppUserController', self::API_APP_USER_NS);

        return $response;
    }

    /** The service's refusal as field errors; an unknown text is logged and shown as a general one. */
    private function appUserErrors(\Throwable $e): array
    {
        $explained = ApiAppUserWindow::explain($e);
        if (!$explained['known']) {
            error_log('ApiAppUserController: ' . get_class($e) . ': ' . $e->getMessage());
        }

        return [$explained['field'] => $explained['message']];
    }

    /** The question of «Deaktivieren …» / «Aktivieren …». */
    private function appUserConfirmState(string $mode): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $appUser = $this->appUserService()->find((int) $request->getGetParameter('id'));
        if ($appUser === null) {
            return $this->appUserRefuse('App-Benutzer nicht gefunden');
        }
        $rights = $this->appUserRights($appUser, $this->appUserBackendUsers(), ApiDeviceAccess::current());
        $reason = $this->appUserStateRefusal($mode, $appUser, $rights);

        $response = $this->html([
            'window'      => ListDefinition::isFetch($request),
            'origin'      => WindowOrigin::of($request),
            'mask'        => self::API_APP_USER_MASK,
            'mode'        => $mode,
            'appUser'     => $appUser,
            'personName'  => $rights['personName'],
            'deviceCount' => $this->appUserLiveDeviceCounts()[(int) $appUser->getId()] ?? 0,
            'blockReason' => $reason,
            'entityCsrf'  => $reason === null ? DI::getCsrfService()->generateEntityToken('apiAppUser.' . $mode, (int) $appUser->getId()) : '',
            'actionBase'  => $this->apiAppUserBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmState', 'Backend/ApiAppUserController', self::API_APP_USER_NS);

        return $response;
    }

    /** Deactivate / activate through the service; fetched, the window shows the app user again. */
    private function appUserChangeState(string $mode): FetchResponse|RedirectResponse
    {
        $post = DI::getRequest()->getPostParameters();
        $id   = (int) ($post['id'] ?? 0);
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'apiAppUser.' . $mode, $id)) {
            return $this->appUserRefuse('Das Fenster ist veraltet — bitte neu öffnen.');
        }
        $appUser = $this->appUserService()->find($id);
        if ($appUser === null) {
            return $this->appUserRefuse('App-Benutzer nicht gefunden');
        }
        $rights = $this->appUserRights($appUser, $this->appUserBackendUsers(), ApiDeviceAccess::current());
        $reason = $this->appUserStateRefusal($mode, $appUser, $rights);
        if ($reason !== null) {
            return $this->appUserRefuse($reason);
        }
        $live = $this->appUserLiveDeviceCounts()[$id] ?? 0;
        try {
            $mode === 'deactivate'
                ? $this->appUserService()->deactivate(ApiDeviceAccess::current()->actorId(), $id)
                : $this->appUserService()->reactivate(ApiDeviceAccess::current()->actorId(), $id);
        } catch (\RuntimeException|\InvalidArgumentException $e) {   // ApiAppUserDenied, bad data, the store's lock
            return $this->appUserRefuse(current($this->appUserErrors($e)));
        }

        return $this->appUserShowAgain($appUser, $mode === 'deactivate'
            ? 'App-Benutzer ' . $rights['personName'] . ' deaktiviert — ' . ($live === 1 ? '1 Gerät' : $live . ' Geräte') . ' abgemeldet'
            : 'App-Benutzer ' . $rights['personName'] . ' aktiviert — Anmeldung per Code auf dem Handy');
    }

    /**
     * Null = the actor may switch this app user now.
     *
     * @param array{canAct: bool, canEdit: bool, readOnly: string} $rights
     */
    private function appUserStateRefusal(string $mode, ApiAppUser $appUser, array $rights): ?string
    {
        if ($mode === 'deactivate') {
            return !$appUser->isActive() ? 'Der App-Benutzer ist bereits deaktiviert.' : ($rights['canAct'] ? null : $rights['readOnly']);
        }

        return $appUser->isActive() ? 'Der App-Benutzer ist bereits aktiv.' : ($rights['canEdit'] ? null : $rights['readOnly']);
    }

    /** @return array{0: ?ApiDevice, 1: ?ApiAppUser} the device and the app user it belongs to */
    private function appUserDevice(int $deviceId): array
    {
        $device = $deviceId > 0 ? $this->appUserDevices()->find($deviceId) : null;
        if ($device === null || $device->getAppUserId() <= 0) {
            return [null, null];
        }

        return [$device, $this->appUserService()->find($device->getAppUserId())];
    }

    /** After a state change or a block: the app user's window again, the list reloaded. */
    private function appUserShowAgain(ApiAppUser $appUser, string $flash): FetchResponse|RedirectResponse
    {
        if (!ListDefinition::isFetch(DI::getRequest())) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->apiAppUserBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('open-window', ['url' => $this->apiAppUserBase() . '/edit?id=' . (int) $appUser->getId(), 'replace' => true])
            ->addCommand('refresh-region', ['name' => ApiAppUserListing::definition()->region()]);
    }

    /** Close the window and reload the list region it came from; without the script: flash + list. */
    private function appUserSaved(string $flash): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->apiAppUserBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $origin = WindowOrigin::of($request);

        return $this->fetch()->setStatus('success')->addCommand('close-window')
            ->addCommand('refresh-region', ['name' => str_starts_with($origin, 'region:') ? substr($origin, strlen('region:')) : ApiAppUserListing::definition()->region()]);
    }

    private function appUserRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->apiAppUserBase() . '/list', 303);
    }
}
