# ADR-038 — The member module knows no project reference: the person here, her rights at the project

**Status:** `[APPROVED]` — built 2026-09-13 (framework side; the project side follows in the same release)
**Date:** 2026-09-13 (revised the same day: `company` stays, see Decision 1)

---

## Context

`module-member` was built project-blind: it does not know what a tenant is.
It still needed ONE grip to hand an activated account to the project, and that
became `tenantRef` — an opaque reference ON the account, written back by the
activation hook. While one account meant one reference, a field on the account
was the cheapest place.

Every step since has lengthened that shortcut instead of lifting it:

| step | what the ACCOUNT gained | why |
|---|---|---|
| B7 registration | `company` | at registration there is no tenant yet to carry the name |
| B7 v1.1.0 (project ADR `konto-einladung`) | `tenantRole: master \| member` | several accounts per reference, exactly one owns it — derived from «registered it» |
| B7 v1.1.0 | `suspendedAt` | the master pauses an account |
| ADR-037 | `grants.json` beside it | one account works for a SECOND reference |

Measured on 2026-09-13 in an installation's `accounts.json`: of 20 fields, 16
belong to the person and 4 to the project (`company`, `origin`, `tenantRef`,
`tenantRole`). The project's profile hook renames the tenant when the person
saves her profile. The project's purge deletes accounts whose home is the
tenant — people vanish with a firm. And «Zugänge» had to move out of the
profile on 2026-09-12 because the header named the CHOSEN reference while the
section listed the HOME's accounts — the first move caused by this, and
without a model change not the last.

The project owner's diagnosis (Peter, 2026-09-13): the account is profile and
tenant in one, and that is wrong. An account belongs to exactly one e-mail,
has login, 2FA and sessions with devices. It belongs to the person. What the
person MAY DO is the project's business and hangs at the project's tenant.

## Decision

**The framework owns the person and the door. The project owns the tenant,
its people and their rights.**

1. **`module-member` carries no project data.** It creates the account,
   confirms the e-mail, runs login (magic link), 2FA, remembered devices,
   profile and theme. It decides «may enter»: the framework ACL role
   (`roles`, e.g. `customer`) and the registration state
   (`registered → confirmed → active`). **`tenantRef` and `tenantRole`
   leave `MemberAccount`; `grants.json` and `MemberGrant` go.** `origin`
   stays — it records which register link was clicked. **`company` stays
   too** (revised 2026-09-13): it is an attribute of the PERSON — where she
   works — like her name, edited by her in the profile. The module never
   interprets it; the project's activation hook may copy it once as the
   tenant's initial name, after which the two fields are independent. What
   must go with that is any hook that writes the profile's `company` INTO a
   tenant on every save — that coupling is the conflation itself.

2. **Memberships live at the project's tenant, not here.** The project keeps
   `(account_id, role, state, invited_by, since)` per tenant and reads every
   right from that list. The module never sees it.

3. **The module ASKS the project, it never LEADS.** One hook, in the pattern
   of `tenantLabelHook`: *«which references does this account hold, with
   which role?»* The project answers from its own store; a project without
   references answers «none». That feeds the header switcher and the session
   choice — nothing else in the module needs it.

4. **The session stays framework, its content comes from the project.** The
   module holds `account_id` and the slot for the choice
   (`member.activeTenantRef`). The rule of ADR-037 survives word for word:
   the reference an account WORKS FOR comes from the session, never from the
   request, and into the session only through a choice checked against the
   set the project reports.

5. **Pausing is a right at the tenant, so it moves to the membership.**
   `suspendedAt` as «the master paused this account» goes. What may stay on
   the account is a block by the OPERATOR — a different thing: the person
   enters nowhere, not «this tenant's owner said no».

6. **Invitation tokens stay; attaching goes.** `MemberToken` with purpose
   `invite` keeps carrying the target reference and the inviter's id — a
   token is a one-time, hashed, time-limited thing and that is this module's
   craft. What happens on redemption — create the account if the address is
   new, then CREATE THE MEMBERSHIP — is split: the account here, the
   membership through the project (an activation-style hook, «this account
   joined reference X as agent»).

## Reasoning

