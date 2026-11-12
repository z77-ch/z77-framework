<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\DI,
    Z77\Core\Config\AuthRole,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Backend\PasswordReset\PasswordResetTokens,
    Z77\Module\Backend\TwoFactor\BackendTwoFactor,
    Z77\Module\Backend\TwoFactor\TotpCheck,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Persistence\File\Storage\FileStorage,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Auth\AccountRemovedListeners,
    Z77\Shared\Auth\AuthUser,
    Z77\Shared\Auth\PasswordPolicy,
    Z77\Shared\Auth\Totp,
    Z77\Shared\Entities\BackendUser,
    Z77\Shared\Mail\SenderIdentity,
    Z77\Shared\Qr\QrCode,
    Z77\Shared\Repositories\BackendUserRepository,
    Z77\Shared\Validators\BackendUserValidator
;

/**
 * Manages {@see BackendUser} accounts (backend user administration). Flat list —
 * no hierarchy, no tree logic. `sortKey` gives a manual order (drag & drop via
 * {@see moveAction}); the modal add/edit/delete flow mirrors the navigation
 * controllers (fetch + entity-CSRF), without the tree machinery.
 *
 * URL: /backend/system/backend-user/{action}.
 */
class BackendUserController extends BackendAbstractController
{
    /** Its own context: a delete or edit token of the row must not open the reset. */
    private const TOTP_RESET_CONTEXT = 'backendUserTotpReset';

    private const ABOVE_ACTOR = 'Dieses Konto hat eine höhere Rolle als du.';

    private const LAST_SUPER_USER = 'Es muss mindestens ein Super-User erhalten bleiben.';

    /** A lock of its own beside the user store, see keepingASuperUser(). */
    private const SUPER_USER_LOCK = 'framework/auth/backendUsers.super-user';

    /**
     * German display labels — PRESENTATION ONLY. This map does NOT define which
     * roles exist or their order: the role SET + order are derived from
     * {@see AuthRole::getRoleHierarchy()} (the engine's single source of truth) by
     * {@see roleLabels()}. A label here is used only for a role that actually
     * exists in core; a stale entry for a removed role is simply never read.
     */
    private const ROLE_LABELS = [
        AuthRole::SUPER_USER => 'Super-User',
        AuthRole::ADMIN      => 'Admin',
        AuthRole::EDITOR     => 'Redaktor',
        AuthRole::CRON_JOB   => 'Cron-Job',
        AuthRole::MEMBER     => 'Mitglied',
        AuthRole::VISITOR    => 'Besucher',
        AuthRole::GUEST      => 'Gast',
    ];

    /**
     * Roles offered in the edit form, highest first. The role set + order come
     * from {@see AuthRole::getRoleHierarchy()} (SSOT) — this controller never
     * defines which roles exist. {@see ROLE_LABELS} supplies the German text per
     * existing role; an unlabeled (newly added) role falls back to its key.
     * Public and static: the screen «App-Benutzer» (module-api, mounted by
     * {@see ApiAppUserController}) names roles with the same words.
     *
     * @return array<string,string> role key => display label, highest role first
     */
    public static function roleLabels(): array
    {
        $hierarchy = AuthRole::getRoleHierarchy();
        arsort($hierarchy); // highest weight first

        $labels = [];
        foreach (array_keys($hierarchy) as $role) {
            $labels[$role] = self::ROLE_LABELS[$role] ?? $role;
        }
        return $labels;
    }

    private function repo(): BackendUserRepository
    {
        return $this->em()->getRepository(BackendUser::class);
    }

    /** @return BackendUser[] ordered by sortKey, then id as a stable tie-breaker. */
    private function sortedUsers(): array
    {
        $users = $this->repo()->findAll();
        usort($users, fn(BackendUser $a, BackendUser $b) => [$a->getSortKey(), $a->getId()] <=> [$b->getSortKey(), $b->getId()]);
        return $users;
    }

    private function nextSortKey(): int
    {
        $max = -1;
        foreach ($this->repo()->findAll() as $u) {
            $max = max($max, $u->getSortKey());
        }
        return $max + 1;
    }

