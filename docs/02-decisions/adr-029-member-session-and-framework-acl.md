# ADR-029 — Member Session and Framework ACL

**Status:** `[OPEN]` — the separation below is in force; how the two meet is deliberately not decided yet
**Date:** 2026-08-07

---

## Context

The framework has one authentication path: `AuthService` resolves an `AuthUser` from
`data/framework/auth/loginUsers.json`, `AccessGuard` compares it against the role required by
the current route (Action > Controller > Module > GUEST) and redirects to the admin login when
it is not enough. Roles are hierarchical: `GUEST=0`, `VISITOR=10`, `MEMBER=20`, `CRON_JOB=30`,
`ADMIN=80`, `SUPER_USER=100`.

`module-member` added a **second** identity: customer accounts with a passwordless login
(`MemberAccount`, magic link, optional TOTP, revocable device keys). Its session lives under
its own keys (`member.*`) and is read by `MemberAuth`. It never writes `auth_user`.

As built:

- Every member route is declared `AuthRole::GUEST` in the module config — it has to be, or
  `AccessGuard` would send a signed-in customer to the **admin** login.
- Each member page guards itself: `MemberAuth::create()->current()` or redirect.
- `MemberAccount.roles` holds `AuthRole` strings (`member` today), but nothing reads them
  during dispatch. They are data for the application on top, not an ACL input.

This is fine while member pages are few and their rule is «signed in or not». It stops being
fine when a role-based member application arrives (customer view vs. staff view, per-function
rights): every controller would re-implement the check that `AccessGuard` already performs.

## Decision

Two decisions, one now and one later.

**Now (in force):** the two identities stay separate. The member session MUST NOT write
`auth_user`, and member routes stay `AuthRole::GUEST` with their own guard. `MemberAccount.roles`
is application data, not an ACL input.

**Later (open):** whether — and how — the member session feeds the ACL is decided when the first
role-based member application is built (AXO3 B10). This ADR is then superseded or amended, not
worked around.

## Reasoning

Separate is right for what exists today, and merging carelessly would reach further than the
member module:

- `AuthRole::MEMBER` (level 20) is **already meaningful** elsewhere. The DMS grants ACEs to
  subject roles `member`/`visitor` (`documents.md`), and the page cache rests on the rule that
  guest and member renders stay byte-identical (`cache.md`, CACHE-ADMIN-001). Lifting customer
  accounts into that same level would silently widen document access and put session-dependent
  markup on cacheable pages.
- The two identities are not the same kind of thing. An `AuthUser` is created by an operator and
  has a password; a `MemberAccount` self-registers, is activated, and never has one. Their
  lifecycles, their stores and their recovery paths differ.
- A guard per member page is honest and readable while the rule is binary. It only becomes a
  liability once rights differ per function — which is exactly the moment the second decision
  is due.

## Consequences

- `MemberAuth` is the single seam other building blocks use; a project asks it for account and
  roles and applies its own rules on top.
- A signed-in customer is a guest to `AccessGuard`. Anything relying on the framework ACL —
  DMS ACEs, page-cache behaviour, admin-only routes — is unaffected by member sessions, and
  MUST stay that way until this ADR is amended.
- The trap is named in `member.md` (MEM-001): declaring a member route `AuthRole::MEMBER` locks
  signed-in customers out, because the string on the account is not the role in the session.
- When the second decision is taken, a **new role level for customer accounts** is the likely
  shape (rather than reusing `MEMBER`), so that DMS and cache semantics stay untouched. The
  cost is one more level in the hierarchy and a session that two services can write.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Member login writes `auth_user` with role `member` now | Reaches into DMS ACEs and the page-cache rule; customer accounts would inherit permissions nobody granted them |
| Member routes declared `AuthRole::MEMBER` | Would lock signed-in customers out — the account's role string is not an ACL input; the ACL resolves `AuthUser`, which a member session never sets |
| Replace the admin login with the member mechanism | Out of scope and independently rejected in the B8 spec: the file-based admin login stays as it is |
| Decide the merge now, before a role-based member application exists | No concrete requirement to design against; the decision would be guesswork and would age before its first use |
