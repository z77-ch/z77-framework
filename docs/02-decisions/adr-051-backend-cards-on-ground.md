# ADR-051 — Backend look: blocks as cards on the window ground, quiet inputs

**Status:** `[APPROVED]` — the direction, owner 2026-10-16 («deine Gestaltung gefällt mir besser»,
«ja» to the backend-wide scope); the exact mix values are confirmed on the board before building
**Date:** 2026-10-16
**Builds on:** [ADR-018](adr-018-css-tokens-scoped-to-viewarea-wrapper.md) (tokens on `.be`),
[`backend-screen.md`](../01-handbook/backend-screen.md) (Rule 8), `css-backend.md` (palettes × light/dark)

---

## Context

The order window was redrafted as a clickable mockup (2026-10-16, `order.md`). The owner judged
its look clearer than the current backend — and the debtor area «just as unattractive» today.
What makes the difference is not the order layout but three general traits:

- The window ground and the blocks on it differ visibly. Today `--be-bg` and `--be-surface` are
  almost the same (Werkbank `#f3f4fb` / `#fafbff`), so a form reads as one flat field.
- Inputs sit on a slightly tinted ground of their own, between ground and card.
- Inputs inside a table are borderless at rest; the row reads as data, the border appears on
  hover and focus.

The backend has a palette picker (six palettes) and light / dark. Any new tone must follow both;
fixed hex values from the mockup would break every palette but one.

## Decision

1. **Blocks are cards on the window ground** — backend-wide, every area. A form section, a list,
   a totals block: `--be-surface2` with a 1px `--be-line` border and radius 8px, on `--be-bg`.
   Gap between cards, no per-element margins.
2. **Three new derived tokens**, declared ONCE on `.be` next to `--be-confirm`, so every palette
   and both themes get them:

   | Token | Role | Derivation (start value, confirmed on the board) |
   |---|---|---|
   | `--be-field` | input ground | `color-mix(in srgb, var(--be-bg) 50%, var(--be-surface2))` |
   | `--be-line-strong` | table head rule, tree connectors | `color-mix(in srgb, var(--be-line) 75%, var(--be-muted))` |
   | `--be-row-tint` | a secondary row (text line, sub row) | `color-mix(in srgb, var(--be-bg) 60%, var(--be-surface2))` |

3. **Table inputs are quiet**: transparent ground and border at rest, `--be-line` border on
   hover, `--be-field` ground + accent border on focus. Numbers right-aligned, tabular figures,
   the existing mono token.
4. **Unchanged framework rules** win over the mockup: «Speichern» is the accent
   (ADR-033 addendum 2026-10-16), the font stays Inter, actions stay in the `modalActions` row (ADR-049).
5. **Order:** the tokens and card rule go into `module-backend` first — every area gets the look
   at once; the order window is the first screen that also gets the new layout.

## Reasoning

- A change of tokens and one card rule reaches every area at once; per-screen styling would
  drift (Rule 9: module-agnostic).
- Deriving from `--be-*` keeps `_colors.scss` the single source of truth — no palette needs new
  hex values, and dark mode follows by itself.
- Visible cards give the eye an order: what belongs together sits on one card.

## Consequences

- `_colors.scss`: three tokens; `_forms.scss` / list components: the card and quiet-input rules.
- Every area changes appearance in one step — the owner's browser look covers one screen per
  module, all six palettes and dark.
- `backend-screen.md` §4 carries the card rule for new areas.
- Embedded fragments with host bindings (`_dms-host.scss`, `_split-host.scss`) are checked
  against the new tokens.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Restyle only the order window | The debtor area has the same problem; one screen looking different breaks «one look per kind» |
| Take the mockup's hex values | Works for one palette in one theme; the picker and dark mode would break |
| Lighten `--be-surface` instead of a new card rule | Changes every surface at once, including the shell; the card is a role, not a palette value |

---

## Addendum 2026-10-17 — the draft IS the target look (owner)

The first boards (developer canvas versions 21 and 23) mapped the draft onto the existing
Werkbank palette (indigo) and Inter. Owner: «es entspricht überhaupt nicht dem Artefakt» — the
draft's look itself is wanted, not its layout on today's tokens. Decided (owner answers 2026-10-17):

1. **The draft's colours become a palette of their own and the DEFAULT palette** — working name
   **Salbei** (neutral sage ground, dark green accent). Light from the draft's `:root`, dark from
   its dark block, mapped onto the `--be-*` roles (`--bg` → `--be-bg`, `--sheet` → `--be-surface2`,
   `--fg` → `--be-text`, `--muted`, `--line`, `--accent`, `--accent-soft`, `--warn`). The other six
   palettes stay in the picker. Replaces «Take the mockup's hex values — rejected» for this one
   palette: they become a palette, not fixed values in components.
2. **Font: the draft's faces** — Instrument Sans (UI) and IBM Plex Mono (numbers), **self-hosted**
   under module-backend's assets (SIL OFL, licence file alongside; no Google Fonts at runtime —
   Rule 7 / CSP own origin). Replaces decision 4's «the font stays Inter».
3. **The draft's forms, backend-wide** (one look per kind, Rule 8/9): blocks as cards (radius 8,
   1px line) on the window ground; block titles small caps-style — uppercase, letter-spaced, bold,
   in the accent; inputs radius 5 on the field ground; table inputs quiet (decision 3); status as
   a pill (accent-soft ground, accent text); numbers in the mono face, tabular, right-aligned.
4. Unchanged: «Speichern» is the accent (ADR-033 addendum 2026-10-16), actions in the
   `modalActions` row (ADR-049), the derived tokens of decision 2 (for Salbei they reproduce the
   draft's `--field`, `--line-strong`, `--text-line` within a shade).
5. Open from the first board (2026-10-17): in Citrus, Lagune and Sonne light the card
   (`--be-surface2`, tinted) barely differs from the ground; Citrus and Sonne accents carry white
   text poorly. Owner to decide after seeing Salbei built.
