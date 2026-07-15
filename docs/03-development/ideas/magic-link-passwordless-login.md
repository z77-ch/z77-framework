# Magic Link — Passwordless Login

**Status:** `[IDEA]`
**Date:** 2026-04 (captured 2026-07-11)
**Context:** Originated from a client concept (wine-shop checkout) — replace password login to
lower checkout friction. Framework-level relevance: the passwordless variant of the
`secondFactor` roadmap in [`../../topics/security.md`](../../topics/security.md) (`none` / `totp` / `magic`).

> Client-specific parts (effort estimate, pricing, offer wording) intentionally omitted —
> this file captures only the reusable framework idea.

---

## Problem

Password login adds checkout friction and support cost (resets, forgotten passwords) and
keeps passwords as an attack surface. For low-frequency, intent-driven purchases (e.g. a
wine shop) forcing account creation hurts conversion.

## Idea

Email-based one-time login link ("magic link"):

- User enters their email at checkout.
- System sends a one-time login link to that address.
- Clicking the link logs the user in immediately — no password, no account creation.
- Link is valid **5 minutes** and **single-use**; reusing an old link errors out.

### Cross-device flow (start on PC, confirm on phone)

- The originating browser polls `/auth/status` every ~2 s.
- Clicking the link on any device flips the server-side request status to `confirmed`.
- The polling browser detects `confirmed` and logs in automatically — transparent to the user.
- Poll times out after ~10 min with a clear error.

## Components

**Reusable (already in the framework):** mail sending, session management after login,
persistence layer, vanilla-JS frontend.

**New:**
- `login_requests` table — token management (see data shape below).
- Backend endpoints: request link, verify token, poll status (`/auth/status`, simple GET).
- Frontend: email-entry popup in checkout, waiting overlay with polling, resend flow.

### Data shape (sketch)

```
login_requests
  id
  email
  token_hash      -- hashed, never plaintext
  status          -- 'pending' | 'confirmed' | 'consumed'
  expires_at      -- created_at + 5 min
  created_at
```

## Security architecture (part of the design, not add-ons)

- Tokens **hashed at rest**, never stored in plaintext.
- **Single-use**, auto-expire after 5 minutes.
- **Rate limiting**: max 3 requests / 10 min / email (brute-force + spam).
- **Uniform server responses** — no signal whether an email exists (anti-enumeration).
- **HTTPS mandatory** — link only works over TLS.
- Token bound to reduce theft-via-URL impact; short validity limits the window.

## Open questions before building

- Binding strength for the cross-device case (browser fingerprint vs. pure token) vs. UX cost.
- Where this sits relative to the `secondFactor` roadmap: standalone passwordless login vs.
  one factor among `totp` / WebAuthn (see `security.md`).
- Build only when a concrete consumer needs it (framework-minimal rule) — no speculative build.

## See also

- [`../../topics/security.md`](../../topics/security.md) — `secondFactor` roadmap (`magic`), auth flow.
- [`../../topics/login.md`](../../topics/login.md) — `LoginUser` entity.
- [`../../topics/mail.md`](../../topics/mail.md) — mail sending (reused for link delivery).
