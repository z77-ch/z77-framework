# Invoice Intake Automation — incoming invoices recognised, assigned and posted from a folder

**Status:** `[IDEA]` — recorded by the owner 2026-11-13 («halte die idee fest und dokumentiere sie»)
**Date:** 2026-11-13
**Context:** Brainstorming with the owner after the order-tab split. Starting point: «wie gut
erkennst du Rechnungen und kannst daraus Regeln erstellen, so dass wiederkehrende Rechnungen
sofort erkannt werden und einem Fibukonto zugeordnet werden können?»

---

## Problem

Incoming invoices arrive as PDF and are assigned to a ledger account by hand, month after month —
the same suppliers, the same accounts, the same VAT codes. The work is repetitive and the
assignment of a recurring invoice is known after its first time.

## The owner's flow (2026-11-13)

1. An invoice arrives as PDF; the owner drops it into a fixed folder.
2. At month end he starts the intake: the folder is read, the invoices sorted and assigned.
3. The result is a booking list he can change.
4. «Hochladen und buchen»: the entries go to the server together with the PDFs.
5. The rules learnt for newly recognised invoices are kept.

## Sketch — built on module-receipt

Most of the flow exists ([`../../topics/receipt.md`](../../topics/receipt.md)):

| Owner's step | Exists as |
|---|---|
| the changeable booking list | the **Belegjournal** — review, correct, split, «Journal verbuchen» up to a cut-off date |
| the rule «this invoice → account» | a **Belegvorlage** (`ReceiptTemplate`: debit account + VAT code) |
| the credit side | a **Zahlungsart** (`PaymentMethod`: Kasse, Firmenkarte, …) |
| the PDF kept with the entry | the DMS, `Belege/<year>/<month>` (`ReceiptCaptureService`) |
| a Claude API client with a stored key | `packages/kibot` (`AnthropicClient`, `KeyStore`) — the backend assistant uses it |

New would be: the intake of the PDFs, the recognition, and the rule store.

### Recognition — rules first, AI only for the unknown

- **Recurring invoices without AI.** A Swiss QR-bill carries creditor IBAN, creditor name,
  amount and reference machine-readably in its QR code. A rule «IBAN (or UID) X → template Y»
  matches deterministically, costs nothing and is reproducible.
- **AI only for a new or unclear invoice.** Claude reads the PDF (text layer; a scan via the
  image), proposes supplier, date, amount, VAT, debit account and VAT code; the owner confirms in
  the list; the confirmation becomes the new rule.
- **The human decides.** Nothing is posted without the reviewed list — the AI proposes, the
  owner books (GeBüV: the PDF is the record, kept unaltered).

### Intake — the browser reads the folder

A browser cannot open a local path by itself. Three ways, discussed with the owner 2026-11-13:

1. **Folder access (Chrome / Edge, File System Access API).** «Ordner wählen» once — the
   browser keeps the handle; afterwards «Ordner einlesen» reads every PDF of that folder and
   uploads it (one «Zugriff erlauben?», or «bei jedem Besuch erlauben»). With write permission
   the page can move uploaded files into a subfolder «hochgeladen» so nothing comes twice. The
   page sees that one folder only; HTTPS required. Revoked in Chrome › Website-Einstellungen.
2. **Fallback for every browser:** drag and drop / folder picker (`webkitdirectory`) — chosen
   anew each time.
3. **Mailbox** (`belege@…`): z77 fetches the PDF attachments itself — no folder, no upload.
   Worth it when most invoices arrive by e-mail.

Recommendation: 1 with 2 as fallback; 3 later if the invoices mostly come by mail.

Rule 7 (JavaScript): the page only reads and uploads; the server sniffs every file (PDF only),
recognises, applies the rules and re-checks every value — the browser decides nothing.

## Scope — paid invoices first, creditors next stage

- **This idea:** invoices booked as PAID (card, direct debit, cash — the credit side is a
  payment method) → the receipt journal is enough.
- **Next stage (owner 2026-11-13, a module project of its own):** a **creditor module** —
  invoices booked as OPEN items first (expense to creditors), paid later (payment run, pain.001,
  matching). The intake and the rules of this idea would feed it.

## Open questions

- Where do the rules live — a new master data «Lieferantenregel» (IBAN / UID / name → template,
  payment method) or a field on the contact (supplier profile)?
- A receipt today has ONE amount; an invoice with several VAT rates → split in the journal
  (exists) or recognised as split lines?
- Duplicate detection: the same invoice uploaded twice (QR reference / number + supplier).
- Cost and quota of the AI calls per month; what goes to the API (PDF of unknown invoices only).
- Data protection: the PDFs of unknown invoices go to the Claude API — to be stated in the
  installation's privacy notes.
- Where the intake screen lives: in the Belegjournal («Rechnungen einlesen …») or its own screen.

## Dependencies

- module-receipt (the journal, templates, payment methods, DMS storage)
- kibot's Anthropic client (shared, or a slim client for the recognition)
- a QR-bill reader (QR code from the PDF — library or own decoder, to be evaluated)
