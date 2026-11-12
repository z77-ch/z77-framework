<?php

namespace Z77\Module\Api\Services;

use Z77\Core\DI,
    Z77\Module\Api\Entities\ApiAppUser,
    Z77\Module\Api\Ui\ApiDeviceAccess,
    Z77\Persistence\Resolver\UnifiedEntityManager,
    Z77\Shared\Auth\LoginCodeStore,
    Z77\Shared\Entities\BackendUser,
    Z77\Shared\Mail\EmailMessage
;

/**
 * The app users of the z77 App (ADR-058): who exists, who the linked person is, which scopes
 * hold right now — and their administration.
 *
 * Administration (create, update, deactivate, reactivate) is for an ADMIN only (A9, no
 * self-service) and carries the actor rule of the device screen over ({@see ApiDeviceAccess::mayActFor()},
 * review B5): an actor never creates, links, changes the e-mail of, assigns scopes to or
 * reactivates an app user linked to a person whose roles lie above his own, and assigns only
 * scopes he could hold himself. The actor's roles come from the STORE, never a session. A
 * refusal is {@see ApiAppUserDenied}; bad data (address, duplicate, unknown person, a scope the
 * person may not hold) is an \InvalidArgumentException.
 *
 * The lifecycle is one rule (review B6): deactivating revokes ALL devices of the app user and
 * spends its pending login codes; reactivating means a fresh code sign-in — it revokes and spends
 * once more first (security review S3: a verify that asked «active?» just before the deactivation
 * may issue its device just after the revoke; that device must not come back alive). An e-mail
 * change keeps the devices (they belong to the app user, not to the address), spends pending
 * codes and tells the OLD address.
 *
 * Uniqueness (one app user per person, one per app e-mail) is checked and written under a lock
 * (`var/lib/api/app-users.lock`), so two parallel creates or changes cannot both pass the check.
 *
 * Members (realm `member`, A8) are modelled, not offered: create() refuses them, and a member
 * link resolves to no person ({@see linkedPerson()}), so nothing signs in through one yet.
 */
final class ApiAppUsers
{
    /** The mails of the app speak German (the app shell is German, ADR-058 §4 «explicit language»). */
    public const LANGUAGE = 'de';

    private const NS = 'Z77\\Module\\Api';

    private UnifiedEntityManager $uem;
    private ?ApiDeviceScopes $scopes;
    private ?ApiDevices $devices;
    private ?LoginCodeStore $codes;
    /** @var \Closure(EmailMessage): bool|null */
    private ?\Closure $sendMail;

    /** @param (\Closure(EmailMessage): bool)|null $sendMail */
    public function __construct(
        ?UnifiedEntityManager $uem = null,
        ?ApiDeviceScopes $scopes = null,
        ?ApiDevices $devices = null,
        ?LoginCodeStore $codes = null,
        ?\Closure $sendMail = null,
    ) {
        $this->uem      = $uem ?? DI::getUnifiedEntityManager();
        $this->scopes   = $scopes;
        $this->devices  = $devices;
        $this->codes    = $codes;
        $this->sendMail = $sendMail;
    }

    /** Where the login records live: `var/lib/api/login` — disposable (ADR-034/035). */
    public static function loginCodeDir(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/api/login';
    }

    /** The lock under which uniqueness is checked and written ({@see locked()}). */
    public static function lockFile(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/api/app-users.lock';
    }

