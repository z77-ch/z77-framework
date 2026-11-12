# Invoice Layout Settings — the customer adjusts her invoice herself

**Status:** `[IDEA]`
**Date:** 2026-11-12
**Context:** Brainstorming with the owner. Starting point: «a layout bot in the backend
changes the invoice layout»; the result: a guided settings screen with a live preview —
no AI needed.

---

## Problem

A customer (example: «Lulu») wants to adjust her invoice without a developer:

- hide the column «Einheit»
- left margin 15 mm
- invoice number in bold
- font «xy»

Today every such wish is developer work: the invoice is a PHP template
(`module-debtor/res/view/templates/pdf/invoice.tpl.php`), changed per project under
`override/` and deployed.

## Direction (owner, 2026-11-12: «das ist der machbare Weg»)

**Layout = data, not code.** The wishes are settings, not free design:

| Wish | Setting |
|---|---|
| Einheit weg | columns on/off |
| linker Rand 15 mm | margins in mm |
| Rechnungsnummer fett | emphasis per field |
| Schrift xy | font from a fixed, embeddable list |

**Screen:** a form on the left, the PDF preview on the right, refreshed on every change —
WYSIWYG in effect, guided, nothing to break. Stored under `data/` with versions (as content
versions), so a change can be undone.

**Fixed zones the settings never move:**

- the address block — it must sit in the envelope window;
- the QR payment part — size and position are fixed by the Swiss Payment Standards;
- fonts — only a vetted list (embedding licence in the PDF).

## Considered and set aside (2026-11-12)

- **AI layout bot + handoff to the developer's PC + SSH upload.** Pull from the PC is
  sound, but: a single-file upload bypasses the release rule (`releases/<name>`, never over
  `current`) and leaves the server out of step with git; customer text would steer an agent
  that holds SSH keys (prompt injection). Kept only as an option for real code changes,
  through the normal repo → commit → release path.
- **The server replaces template files itself.** The web process would need write access
  to its own code — a backend compromise becomes remote code execution. Code on the server
  stays read-only for the web.
- **An uploadable, logic-free template** (`*.layout.html` with placeholders, rendered as
  data, never `include`d). Feasible if enforced technically (no PHP, auto-escaping, a
  renderer that loads no external URL or local file), but the settings screen covers the
  customer's wishes without it.
- **A free drag-and-drop designer.** A product of its own; it would let the address leave
  the envelope window and break the QR part.

**AI later, optional:** translate «Logo nach rechts» into these settings — only once the
settings exist. The backend KI-Assistent ([`../../topics/kibot.md`](../../topics/kibot.md),
ADR-055) would be the natural place.

## Open questions

- ~~How is the PDF drawn today?~~ Answered: through the `PdfDocument` facade (FPDF, by
  coordinates — [`../../topics/pdf.md`](../../topics/pdf.md)); `invoice.tpl.php` draws
  through `$pdf`, it is no HTML. Settings (margins, columns, font, emphasis, logo position)
  fit this model directly; an uploadable HTML template would not.
- Where do the settings live — per mandator? (one installation, several companies)
- Which logic in today's template must move into PHP first (values computed before the
  layout, not in it)?
- Which fonts, with which licence?
- Do dunning notices and other PDFs (`dunningNotice`, `documentSummary`) share the settings?
- Can the content versioning be reused, or does it need its own?
