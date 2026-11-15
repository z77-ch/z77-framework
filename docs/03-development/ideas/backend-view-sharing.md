# Backend view sharing — show your current view to invited users as a DOM snapshot

**Status:** `[IDEA]` — recorded by the owner 2026-11-15 («ablegen»). Not decided, not to be built now.
**Date:** 2026-11-15
**Context:** Came up in the session of a client project; the feature belongs to the framework
(module-backend). What is the owner's and what is a proposal is marked below.

---

## What the owner wants

Two or more backend users show each other what they have on the screen — back and forth, only
between the people who were **invited**. Sharp and small: no video, no screenshot. *(owner)*

## The idea in one paragraph

The backend serialises the current browser tab as HTML — the page, the open windows (ADR-047) and
dialogs, the values typed into the fields, saved or not — and sends it to the server. An invited
user opens it read-only in a sealed frame. Sent again every few seconds, it becomes simple
«watching». Everything runs over ordinary requests and polling: no WebSocket, no WebRTC, no TURN
server, no dependency — so it works on shared hosting.

## Flow

1. **Invite.** The sender presses «Ansicht teilen» and picks one or more backend users of the same
   installation. Each gets a notice in the backend (polling).
2. **Send.** The sender's browser sends a snapshot: once («Schnappschuss») or repeatedly while the
   share is open («Zuschauen» — on change, debounced, at most every few seconds).
3. **Look.** An invited user opens the share and sees the snapshot with a bar
   «Ansicht von ‹Name›, Stand ‹Zeit›». Read-only: nothing can be clicked or typed.
4. **Back and forth.** Every participant can send his own view the same way.
5. **End.** The sender ends the share, or it expires by itself.

## Why a DOM snapshot *(owner: DOM snapshot instead of an image)*

| Variant | Verdict |
|---|---|
| **DOM snapshot** (this idea) | Sharp at every zoom, a few hundred KB at most, text can be copied, no browser prompt, shows open windows and unsaved input. |
| Screenshot (`getDisplayMedia`) | The browser asks every time, the user can pick the wrong tab; large images. |
| Live video (WebRTC) | Needs signalling and a TURN server — neither exists on shared hosting. |
| Sharing a link | Shows only the saved state; open windows are not part of the URL. |

## What the snapshot contains

- The document as it stands: page, open windows and dialogs, scroll positions.
- Field state written into the markup before sending: `value`, `checked`, `selected`, textarea text.
- Stylesheets and images by their same-origin URL, not inline — the receiver's browser loads them
  with his own session, so the usual access checks apply.

Removed in the browser **and again on the server**: `<script>`, inline event handlers, `javascript:`
URLs, CSRF tokens, hidden inputs, password fields, and anything marked not to be shared
(*proposal:* an attribute such as `data-share-mask` on a field or a block).

## Rules (proposals — to be decided)

1. **Only invited users.** A share names its participants; every request checks the logged-in user
   against that list. No public link, no token in a URL.
2. **The snapshot is foreign HTML** — written by one client, shown to another admin: the textbook
   stored-XSS case (Rule 7). Cleaning is not the barrier; the barrier is the display: a frame with
   `sandbox` and without `allow-scripts`, served with its own strict CSP (no script, no form target,
   styles and images from the own origin only).
3. **The receiver sees what the sender sees** — also data his own rights would not show him. To be
   decided: invite only users of the same role (*proposal, as an option*), or warn the sender first.
4. **Short-lived.** Only the latest snapshot of a share is kept; a share expires (*proposal:* 15
   minutes without a new snapshot) and a job removes it.
5. **Logged.** Who shared with whom and when — not the content.
6. **Limits.** Maximum size per snapshot (*proposal:* 2 MB) and a rate limit per user.
7. Logic and authorisation on the server; the JavaScript only collects and sends.

## Not part of the idea

Remote control, the other user's cursor, typing into the sender's form, sound.

## Open questions

- Storage: one file per share under `data/` like other records, or `var/` because it is throw-away
  state (and must stay out of the «Daten» backup)?
- Content the DOM does not carry: `<canvas>`, PDF previews and other embedded frames show empty.
- Mandators: may a share cross a mandator boundary?
- Two users editing the same record: the framework refuses one editable field in two open masks
  inside ONE browser (WIN-001); what protects across two users (optimistic versions on save) was
  not checked for every editor.
- Later: the same for an installation's staff with a support user of the operator?

## Effort

Rough guess from the originating session, **without reading the framework code**: 3–5 days for
inviting, sending and looking; 1–2 days more for «Zuschauen». The risk sits in rule 2 (the sealed
display) and in serialising the windows faithfully. To be re-estimated against the code before a
decision.