    protected function listAction(): HtmlResponse
    {
        $response = $this->html([
            'authUser'   => DI::getAuthService()->getCurrentUser(),
            'users'      => $this->sortedUsers(),
            'roleLabels' => $this->roleLabels(),
        ]);

        $this->layoutManager->addJs('backend-user/list', self::NAMESPACE);

        return $response;
    }

    protected function addAction(): HtmlResponse|FetchResponse
    {
        return $this->edit(new BackendUser());
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id   = (int)DI::getRequest()->getGetParameter('id');
        $user = $id ? $this->repo()->find($id) : null;

        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }
        if ($this->isAboveActor($user)) {
            return $this->fetchError(self::ABOVE_ACTOR);
        }

        return $this->edit($user);
    }

    private function edit(BackendUser $user): HtmlResponse|FetchResponse
    {
        $isNew     = $user->getId() === null;
        $validator = null;
        $tier      = $this->passwordTier();

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'backendUser', $user->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $password        = trim($body['password'] ?? '');
            $originalHash     = $user->getPasswordHash();
            $originalSortKey  = $user->getSortKey();
            $originalWeak     = $user->isPasswordWeak();
            $originalEmail    = $user->getEmail();
            $originalRoles    = $user->getRoles();
            $postedRoles      = array_values(array_intersect(
                array_map('strval', (array)($body['roles'] ?? [])),
                array_keys(AuthRole::getRoleHierarchy())
            ));

            $refusal = $this->rolesRefusal($user, $postedRoles);
            if ($refusal !== null) {
                return $this->fetchError($refusal);
            }

            $user->mapFromArray(BodyCleaner::cleanFor(BackendUser::class, $body));

            // Server-controlled / specially handled fields — never trust the body.
            // sortKey: preserve on edit, assigned via nextSortKey on add (after validation).
            $user->setSortKey($isNew ? 0 : $originalSortKey);
            // roles: only known AuthRole keys from the posted checkbox set, judged above.
            $user->setRoles($postedRoles);
            $rolesChanged = !$isNew && !$this->sameRoles($originalRoles, $postedRoles);
            // sender identities (owner 2026-10-23): rebuilt from the form rows — the parallel maps
            // identity_name[k] / identity_email[k], in posted order, empty rows dropped. The
            // body's own `sender_identities` key is never trusted; the validator judges the rows.
            $user->setSenderIdentities(SenderIdentity::listFromForm($body['identity_name'] ?? [], $body['identity_email'] ?? []));
            // passwordHash + passwordWeak: keep current unless a new plaintext
            // password was supplied; a new one is (re-)evaluated against the policy
            // (allowed even if weak — drives the every-login nag, never blocks).
            if ($password === '') {
                $user->setPasswordHash($originalHash);
                $user->setPasswordWeak($originalWeak);
            } else {
                $user->setPasswordWeak(PasswordPolicy::isWeak($password, [$user->getUsername()], $tier));
            }

            $validator = new BackendUserValidator($user, $this->repo(), $password, $isNew, $user->getId(), $tier);
            if ($validator->isValid()) {
                if ($password !== '') {
                    $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
                }
                if ($isNew) {
                    $user->setSortKey($this->nextSortKey());
                    $user->seedAuthEpoch(time()); // a reused id must not fit a deleted user's session
                } elseif ($password !== '' || $rolesChanged) {
                    // His sessions end: the password was their proof, and a session carries the
                    // roles it started with (login.md LOGIN-RESET-001, BACKEND-2FA-001).
                    $user->bumpAuthEpoch();
                }

                $saved = $this->keepingASuperUser($user, $user->getRoles(), function () use ($user): void {
                    $this->em()->persist($user);
                    $this->em()->flush();
                });
                if (!$saved) {
                    return $this->fetchError(self::LAST_SUPER_USER);
                }

                if (!$isNew) {
                    $this->afterAccountSave($user, $password !== '', $rolesChanged, $user->getEmail() !== $originalEmail);
                }

                // In place (ADR-047 addendum 2026-10-10): the row rendered by the list's own partial —
                // appended for a new user (the list orders by sortKey, a new one gets the last),
                // replaced for an edit. core.js wires its ⋮ (FETCH-ROW-001); the drag & drop listens
                // on the list body, so the new row takes part without re-binding.
                $html = (new TemplateRenderer(self::NAMESPACE))->partial('System/BackendUserController/_row', [
                    'user'       => $user,
                    'isSelf'     => $user->getId() === DI::getAuthService()->getCurrentUser()->getId(),
                    'roleLabels' => $this->roleLabels(),
                ]);
                $this->messageService->pushFlash('success', 'Benutzer «' . $user->getUsername() . '» ' . ($isNew ? 'angelegt' : 'gespeichert'));
                $response = $this->fetch()->setStatus('success')->setData(['id' => $user->getId()]);
                if ($isNew) {
                    $response->insertRow('backend-user', $html);
                } else {
                    $response->replaceRow('backend-user', $user->getId(), $html);
                }
                return $response->addCommand('close-modal');
            }
            // validation failed — fall through to re-render the form with errors
        }

        $entityCsrf = !$isNew ? DI::getCsrfService()->generateEntityToken('backendUser', $user->getId()) : '';

        $response = $this->html([
            'entry'             => $user,
            'entityCsrf'        => $entityCsrf,
            'roleLabels'        => array_filter($this->roleLabels(), [$this, 'mayGrant'], ARRAY_FILTER_USE_KEY),
            'validator'         => $validator ?? new BackendUserValidator($user),
            'passwordMinLength' => $tier->minLength(),
            'isSelf'            => !$isNew && $user->getId() === DI::getAuthService()->getCurrentUser()->getId(),
        ]);
        $this->layoutManager->addPartials('edit', 'System/BackendUserController', self::NAMESPACE);
        // Live password-strength meter (hint only; server policy is authority on save).
        $response->addCommand('load-script', [
            'src'   => $this->layoutManager->resolveJsPath('password-meter', self::NAMESPACE),
            'init'  => 'password-meter',
            'scope' => '[data-z77-popup-body]',
        ]);
        // Show-password toggle on the password field (lazy, popup-scoped).
        $response->addCommand('load-script', [
            'src'   => $this->layoutManager->resolveJsPath('password-toggle', self::NAMESPACE),
            'init'  => 'password-toggle',
            'scope' => '[data-z77-popup-body]',
        ]);
        return $response;
    }

    /**
     * A new password or new roles keep the session of the user who changed his own (the stamp was
     * just raised). A new password or a new recovery address also ends an open reset link: the link
     * went to the old address, or would overwrite what the admin just set.
     */
    private function afterAccountSave(BackendUser $user, bool $passwordChanged, bool $rolesChanged, bool $emailChanged): void
    {
        if ($passwordChanged || $rolesChanged) {
            DI::getAuthService()->renewSessionStamp($user);
        }
        if ($passwordChanged || $emailChanged) {
            try {
                (new PasswordResetTokens())->revokeFor((int) $user->getId());
            } catch (\RuntimeException $e) {
                error_log('BackendUserController: open reset link not revoked — ' . $e->getMessage());
            }
        }
    }

    /**
     * The acting user's roles, from the STORE: a session carries the roles it started with. Empty
     * (no backend user behind this session) grants nothing.
     *
     * @return string[]
     */
    private function actorRoles(): array
    {
        return DI::getCurrentUserService()->getBackendUser()?->getRoles() ?? [];
    }

    private function mayGrant(string $role): bool
    {
        $actor = $this->actorRoles();

        return $actor !== [] && AuthRole::rolesSatisfy($actor, $role);
    }

    /** An account that holds a role above the actor's level is out of his reach (owner 2026-11-01). */
    private function isAboveActor(BackendUser $target): bool
    {
        foreach ($target->getRoles() as $role) {
            if (!$this->mayGrant($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * May these roles be saved on this account? Nobody grants above his own level; the form hides
     * those roles, this is the gate. And the last SUPER_USER stays one: with the rule above nobody
     * could grant the role again, the way out would be a hand edit on the server. An installation
     * without any SUPER_USER is not stopped from anything here.
     *
     * @param string[] $roles
     */
    private function rolesRefusal(BackendUser $target, array $roles): ?string
    {
        foreach ($roles as $role) {
            if (!$this->mayGrant($role)) {
                return 'Diese Rolle darfst du nicht vergeben, sie liegt über deiner eigenen.';
            }
        }

        return $this->leavesNoSuperUser($target, $roles) ? self::LAST_SUPER_USER : null;
    }

    /** Would these roles take away the last SUPER_USER? Asks the store as it is now. */
    private function leavesNoSuperUser(BackendUser $target, array $rolesAfter): bool
    {
        if ($target->getId() === null || AuthRole::rolesSatisfy($rolesAfter, AuthRole::SUPER_USER)) {
            return false;
        }

        $stored = $this->repo()->find((int) $target->getId());
        if (!$stored instanceof BackendUser || !AuthRole::rolesSatisfy($stored->getRoles(), AuthRole::SUPER_USER)) {
            return false;
        }

        foreach ($this->repo()->findAll() as $other) {
            if ($other->getId() !== $target->getId() && AuthRole::rolesSatisfy($other->getRoles(), AuthRole::SUPER_USER)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs $write with the last-SUPER_USER rule checked in one lock with it: otherwise two
     * SUPER_USERs demoting each other both find the other one still there, and none is left. False
     * = refused, nothing written. Its own lock: the store's is taken by the flush inside.
     *
     * @param string[] $rolesAfter the target's roles once written; [] for a delete
     */
    private function keepingASuperUser(BackendUser $target, array $rolesAfter, callable $write): bool
    {
        return (new FileStorage())->withExclusiveLock(self::SUPER_USER_LOCK, function () use ($target, $rolesAfter, $write): bool {
            if ($this->leavesNoSuperUser($target, $rolesAfter)) {
                return false;
            }
            $write();

            return true;
        });
    }

    /** @param string[] $a @param string[] $b */
    private function sameRoles(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * The dialog for the signed-in user's OWN second factor (login.md BACKEND-2FA-001): the user
     * form's section and the avatar panel open it. Takes no id: the account is the session's.
     */
    protected function twoFactorAction(): HtmlResponse|FetchResponse
    {
        $user = DI::getCurrentUserService()->getBackendUser();
        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }

        $context = ['active' => $user->hasTotp(), 'activatedAt' => $user->getTotpActivatedAt()];
        if (!$user->hasTotp()) {
            try {
                $secret = BackendTwoFactor::create()->enrolmentSecret($user);
            } catch (\RuntimeException $e) {
                error_log('BackendUserController: 2FA dialog refused — ' . $e->getMessage());
                return $this->fetchError(TotpCheck::Unavailable->refusal());
            }
            // The name in the app: which installation this code belongs to.
            $issuer  = self::installationLabel();
            $uri     = Totp::otpauthUri($issuer !== '' ? $issuer : 'z77 Backend', $user->getUsername(), $secret);
            $context += [
                'qrSvg'  => (string) preg_replace('/^<\?xml[^>]*>\s*/', '', QrCode::svg($uri, 220)),
                'secret' => trim(chunk_split($secret, 4, ' ')),
            ];
        }

        $response = $this->html($context);
        $this->layoutManager->addPartials('twoFactor', 'System/BackendUserController', self::NAMESPACE);
        return $response;
    }

    /**
     * Switching on: the current password AND a valid code. The password goes through the login
     * throttle, otherwise a stolen session could guess it here without limit. A wrong field answers
     * in place, the QR stays.
     */
    #[Fetch, HttpMethod('POST')]
    protected function twoFactorEnableAction(): FetchResponse
    {
        $user = DI::getCurrentUserService()->getBackendUser();
        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }
        if ($user->hasTotp()) {
            return $this->fetchError('Der Zwei-Faktor-Schutz ist bereits aktiv.');
        }

        $body     = DI::getRequest()->getJsonBody();
        $password = is_string($body['tf_password'] ?? null) ? $body['tf_password'] : '';
        $code     = is_string($body['tf_code'] ?? null) ? $body['tf_code'] : '';

        $throttle = $this->loginThrottle();
        $ip       = DI::getRequest()->getClientIp();
        if (!$throttle->tryAttempt($user->getUsername(), $ip)) {
            return $this->fetch()->setStatus('error')->setField('tf_password', false, 'Zu viele Fehlversuche, bitte in 15 Minuten erneut versuchen.');
        }
        if (!password_verify($password, $user->getPasswordHash())) {
            return $this->fetch()->setStatus('error')->setField('tf_password', false, 'Das Passwort stimmt nicht.');
        }
        $throttle->succeeded($user->getUsername(), $ip);

        $twoFactor = BackendTwoFactor::create();
        if (!$twoFactor->canCheckCodes($user)) {
            return $this->fetch()->setStatus('error')->setField('tf_code', false, TotpCheck::Unavailable->refusal());
        }
        if (!$twoFactor->confirmEnrolment($user, $code)) {
            return $this->fetch()->setStatus('error')->setField('tf_code', false, 'Der Code ist falsch, oder der Dialog ist zu alt. Bitte öffne ihn neu und scanne den neuen QR-Code.');
        }

        // Switching on ended his other sessions, the owner's decision: this one stays.
        $fresh = $this->repo()->find((int) $user->getId());
        if ($fresh instanceof BackendUser) {
            DI::getAuthService()->renewSessionStamp($fresh);
        }

        $this->messageService->pushFlash('success', 'Zwei-Faktor-Schutz ist aktiv. Ab jetzt fragt die Anmeldung nach dem Code.');
        return $this->fetch()->setStatus('success')->addCommand('close-modal');
    }

    /** Switching off: a valid code. A wrong one counts towards the lock of the login. */
    #[Fetch, HttpMethod('POST')]
    protected function twoFactorDisableAction(): FetchResponse
    {
        $user = DI::getCurrentUserService()->getBackendUser();
        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }
        if (!$user->hasTotp()) {
            return $this->fetchError('Der Zwei-Faktor-Schutz ist nicht aktiv.');
        }

        $code = DI::getRequest()->getJsonBody()['tf_code'] ?? '';

        $check = BackendTwoFactor::create()->disable($user, is_string($code) ? $code : '');

        return $check === TotpCheck::Passed
            ? $this->twoFactorDisabled()
            : $this->fetch()->setStatus('error')->setField('tf_code', false, $check->refusal());
    }

    private function twoFactorDisabled(): FetchResponse
    {
        $this->messageService->pushFlash('success', 'Zwei-Faktor-Schutz entfernt.');
        return $this->fetch()->setStatus('success')->addCommand('close-modal');
    }

    /** The confirm of «2FA zurücksetzen»; refused with the reason in the same dialog. */
    protected function confirmTwoFactorResetAction(): HtmlResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $user = $id ? $this->repo()->find($id) : null;

        $response = $this->html([
            'entry'       => $user,
            'entityCsrf'  => $user ? DI::getCsrfService()->generateEntityToken(self::TOTP_RESET_CONTEXT, $id) : '',
            'blockReason' => $user ? $this->totpResetRefusal($user) : 'Benutzer nicht gefunden',
        ]);
        $this->layoutManager->addPartials('confirmTwoFactorReset', 'System/BackendUserController', self::NAMESPACE);
        return $response;
    }

    /** The lost device, row ⋮: the rule is checked again here, the menu proves nothing. */
    #[Fetch, HttpMethod('POST')]
    protected function twoFactorResetAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        $csrf = is_string($body['entity_csrf'] ?? null) ? trim($body['entity_csrf']) : '';
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken($csrf, self::TOTP_RESET_CONTEXT, $id)) {
            return $this->fetchError('Invalid token');
        }

        $user = $this->repo()->find($id);
        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }
        $reason = $this->totpResetRefusal($user);
        if ($reason !== null) {
            return $this->fetchError($reason);
        }

        if (!BackendTwoFactor::create()->resetByAdmin($user, (int) DI::getAuthService()->getCurrentUser()->getId())) {
            return $this->fetchError('Zurücksetzen fehlgeschlagen, es hat sich nichts geändert. Details im Log.');
        }

        $this->messageService->pushFlash('success', 'Zwei-Faktor-Schutz für «' . $user->getUsername() . '» zurückgesetzt.');
        return $this->fetch()->setStatus('success')->addCommand('close-modal');
    }

    /**
     * Who may reset whose 2FA: the governor, for someone else, who has it. Null = allowed. The menu
     * asks the same question, so what it offers and what the action accepts cannot differ. Own
     * account: the code is the way out; the last admin who lost his device is a hand job on the
     * server (login.md «lost device»).
     */
    private function totpResetRefusal(BackendUser $target): ?string
    {
        $actor = DI::getAuthService()->getCurrentUser();
        // From the store: a demoted session must not keep this, whatever else let it through.
        if (!$this->mayGrant(AuthRole::SUPER_USER)) {
            return 'Das darf nur ein Super-User.';
        }
        if ($target->getId() === $actor->getId()) {
            return 'Dein eigenes Konto kannst du hier nicht zurücksetzen.';
        }
        if (!$target->hasTotp()) {
            return 'Für dieses Konto ist kein Zwei-Faktor-Schutz aktiv.';
        }

        return null;
    }

    protected function confirmDeleteAction(): HtmlResponse
    {
        $id   = (int)DI::getRequest()->getGetParameter('id');
        $user = $id ? $this->repo()->find($id) : null;

        $entityCsrf = $user ? DI::getCsrfService()->generateEntityToken('backendUser', $id) : '';

        $response = $this->html([
            'entry'       => $user,
            'entityCsrf'  => $entityCsrf,
            'blockReason' => $user ? $this->deleteBlockReason($user) : 'Benutzer nicht gefunden',
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'System/BackendUserController', self::NAMESPACE);
        return $response;
    }

    /** Per-row action hub (the list row's ⋮): edit + delete. Mirrors the DMS drive actions hub. */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $id   = (int)DI::getRequest()->getGetParameter('id');
        $user = $id ? $this->repo()->find($id) : null;
        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }

        $response = $this->html([
            'entry'             => $user,
            'canManage'         => !$this->isAboveActor($user),
            'canResetTwoFactor' => $this->totpResetRefusal($user) === null,
        ]);
        $this->layoutManager->addPartials('actions', 'System/BackendUserController', self::NAMESPACE);
        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = !empty($body['id']) ? (int)$body['id'] : null;

        if (!$id) {
            return $this->fetchError('Missing id');
        }

        $entityCsrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($entityCsrf, 'backendUser', $id)) {
            return $this->fetchError('Invalid token');
        }

        $user = $this->repo()->find($id);
        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }

        $reason = $this->deleteBlockReason($user);
        if ($reason !== null) {
            return $this->fetchError($reason);
        }

        $removed = $this->keepingASuperUser($user, [], function () use ($user): void {
            $this->em()->remove($user);
            $this->em()->flush();
        });
        if (!$removed) {
            return $this->fetchError(self::LAST_SUPER_USER);
        }

        // What hangs on the id goes with the user. The store hands out no deleted id, but a row
        // removed by hand leaves no mark, so a later user could still get this one.
        try {
            (new PasswordResetTokens())->revokeFor($id);
        } catch (\RuntimeException $e) {
            error_log('BackendUserController: open reset link of a deleted user not revoked — ' . $e->getMessage());
        }
        BackendTwoFactor::create()->forgetAccount($id);
        // And what other modules hold for it. By the removed id, not the session removing it.
        AccountRemovedListeners::announce(new AuthUser(['id' => $id, 'realm' => AuthUser::REALM_BACKEND]));

        return $this->fetch()
            ->setStatus('success')
            ->removeRow('backend-user', $id)
            ->addCommand('close-modal');
    }

    /**
     * Guards against locking everyone out: the currently logged-in user may not
     * delete their own account, and at least one admin-capable account must
     * remain. "Admin-capable" is defined by role LEVEL (>= {@see AuthRole::ADMIN}
     * in the hierarchy), NOT by the literal `admin` role or a username — a
     * `superUser` outranks an admin and counts here too.
     * Returns the reason string when deletion is blocked, null when allowed.
     */
    private function deleteBlockReason(BackendUser $user): ?string
    {
        if ($this->isAboveActor($user)) {
            return self::ABOVE_ACTOR;
        }

        if ($user->getId() === DI::getAuthService()->getCurrentUser()->getId()) {
            return 'Du kannst dein eigenes Konto nicht löschen.';
        }

        if ($this->isAdminCapable($user)) {
            $remaining = count(array_filter(
                $this->repo()->findAll(),
                fn(BackendUser $u) => $u->getId() !== $user->getId() && $this->isAdminCapable($u)
            ));
            if ($remaining === 0) {
                return 'Es muss mindestens ein Benutzer mit Admin-Rechten erhalten bleiben.';
            }
        }

        return null;
    }

    /**
     * True when the user holds a role at or above {@see AuthRole::ADMIN} in the
     * hierarchy — i.e. can administer the system (admin, superUser, …). Mirrors
     * the role-level logic in {@see AuthService::hasSufficientRole}; the literal
     * `admin` role string is not required.
     */
    private function isAdminCapable(BackendUser $user): bool
    {
        return AuthRole::rolesSatisfy($user->getRoles(), AuthRole::ADMIN);
    }

    #[Fetch, HttpMethod('POST')]
    protected function checkFieldAction(): FetchResponse
    {
        $body  = DI::getRequest()->getJsonBody();
        $field = (string)($body['field'] ?? '');
        if ($field === '') {
            return $this->fetch();
        }

        $cleaned = BodyCleaner::cleanFor(BackendUser::class, [$field => $body['value'] ?? '']);
        $entity  = new BackendUser($cleaned);

        // Format-only live check (no repo): the blur channel sends just {field,value}
        // — no id — so uniqueness, which must exclude the edited user, is enforced
        // on full submit (where the loaded entity carries its id) to avoid a false
        // "username taken" against the user's own name.
        $validator = new BackendUserValidator($entity);
        $validator->isValid([$field]);

        $response = $this->fetch();
        if ($validator->hasFieldError($field)) {
            $response
                ->setStatus('error')
                ->setField($field, false, $validator->getFieldError($field));
        }
        return $response;
    }

    /**
     * Flat reorder: move the user with `entry_id` to the 0-based `new_index`
     * among all users, then renumber sortKey densely. Only changed rows persist.
     */
    #[Fetch, HttpMethod('POST')]
    protected function moveAction(): FetchResponse
    {
        $body     = DI::getRequest()->getJsonBody();
        $entryId  = (int)($body['entry_id'] ?? 0);
        $newIndex = max(0, (int)($body['new_index'] ?? 0));

        if ($entryId <= 0) {
            return $this->fetchError('Missing entry_id');
        }

        $users = $this->sortedUsers();
        $moved = null;
        foreach ($users as $u) {
            if ($u->getId() === $entryId) {
                $moved = $u;
                break;
            }
        }
        if ($moved === null) {
            return $this->fetchError('Benutzer nicht gefunden.');
        }

        $rest     = array_values(array_filter($users, fn(BackendUser $u) => $u->getId() !== $entryId));
        $newIndex = min($newIndex, count($rest));
        array_splice($rest, $newIndex, 0, [$moved]);

        $em = $this->em();
        foreach ($rest as $pos => $u) {
            if ($u->getSortKey() !== $pos) {
                $u->setSortKey($pos);
                $em->persist($u);
            }
        }
        $em->flush();

        return $this->fetch()->setStatus('success');
    }
}