- **Data shape says who owns what.** Four project fields on the account are
  four places where the person speaks for the firm. With the list at the
  tenant, the profile cannot reach the tenant without meaning to.
- **The module gets simpler.** It stops managing references it never
  understood. An installation without tenants (zihlundsee) sheds a switcher,
  a grant store and a role field it never used.
- **Deletion becomes honest on the project side.** A tenant takes its list
  with it; no grant is left dangling, no person disappears with a firm.
- **The cut is already half there.** `tenantLabelHook` asks the project for a
  name; `MemberActivationHook` hands the account to the project. The
  membership hook asks one more question in the same direction.
- **What was built on 2026-09-12 is not lost.** The `Zugaenge` area was put
  into the framework under the argument «nothing in it is project-specific;
  `InvitationFlow` is the whole domain». That held while the framework
  managed references. Its templates, dialogs and fences move with the domain
  to the project; the token mechanics stay here.

## Consequences

**What moves out of `module-member`** (to the project that has tenants):

| stays here | moves to the project |
|---|---|
| account, confirm, login, magic link, 2FA, devices, profile, theme | the `Zugaenge` area (accounts, invitations, pause, remove) |
| token mechanics, `invite` purpose included | the «who may work here» part of `InvitationFlow` |
| the header switcher — fed by the hook | `MemberGrants` → the project's membership list |
| ACL roles, registration state, operator block | the activation hook writes an OWNER membership, not `tenantRef` |

**Supersedes in part:** ADR-037 — its session-choice rule stands; its data
home (`tenantRef` + `grants.json`, «ownership follows the home») is
replaced by memberships at the project. `docs/topics/member.md` MEM rules
that read `getTenantRef()` for ownership are retired with the build.

**Migration:** one script per installation turns `accounts.json` +
`grants.json` into the project's memberships, then drops the fields. Order:
project ADR → its open questions → specs → this ADR built → project built →
migration → acceptance. Not before, not in parallel.

**Decided on the project side the same day** (project ADR `konto-und-mandant`
v1.1.0): the tenant is created at ACTIVATION and takes `company` as its
initial name; the tenant's runtime status leaves `tenants.json` so that file
is written by people only and the membership list lives in it; an account
whose LAST membership goes is deleted (no new mechanism — «remove» already
deletes a person); owner handover is an operator handgrip, no customer path.

## What was built (2026-09-13)

- `MemberAccount`: `tenantRef`, `tenantRole`, `suspendedAt` and their
  getters, setters, `isMaster()`, `isSuspended()`, `ROLE_*` gone.
- `MemberGrant`, `MemberGrants`, `grants.json` gone; `ZugaengeController`
  and its template gone (moved to the project; history in `e274550`).
- New `TenantChoice` (memberships via hook, available set, session choice)
  and three config hooks: `membershipHook`, `joinHook`,
  `areaVisibilityHook`. `activationHook` returns nothing that is stored.
- `InvitationFlow` reduced to the token's craft: `invite(inviter, ref,
  email)` checks no right, `redeem()` creates the account if new and calls
  `joinHook`, `sendJoinActivated()` is the mail a project sends when it
  activates a join. Outcomes `JOINED` instead of `GRANTED`.
- `MemberAuth` and `LoginFlow` no longer refuse a paused account — there
  is no such flag; pausing is the project's membership state (MEM-016).
- Backend accounts list and activation dialog read the project's
  memberships (`membershipHook`) for «creates / attaches»; the grant
  section and its two modals are gone (the project's backend lists
  pending joins).
- `shell.js`: the pause switch posts to `data-url` (hand copy per
  installation, ADR-024).
- Not built, by decision: an OPERATOR block on the account. Nothing
  needed it; the field can come when something does.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep `tenantRef` + grants on the account | Drift measured: profile hook renames a tenant, purge deletes people, `Zugaenge` already moved once. Every further step lengthens the shortcut. |
| `tenantRef[]` on the account | Rejected by ADR-037 already — and it is still the person carrying the firm, only longer. |
| A generic `Membership` entity IN the framework | The framework would manage references again, under a better name. It may ask, never lead. |
| UI-only fix (a «Mandant» area, profile behind the avatar) | Removes the symptom, not the cause. Acceptable as a STAGE if the model has to wait — then named as a stage, not as the fix. |
