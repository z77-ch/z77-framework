# ADR-037 — One account, several project references: grants beside the home

**Status:** `[APPROVED]`
**Date:** 2026-09-06

---

## Context

Since B7 v1.1.0 a project reference (AXO3: a tenant) may carry several member
accounts, always by invitation from its master (project ADR `konto-einladung`).
The rule behind it was **one e-mail = one account = one reference**: an
invitation to an address that already had an account was refused with
`ALREADY_TAKEN`, and that refusal was deliberately the SIGNAL that one human
was to work for two references — the trigger to build the apparatus instead of
collecting accounts.

The signal fired on 2026-09-01: the master of one tenant invited an address
that already hung, as an invited account, on another tenant. The project side
answered the open questions in a bauplan (`agentur-delegation-bauplan.md`,
freigegeben 2026-09-06) and handed the framework part over
(`handoff-framework-mandanten-grants-2026-09-06.md`).

Options on the table:

- A second account to the same address (plus-address, username instead of
  e-mail) — the forbidden catalogue of the project ADR, and twice 2FA, twice
  devices, re-login per switch.
- A list of references on the account (`tenantRef[]`) — costs the load-bearing
  rule «the tenant comes from the account», touches B7, B8 and B10 at once, and
  needs a migration of `accounts.json` for the 99 % who have one reference.
- An identity of its own beside the member account — a second login, a second
  store, for a case that is structurally a member with one more permission.

## Decision

**The account keeps exactly one HOME (`MemberAccount::$tenantRef`, unchanged).
A GRANT is a small row beside it: «this account may additionally work for that
reference». The session holds a CHECKED choice among home + grants; everything
that works reads the choice, everything that owns reads the home.**

1. **Store.** `MemberGrant` (`data/framework/member/grants.json`, same
   mechanics as `accounts.json`): `{id, account_id, tenant_ref, invited_by,
   state, created_at, confirmed_at, activated_at, suspended_at}`. States
   `confirmed → active`; `suspended_at` is the master's pause switch with the
   account's semantics. Ids live in their own space (`g-…` next to `m-…`).
2. **Way in.** Only an invitation, and only from the master of the home. A
   known address elsewhere gets the token like an unknown one; the page behind
   the link asks «add this reference to your account?» (POST, with decline)
   instead of a name. Redemption creates the grant `confirmed`; **we activate**
   it in the backend like every registration — with NO activation hook, since
   nothing is created. `ALREADY_TAKEN` now means only «already on THIS
   reference» (home or grant, any state).
3. **The choice.** `MemberGrants::grantedTenantRefs($account)` is the ONE
   source of the granted set (home first, then usable grants).
   `MemberGrants::choose()` checks against it BEFORE writing
   `member.activeTenantRef`; `MemberGrants::activeTenantRef($account)` reads it
   back with the home as fallback — never «nothing» while there is a home. A
   fresh sign-in (also the device-key resume) clears the choice.
4. **Ownership follows the home.** Inviting, pausing, removing
   (`InvitationFlow::tenantRefOf()`), the profile hook that renames a tenant,
   and the backend's «who is the master» all read `getTenantRef()`. A grant
   confers no ownership and no right to invite.
5. **Deletion.** A removed grant never deletes the account. A deleted account
   takes its grants along (`MemberAccounts::delete()` cascades); a project
   that deletes a reference calls `MemberGrants::deleteForTenant()`; the
   cleanup job purges grants whose account is gone — and deletes NOTHING by
   age: a `confirmed` grant waits for the operator like a `confirmed` account.
6. **Surface.** The header's tenant label becomes a `<details>` switcher from
   two granted references on — one form per entry, no script; the master's
   «Zugänge» lists grants on his reference with the same two handgrips; the
   backend list shows grants as their own rows naming BOTH references.

## Reasoning

**Why beside, not inside the account:** for the 99 % nothing changes — no
nullable `tenantRef`, no migration, one login, one TOTP, one device set. The
row that exists only for the exception costs the rule nothing.

**Why the choice is a write, not a request parameter:** the bauplan's rule
«the acting tenant never comes from the request of the working operation» is
kept literally — the only request that names a reference is the choice itself,
and it is checked against the granted set before it lands. Working requests
read the session.

**Why ownership reads the home:** a grant on the session choice would let a
guest invite (fetching somebody the master cannot get rid of) or rename a
foreign tenant by saving his own profile (found in the code verification of
2026-09-06, `MemberProfileHook`). «Where a handgrip concerns ownership of the
reference, the home counts, not the choice» is the general form.

**Why no age-based cleanup for grants:** the module's rule for accounts holds —
what waits for a human decision is never a cron's to delete. The handoff asked
for aging «like never-confirmed accounts»; a grant is born CONFIRMED, so the
comparable account is the confirmed one, which is never touched.

**Why an open invitation is re-sent, not refused:** the handoff listed «offene
Einladung» among the `ALREADY_TAKEN` cases. For an unknown address a second
invitation devalues the first (resend semantics, TokenService::issueInvite);
the known address gets the same, or the two cases would behave differently for
no reason the master could see.

## Consequences

- Projects with one reference per account see no change; the switcher renders
  only from two granted references on.
- A project that reads the acting tenant (AXO3 `TenantAccess::tenantFor()`)
  moves that ONE read to `MemberGrants::create()->activeTenantRef($account)`;
  ownership reads stay on `getTenantRef()`.
- A project's whole-file override of `memberConfig.inc.php` MUST carry the new
  route `mandantAction`; a narrowed backend mount decides via
  `memberGrantRows()` which grants it shows.
- A project's deletion path of a reference MUST call
  `MemberGrants::deleteForTenant()`; the module cannot know the reference is
  gone.
- The invited person's redemption page reveals that the address has an
  account — to the holder of the link only, whose mailbox it is. The master's
  outcome (`SENT`) is the same for a known and an unknown address elsewhere:
  the anti-oracle exception of the project ADR is narrower than before.
- The decline of a grant invitation consumes the link; a decline by mistake
  needs a new invitation.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Second account to the same address (plus-address, username) | Forbidden catalogue of the project ADR: twice 2FA, twice devices, re-login per switch, and the first multi-tenant case becomes invisible |
| `tenantRef[]` on the account with a switcher | Costs «the tenant comes from the account», migration of every store, and touches B7/B8/B10 at once for a case that is one row |
| Own agency identity beside the member account | A second login and store for what is structurally a member with one more permission |
| Choice as a request parameter of the working pages | Every write would have to re-check ownership; one forgotten check is a foreign tenant's data |
| Grants age out like never-confirmed accounts | A confirmed grant waits for the operator; the module never lets a cron decide what a human should |
| Mixed list of accounts and grants from `accountsOf()` | Two different things (a person vs. a permission of a person who lives elsewhere); every consumer would sort them out again |
