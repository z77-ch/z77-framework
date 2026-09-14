<?php

namespace Z77\Module\Member\Services;

use Z77\Core\DI;
use Z77\Module\Member\Entities\MemberAccount;

/**
 * Which project references an account may work for, and which one it is
 * working for RIGHT NOW (ADR-038, replacing `MemberGrants` of ADR-037).
 *
 * The module knows no tenants and keeps no memberships. It ASKS the project,
 * through `memberConfig['membershipHook']` — an invokable
 * `__invoke(MemberAccount): list<array{ref:string, label:string, usable:bool, note?:string}>`
 * — and never leads: what comes back is the project's answer, in the
 * project's order, and the FIRST usable entry is the fallback choice. A
 * project without the hook answers «none»: no switcher, no choice, no
 * membership anywhere (zihlundsee).
 *
 *   memberships($account)  everything the project reports, usable or not —
 *                          the backend list names pending ones too
 *   available($account)    the usable refs, in order — the set every choice
 *                          is checked against
 *   activeRef($account)    the session's choice while it is still available,
 *                          else the first available; null when there is none
 *   choose($account, $ref) the WRITE: checked against available() BEFORE it
 *                          lands in the session — «the acting reference never
 *                          comes from the request of a working operation»
 *                          (ADR-037, kept word for word)
 *   holds($account, $ref)  whether the account belongs to the reference in
 *                          ANY state — the invitation asks this, and a paused
 *                          membership counts (it is resumed, not re-invited)
 *
 * ⚠️ What this service does NOT decide: rights. Whether the account may
 * invite, rename or pause anything on a reference is the project's question
 * to its own membership list (owner vs. agent). Here there is only «may work
 * for», which is what the switcher and the session need.
 */
final class TenantChoice
{
    /** @var array<string, list<array{ref:string,label:string,usable:bool,note:string}>> per request */
    private array $memo = [];

    /**
     * @param ?MemberSession $session the session the choice lives in — null in
     *        a context without one (cleanup job, backend list, harness); then
     *        activeRef() answers the first available and choose() refuses.
     * @param ?\Closure(MemberAccount): array $hook the project's membership
     *        hook, already resolved; null = no project, no memberships.
     */
    public function __construct(
        private ?MemberSession $session = null,
        private ?\Closure $hook = null,
    ) {
    }

    /** Production wiring: the kernel session + the hook named in memberConfig. */
    public static function create(): self
    {
        return new self(
            new MemberSession(DI::getSessionManager()),
            self::hookFromConfig(),
        );
    }

    /**
     * The hook as a closure, or null. Resolved once per call site, not per
     * account: a project's whole-file config override names it, and the same
     * resolution serves the invitation flow and the backend list.
     */
    public static function hookFromConfig(): ?\Closure
    {
        $fqcn = (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
            ->get('membershipHook', '');

        if ($fqcn === '' || !class_exists($fqcn)) {
            return null;
        }

        return static fn(MemberAccount $account): array => (array)(new $fqcn())($account);
    }

    // ── what the project reports ───────────────────────────────────────────

    /**
     * Every membership the project reports for this account, normalized: a
     * row without a ref is dropped, `usable` defaults to true, `note` to ''.
     * A hook that throws answers «none» — a switcher is chrome, and a project
     * hook that stumbles must not cost the page it decorates.
     *
     * @return list<array{ref:string,label:string,usable:bool,state:string,note:string}>
     *         state: active | paused | waiting
     */
    public function memberships(MemberAccount $account): array
    {
        $id = (string)$account->getId();
        if ($id !== '' && array_key_exists($id, $this->memo)) {
            return $this->memo[$id];
        }

        $rows = [];
        if ($this->hook !== null) {
            try {
                foreach (($this->hook)($account) as $raw) {
                    $ref = trim((string)($raw['ref'] ?? ''));
                    if ($ref === '') {
                        continue;
                    }
                    $usable = (bool)($raw['usable'] ?? true);
                    // WHY a row is closed, when the project says: `paused`
                    // (the owner's pause) or `waiting` (for the operator).
                    // The profile words the person's status from it — a
                    // paused person must read «pausiert», not «aktiv»
                    // (Peter, 2026-09-14). Unknown → derived from `usable`.
                    $state = strtolower(trim((string)($raw['state'] ?? '')));
                    if (!in_array($state, ['active', 'paused', 'waiting'], true)) {
                        $state = $usable ? 'active' : 'waiting';
                    }
                    $rows[] = [
                        'ref'    => $ref,
                        'label'  => trim((string)($raw['label'] ?? '')) ?: $ref,
                        'usable' => $usable,
                        'state'  => $state,
                        'note'   => trim((string)($raw['note'] ?? '')),
                    ];
                }
            } catch (\Throwable) {
                $rows = [];
            }
        }

        if ($id !== '') {
            $this->memo[$id] = $rows;
        }

        return $rows;
    }

    /** @return list<string> the usable refs, in the project's order */
    public function available(MemberAccount $account): array
    {
        $refs = [];
        foreach ($this->memberships($account) as $row) {
            if ($row['usable'] && !in_array($row['ref'], $refs, true)) {
                $refs[] = $row['ref'];
            }
        }

        return $refs;
    }

    /** Does the account belong to the reference in ANY state? */
    public function holds(MemberAccount $account, string $ref): bool
    {
        $ref = trim($ref);
        foreach ($this->memberships($account) as $row) {
            if ($row['ref'] === $ref) {
                return true;
            }
        }

        return false;
    }

    // ── the choice ─────────────────────────────────────────────────────────

    /**
     * The reference the account works for now — the validated session choice,
     * or the first available. Null only when the account has nothing at all.
     */
    public function activeRef(MemberAccount $account): ?string
    {
        $available = $this->available($account);
        if ($available === []) {
            return null;
        }

        $chosen = $this->session?->activeTenantRef();

        return ($chosen !== null && in_array($chosen, $available, true)) ? $chosen : $available[0];
    }

    /**
     * The choice: checked against available(), THEN written. False when the
     * reference is not available (or there is no session to write to) — and
     * then nothing changes, the previous choice stands.
     */
    public function choose(MemberAccount $account, string $ref): bool
    {
        $ref = trim($ref);
        if ($this->session === null || !in_array($ref, $this->available($account), true)) {
            return false;
        }

        $this->session->setActiveTenantRef($ref);

        return true;
    }

    /** The label the project gave a reference, or the bare reference. */
    public function labelFor(MemberAccount $account, string $ref): string
    {
        $ref = trim($ref);
        foreach ($this->memberships($account) as $row) {
            if ($row['ref'] === $ref) {
                return $row['label'];
            }
        }

        return $ref;
    }

    /** Forget what was read — after the project changed a membership in the same request. */
    public function forget(MemberAccount $account): void
    {
        unset($this->memo[(string)$account->getId()]);
    }
}
