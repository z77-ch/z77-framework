<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\DI,
    Z77\Core\Config\AuthRole,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Auth\PasswordPolicy,
    Z77\Shared\Entities\LoginUser,
    Z77\Shared\Repositories\LoginUserRepository,
    Z77\Shared\Validators\LoginUserValidator
;

/**
 * Manages {@see LoginUser} accounts (backend user administration). Flat list —
 * no hierarchy, no tree logic. `sortKey` gives a manual order (drag & drop via
 * {@see moveAction}); the modal add/edit/delete flow mirrors the navigation
 * controllers (fetch + entity-CSRF), without the tree machinery.
 *
 * URL: /backend/system/login-user/{action}.
 */
class LoginUserController extends BackendAbstractController
{
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
     *
     * @return array<string,string> role key => display label, highest role first
     */
    private function roleLabels(): array
    {
        $hierarchy = AuthRole::getRoleHierarchy();
        arsort($hierarchy); // highest weight first

        $labels = [];
        foreach (array_keys($hierarchy) as $role) {
            $labels[$role] = self::ROLE_LABELS[$role] ?? $role;
        }
        return $labels;
    }

    private function repo(): LoginUserRepository
    {
        return $this->em()->getRepository(LoginUser::class);
    }

    /** @return LoginUser[] ordered by sortKey, then id as a stable tie-breaker. */
    private function sortedUsers(): array
    {
        $users = $this->repo()->findAll();
        usort($users, fn(LoginUser $a, LoginUser $b) => [$a->getSortKey(), $a->getId()] <=> [$b->getSortKey(), $b->getId()]);
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

        $this->layoutManager->addJs('login-user/list', self::NAMESPACE);

        return $response;
    }

    protected function addAction(): HtmlResponse|FetchResponse
    {
        return $this->edit(new LoginUser());
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id   = (int)DI::getRequest()->getGetParameter('id');
        $user = $id ? $this->repo()->find($id) : null;

        if ($user === null) {
            return $this->fetchError('Benutzer nicht gefunden');
        }

        return $this->edit($user);
    }

    private function edit(LoginUser $user): HtmlResponse|FetchResponse
    {
        $isNew     = $user->getId() === null;
        $validator = null;
        $tier      = $this->passwordTier();

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'loginUser', $user->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $password        = trim($body['password'] ?? '');
            $originalHash     = $user->getPasswordHash();
            $originalSortKey  = $user->getSortKey();
            $originalWeak     = $user->isPasswordWeak();

            $user->mapFromArray(BodyCleaner::cleanFor(LoginUser::class, $body));

            // Server-controlled / specially handled fields — never trust the body.
            // sortKey: preserve on edit, assigned via nextSortKey on add (after validation).
            $user->setSortKey($isNew ? 0 : $originalSortKey);
            // roles: keep only known AuthRole keys from the posted checkbox set.
            $user->setRoles(array_values(array_intersect(
                array_map('strval', (array)($body['roles'] ?? [])),
                array_keys(AuthRole::getRoleHierarchy())
            )));
            // passwordHash + passwordWeak: keep current unless a new plaintext
            // password was supplied; a new one is (re-)evaluated against the policy
            // (allowed even if weak — drives the every-login nag, never blocks).
            if ($password === '') {
                $user->setPasswordHash($originalHash);
                $user->setPasswordWeak($originalWeak);
            } else {
                $user->setPasswordWeak(PasswordPolicy::isWeak($password, [$user->getUsername()], $tier));
            }

            $validator = new LoginUserValidator($user, $this->repo(), $password, $isNew, $user->getId(), $tier);
            if ($validator->isValid()) {
                if ($password !== '') {
                    $user->setPasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
                }
                if ($isNew) {
                    $user->setSortKey($this->nextSortKey());
                }

                $this->em()->persist($user);
                $this->em()->flush();

                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    'Benutzer «' . $user->getUsername() . '» ' . ($isNew ? 'angelegt' : 'gespeichert')
                );
                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $user->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            }
            // validation failed — fall through to re-render the form with errors
        }

        $entityCsrf = !$isNew ? DI::getCsrfService()->generateEntityToken('loginUser', $user->getId()) : '';

        $response = $this->html([
            'entry'             => $user,
            'entityCsrf'        => $entityCsrf,
            'roleLabels'        => $this->roleLabels(),
            'validator'         => $validator ?? new LoginUserValidator($user),
            'passwordMinLength' => $tier->minLength(),
        ]);
        $this->layoutManager->addPartials('edit', 'System/LoginUserController', self::NAMESPACE);
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

    protected function confirmDeleteAction(): HtmlResponse
    {
        $id   = (int)DI::getRequest()->getGetParameter('id');
        $user = $id ? $this->repo()->find($id) : null;

        $entityCsrf = $user ? DI::getCsrfService()->generateEntityToken('loginUser', $id) : '';

        $response = $this->html([
            'entry'       => $user,
            'entityCsrf'  => $entityCsrf,
            'blockReason' => $user ? $this->deleteBlockReason($user) : 'Benutzer nicht gefunden',
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'System/LoginUserController', self::NAMESPACE);
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

        $response = $this->html(['entry' => $user]);
        $this->layoutManager->addPartials('actions', 'System/LoginUserController', self::NAMESPACE);
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
        if (!DI::getCsrfService()->validateEntityToken($entityCsrf, 'loginUser', $id)) {
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

        $this->em()->remove($user);
        $this->em()->flush();

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('remove-element', ['target' => '[data-user-id="' . $id . '"]'])
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
    private function deleteBlockReason(LoginUser $user): ?string
    {
        if ($user->getId() === DI::getAuthService()->getCurrentUser()->getId()) {
            return 'Du kannst dein eigenes Konto nicht löschen.';
        }

        if ($this->isAdminCapable($user)) {
            $remaining = count(array_filter(
                $this->repo()->findAll(),
                fn(LoginUser $u) => $u->getId() !== $user->getId() && $this->isAdminCapable($u)
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
    private function isAdminCapable(LoginUser $user): bool
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

        $cleaned = BodyCleaner::cleanFor(LoginUser::class, [$field => $body['value'] ?? '']);
        $entity  = new LoginUser($cleaned);

        // Format-only live check (no repo): the blur channel sends just {field,value}
        // — no id — so uniqueness, which must exclude the edited user, is enforced
        // on full submit (where the loaded entity carries its id) to avoid a false
        // "username taken" against the user's own name.
        $validator = new LoginUserValidator($entity);
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

        $rest     = array_values(array_filter($users, fn(LoginUser $u) => $u->getId() !== $entryId));
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
