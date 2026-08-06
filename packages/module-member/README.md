# z77/module-member

Self-registration for z77 projects: an interested party registers, confirms
their e-mail (hard double opt-in — confirmation is a gate, not a notice), and
is activated by an operator in the backend. Passwordless by design: the
confirmed e-mail is the key; login (magic link, 2FA) is a separate concern that
reuses this module's token mechanism.

Account states:

```
registered ──e-mail confirmed──▶ confirmed ──operator activation──▶ active
```

The module is project-neutral. What happens on activation (e.g. AXO3 creating
a tenant) is override logic, attached through the activation hook — the module
itself never knows the project domain.

Pieces:

- `Entities/MemberAccount` — account with state machine, file-backed
  (`framework/member/accounts.json`), carrier-neutral via the kernel
  persistence layer.
- `Entities/MemberToken` + `Services/TokenService` — one token mechanism with
  a purpose field: hashed at rest (never plaintext), time-limited, single-use.
  `confirm` is used here; `login` is reserved for the login module part.
- `Services/MemberAccounts` — account lifecycle: register (normalized unique
  e-mail), confirm, activate, delete.

Origin: AXO3 building block B7 (spec `member-registrierung`, axo3-core).