    /**
     * Is this (normalised) string an address an app user may have? A plain address only: the
     * length of the column, FILTER_VALIDATE_EMAIL, and none of `"` `,` `;` or white space —
     * FILTER_VALIDATE_EMAIL accepts a quoted local part (`"a b"@x.ch`), which a mail header
     * would read as a display name or a list. The sign-in asks it first: what is not one is
     * nobody's address (security review M1).
     */
    public static function isAppEmail(string $email): bool
    {
        return $email !== ''
            && mb_strlen($email) <= ApiAppUser::EMAIL_LENGTH
            && preg_match('/["\s,;]/', $email) !== 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // ── reading ──────────────────────────────────────────────────────────────

    public function find(int $id): ?ApiAppUser
    {
        if ($id <= 0) {
            return null;
        }
        $user = $this->repository()->find($id);

        return $user instanceof ApiAppUser ? $user : null;
    }

    public function findByEmail(string $email): ?ApiAppUser
    {
        $email = ApiAppUser::normalizeEmail($email);
        foreach ($this->all() as $user) {
            if ($user->getEmail() === $email) {
                return $user;
            }
        }

        return null;
    }

    public function findLinked(string $realm, string $linkedId): ?ApiAppUser
    {
        foreach ($this->all() as $user) {
            if ($user->getLinkedRealm() === $realm && $user->getLinkedId() === $linkedId) {
                return $user;
            }
        }

        return null;
    }

    /** @return list<ApiAppUser> every app user, inactive ones included */
    public function all(): array
    {
        return array_values(array_filter(
            $this->repository()->findAll(),
            static fn($u): bool => $u instanceof ApiAppUser
        ));
    }

    /**
     * The linked person as the app needs it: name and roles, read from the STORE now. Null =
     * the person is gone — or a member (A8: not built), so nothing signs in through one.
     *
     * @return array{name: string, roles: list<string>}|null
     */
    public function linkedPerson(ApiAppUser $appUser): ?array
    {
        $user = $appUser->getLinkedRealm() === ApiAppUser::REALM_BACKEND ? $this->backendUser($appUser->getLinkedId()) : null;
        if ($user === null) {
            return null;
        }

        return [
            'name'  => $user->getName() !== '' ? $user->getName() : $user->getUsername(),
            'roles' => array_values($user->getRoles()),
        ];
    }

    /**
     * May this app user sign in / act right now? Active, and the linked person still exists.
     * Asked by the sign-in and by every device request (ADR-058 §4 «Lifecycle»).
     */
    public function isUsable(ApiAppUser $appUser): bool
    {
        return $appUser->isActive() && $this->linkedPerson($appUser) !== null;
    }

    /**
     * The scopes of the app user that hold NOW: those the linked person's roles reach the
     * declared minimum for (review B7) — rights can shrink between two requests.
     *
     * @param array{name: string, roles: list<string>} $person from {@see linkedPerson()}
     * @return list<string>
     */
    public function liveScopes(ApiAppUser $appUser, array $person): array
    {
        return $this->scopes()->filter($appUser->getScopes(), $appUser->getLinkedRealm(), $person['roles']);
    }

    /** A successful code sign-in (written by the login, not by an actor). */
    public function recordLogin(ApiAppUser $appUser, ?int $now = null): void
    {
        $appUser->setLastLoginAt(date(DATE_ATOM, $now ?? time()));
        $this->save($appUser);
    }

    // ── administration (A9: ADMIN only, the actor rule) ──────────────────────

    /**
     * A new app user for a backend user.
     *
     * @param list<string> $scopes
     * @throws ApiAppUserDenied          not an ADMIN, the person is above the actor, a scope the actor cannot hold
     * @throws \InvalidArgumentException bad or taken address, unknown person, person already linked, a member, bad scope
     */
    public function create(int $actorId, string $email, string $realm, string $linkedId, array $scopes, ?int $now = null): ApiAppUser
    {
        $now   = $now ?? time();
        $actor = $this->actor($actorId);
        if ($realm !== ApiAppUser::REALM_BACKEND) {
            throw new \InvalidArgumentException('Only backend users can be linked to an app user yet (ADR-058 A8).');
        }
        $person = $this->backendUser($linkedId);
        if ($person === null) {
            throw new \InvalidArgumentException("Unknown backend user {$linkedId}.");
        }
        $this->mayActFor($actor, $person);
        $scopes = $this->assignable($scopes, [], $realm, $person->getRoles(), $actor);

        // Check and write under one lock: two parallel creates must not both find «free».
        return $this->locked(function () use ($realm, $person, $email, $scopes, $now, $actorId): ApiAppUser {
            if ($this->findLinked($realm, (string) $person->getId()) !== null) {
                throw new \InvalidArgumentException('This person already has an app user.');
            }
            $appUser = new ApiAppUser();
            $appUser->setEmail($this->freeEmail($email, null));
            $appUser->setLinkedRealm($realm);
            $appUser->setLinkedId((string) $person->getId());
            $appUser->setScopes($scopes);
            $appUser->setActive(true);
            $appUser->setCreatedAt(date(DATE_ATOM, $now));
            $appUser->setCreatedBy($actorId);
            $appUser->setChangedAt(date(DATE_ATOM, $now));
            $appUser->setChangedBy($actorId);
            $this->save($appUser);

            return $appUser;
        });
    }

    /**
     * The app e-mail and the scopes. A scope added must be one the person may hold and the actor
     * could hold; a scope kept is not assigned anew. A changed e-mail spends pending login codes
     * and tells the old address; the devices stay signed in.
     *
     * @param list<string> $scopes
     * @throws ApiAppUserDenied|\InvalidArgumentException as {@see create()}
     */
    public function update(int $actorId, int $appUserId, string $email, array $scopes, ?int $now = null): ApiAppUser
    {
        $now     = $now ?? time();
        $actor   = $this->actor($actorId);
        $appUser = $this->existing($appUserId);
        $person  = $this->linkedBackendUser($appUser);
        $this->mayActFor($actor, $person);

        $oldEmail = $appUser->getEmail();
        $appUser->setScopes($this->assignable($scopes, $appUser->getScopes(), $appUser->getLinkedRealm(), $person?->getRoles() ?? [], $actor));
        $email = $this->locked(function () use ($appUser, $email, $now, $actorId): string {
            $email = $this->freeEmail($email, $appUser);
            $appUser->setEmail($email);
            $appUser->setChangedAt(date(DATE_ATOM, $now));
            $appUser->setChangedBy($actorId);
            $this->save($appUser);

            return $email;
        });

        if ($email !== $oldEmail) {
            $this->codes()->forgetSubject((string) $appUser->getId());
            $this->mail($this->emailChangedMail($oldEmail, $person, $now), 'e-mail change notice');
        }

        return $appUser;
    }

    /**
     * Deactivates: every device is revoked at once, pending login codes are spent. Reactivation
     * is a fresh sign-in (E13).
     *
     * @throws ApiAppUserDenied|\InvalidArgumentException
     */
    public function deactivate(int $actorId, int $appUserId, ?int $now = null): ApiAppUser
    {
        $actor   = $this->actor($actorId);
        $appUser = $this->existing($appUserId);
        $this->mayActFor($actor, $this->linkedBackendUser($appUser));

        return $this->switchOff($appUser, $actorId, $now ?? time());
    }

    /**
     * Active again — no device comes back; the person signs in anew by code.
     *
     * @throws ApiAppUserDenied|\InvalidArgumentException
     */
    public function reactivate(int $actorId, int $appUserId, ?int $now = null): ApiAppUser
    {
        $now     = $now ?? time();
        $actor   = $this->actor($actorId);
        $appUser = $this->existing($appUserId);
        $person  = $this->linkedBackendUser($appUser);
        if ($person === null) {
            throw new \InvalidArgumentException('The linked person is gone — this app user cannot be reactivated.');
        }
        $this->mayActFor($actor, $person);

        // Review S3: a verify that found the app user active just before the deactivation may
        // have issued a device (or a code may still be live) just after its revoke. Nothing of
        // before comes back — revoke and spend again before the switch is on.
        $this->devices()->revokeAllFor((int) $appUser->getId(), $now);
        $this->codes()->forgetSubject((string) $appUser->getId());

        $appUser->setActive(true);
        $appUser->setChangedAt(date(DATE_ATOM, $now));
        $appUser->setChangedBy($actorId);
        $this->save($appUser);

        return $appUser;
    }

    /**
     * The linked person was deleted ({@see ApiAppUserAccountListener}): its app user is
     * deactivated by the system — devices revoked, codes spent. False when there was none.
     */
    public function deactivateLinked(string $realm, string $linkedId, ?int $now = null): bool
    {
        $appUser = $this->findLinked($realm, $linkedId);
        if ($appUser === null) {
            return false;
        }
        $this->switchOff($appUser, 0, $now ?? time());

        return true;
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function switchOff(ApiAppUser $appUser, int $actorId, int $now): ApiAppUser
    {
        $appUser->setActive(false);
        $appUser->setChangedAt(date(DATE_ATOM, $now));
        $appUser->setChangedBy($actorId);
        $this->save($appUser);
        $this->devices()->revokeAllFor((int) $appUser->getId(), $now);
        $this->codes()->forgetSubject((string) $appUser->getId());

        return $appUser;
    }

    /** The actor as stored now; only an ADMIN (and up) manages app users (A9). */
    private function actor(int $actorId): ApiDeviceAccess
    {
        $user   = $actorId > 0 ? $this->backendUser((string) $actorId) : null;
        $access = new ApiDeviceAccess($user === null ? 0 : $actorId, array_values($user?->getRoles() ?? []));
        if (!$access->governs()) {
            throw new ApiAppUserDenied('Only an admin manages app users (ADR-058 A9).');
        }

        return $access;
    }

    private function mayActFor(ApiDeviceAccess $actor, ?BackendUser $person): void
    {
        if (!$actor->mayActFor($person)) {
            throw new ApiAppUserDenied('The linked person has a role above the actor\'s.');
        }
    }

    /**
     * What may be stored: every scope well-formed; each one ADDED (not held before) declared,
     * holdable by the person and by the actor.
     *
     * @param list<string> $scopes
     * @param list<string> $held
     * @param list<string> $personRoles
     * @return list<string>
     */
    private function assignable(array $scopes, array $held, string $realm, array $personRoles, ApiDeviceAccess $actor): array
    {
        $scopes = ApiDevices::normalizeScopes($scopes);
        $actorRoles = $this->actorRoles($actor);
        foreach ($scopes as $scope) {
            if (in_array($scope, $held, true)) {
                continue;
            }
            if (!isset($this->scopes()->all()[$scope])) {
                throw new \InvalidArgumentException("Scope '{$scope}' is not offered by any module.");
            }
            if (!$this->scopes()->holdable($scope, $realm, $personRoles)) {
                throw new \InvalidArgumentException("The linked person's roles do not reach scope '{$scope}'.");
            }
            // The actor is a backend user, so his own realm is asked. For a member-linked app user
            // (A8) a scope declared for members only would fail here — decided when A8 is built
            // (api.md API-014).
            if (!$this->scopes()->holdable($scope, ApiAppUser::REALM_BACKEND, $actorRoles)) {
                throw new ApiAppUserDenied("The actor could not hold scope '{$scope}' himself.");
            }
        }

        return $scopes;
    }

    /** @return list<string> */
    private function actorRoles(ApiDeviceAccess $actor): array
    {
        return array_values($this->backendUser((string) $actor->actorId())?->getRoles() ?? []);
    }

    /** A valid address ({@see isAppEmail()}) no OTHER app user holds, normalised. */
    private function freeEmail(string $email, ?ApiAppUser $self): string
    {
        $email = ApiAppUser::normalizeEmail($email);
        if (!self::isAppEmail($email)) {
            throw new \InvalidArgumentException('Invalid app e-mail address.');
        }
        $holder = $this->findByEmail($email);
        if ($holder !== null && ($self === null || $holder->getId() !== $self->getId())) {
            throw new \InvalidArgumentException('This app e-mail is taken by another app user.');
        }

        return $email;
    }

    private function existing(int $appUserId): ApiAppUser
    {
        return $this->find($appUserId) ?? throw new \InvalidArgumentException("Unknown app user {$appUserId}.");
    }

    private function linkedBackendUser(ApiAppUser $appUser): ?BackendUser
    {
        return $appUser->getLinkedRealm() === ApiAppUser::REALM_BACKEND ? $this->backendUser($appUser->getLinkedId()) : null;
    }

    private function backendUser(string $id): ?BackendUser
    {
        if (preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            return null;
        }
        $user = $this->uem->getRepository(BackendUser::class)->find((int) $id);

        return $user instanceof BackendUser ? $user : null;
    }

    /** The old address learns that the app e-mail moved — without the new one. */
    private function emailChangedMail(string $oldEmail, ?BackendUser $person, int $now): EmailMessage
    {
        return (new EmailMessage())
            ->to($oldEmail)
            ->language(self::LANGUAGE)
            ->subject('Deine E-Mail für die z77 App wurde geändert')
            ->template('emails/appEmailChanged', self::NS, [
                'greetingName' => $person !== null && $person->getName() !== '' ? $person->getName() : ($person?->getUsername() ?? ''),
                'when'         => date('d.m.Y, H:i', $now),
            ]);
    }

    /** Sends at once; a failure is logged, never thrown — the change is done either way. */
    private function mail(EmailMessage $mail, string $what): void
    {
        try {
            $sent = ($this->sendMail ?? static fn(EmailMessage $m): bool => DI::getEmailService()->send($m))($mail);
            if (!$sent) {
                error_log("ApiAppUsers: {$what} not sent");
            }
        } catch (\Throwable $e) {
            error_log("ApiAppUsers: {$what} not sent — " . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Runs $fn under the app users' lock. Polls non-blocking with a budget of ~2 s (the
     * FileStorage::withExclusiveLock() pattern — a blocking flock has no timeout), then throws.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function locked(\Closure $fn): mixed
    {
        $file = self::lockFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the app users\' lock.');
        }
        for ($attempt = 1; !flock($handle, LOCK_EX | LOCK_NB); $attempt++) {
            if ($attempt >= 40) {
                fclose($handle);
                throw new \RuntimeException('The app users are being changed by someone else — please try again.');
            }
            usleep(50000);
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function save(ApiAppUser $appUser): void
    {
        $this->uem->persist($appUser);
        $this->uem->flush();
    }

    private function repository(): object
    {
        return $this->uem->getRepository(ApiAppUser::class);
    }

    private function scopes(): ApiDeviceScopes
    {
        return $this->scopes ??= new ApiDeviceScopes(DI::getModuleManager());
    }

    private function devices(): ApiDevices
    {
        return $this->devices ??= new ApiDevices($this->uem, $this->scopes);
    }

    private function codes(): LoginCodeStore
    {
        return $this->codes ??= new LoginCodeStore(self::loginCodeDir());
    }
}
