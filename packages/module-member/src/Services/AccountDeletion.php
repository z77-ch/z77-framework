<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The person deletes her own account (2026-09-14; Art. 32 revDSG is the
 * reason it is a button and not a mail to us). What dies with it is the
 * module's: the account record with its 2FA secret, every device key, the
 * tokens and pending logins that pointed at it. What the deletion means at
 * the PROJECT — memberships, a tenant left without its owner — is the
 * project's, asked FIRST through `memberConfig` `accountDeletionHook`:
 *
 *   __invoke(MemberAccount): ?string   detach the person; return one short
 *                                      fact for the log; THROW to refuse —
 *                                      then nothing is deleted
 *   notice(MemberAccount): list<string> optional — sentences the dialog shows
 *                                      before the person confirms
 *
 * Order: project first, then the module. The other way round would leave a
 * project row pointing at an account that is gone if the hook fails.
 *
 * The session is NOT ended here — the controller does that, because it is
 * the one holding it. Nothing is mailed to the person: she is on the page.
 */
final class AccountDeletion
{
    /**
     * @param ?\Closure(MemberAccount): ?string $projectHook
     */
    public function __construct(
        private MemberAccounts $accounts,
        private TokenService $tokens,
        private PendingLogins $pendingLogins,
        private DeviceKeys $deviceKeys,
        private ?\Closure $projectHook = null,
    ) {
    }

    /** Production wiring: file persistence, the project's hook from the config. */
    public static function create(): self
    {
        $uem  = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
        $hook = self::hookInstance();

        return new self(
            new MemberAccounts($uem),
            new TokenService($uem),
            new PendingLogins($uem),
            DeviceKeys::create(),
            // ⚠️ `use ($hook)`: a static closure sees no outer variable (the
            // RegistrationFlow defect of 2026-09-14).
            $hook === null ? null : static fn(MemberAccount $a): ?string => ($hook)($a),
        );
    }

    /**
     * What the project wants the person to read before she confirms. Empty
     * without a hook or without a `notice()` method.
     *
     * @return list<string>
     */
    public static function noticesFor(MemberAccount $account): array
    {
        $hook = self::hookInstance();
        if ($hook === null || !method_exists($hook, 'notice')) {
            return [];
        }
        try {
            return array_values(array_filter(
                array_map(static fn($s): string => trim((string)$s), (array)$hook->notice($account)),
                static fn(string $s): bool => $s !== ''
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Deletes the account. Throws when the project refuses — then nothing has
     * changed. Returns the log detail the project handed back.
     */
    public function delete(MemberAccount $account, ?int $now = null): ?string
    {
        $id     = (string)$account->getId();
        $detail = $this->projectHook !== null ? ($this->projectHook)($account) : null;

        $this->deviceKeys->revokeAll($account);   // every device, this one included
        $this->accounts->delete($account);

        // Tokens and pending logins of accounts that no longer exist — the
        // same sweep the cleanup job runs, here at the moment it matters.
        $surviving = array_map(static fn(MemberAccount $a): string => (string)$a->getId(), $this->accounts->all());
        $this->tokens->purge($surviving, $now);
        $this->pendingLogins->purge($now);

        MemberLog::write('account.delete', $id, ['detail' => $detail]);

        return $detail;
    }

    private static function hookInstance(): ?object
    {
        try {
            $fqcn = (string)DI::getConfigManager()
                ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
                ->get('accountDeletionHook', '');
        } catch (\Throwable) {
            return null;
        }

        return $fqcn !== '' && class_exists($fqcn) ? new $fqcn() : null;
    }
}
