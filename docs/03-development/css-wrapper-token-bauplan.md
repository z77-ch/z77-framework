# css-wrapper-token-bauplan.md — Tokens auf den viewArea-Wrapper (Vorstufe zum DMS-Umbau)

**Status:** C0–C2 abgeschlossen (2026-06-22) → **PAUSE 1: visueller Test ausstehend**, dann DMS.
**Datum:** 2026-06-22
**Bindender Entscheid:** [`../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md`](../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md)
**Konvention:** [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) §3 + §7
**Folge-Plan:** [`dms-umbauplan.md`](dms-umbauplan.md) (startet erst NACH diesem Plan + Test)

> **Warum zuerst:** Die DMS soll als gekapseltes, eingebettetes Fragment (`.dms`) überall
> gleich aussehen und vom Frontend optional überschreibbar sein. Das trägt nur, wenn jede
> viewArea ihre Tokens auf einem eigenen Wrapper deklariert statt global auf `:root`. Also:
> erst Frontend + Backend auf Wrapper-Tokens umstellen und testen, dann DMS bauen.

---

## Prinzip (Kurzfassung)

- Tokens auf den viewArea-Wrapper: `.fe` (frontend), `.be` (backend), später `.dms`.
- Jeder Wrapper deklariert ein **vollständiges** Token-Set (sonst erbt ein fehlender Token
  den Host-Wert).
- Global auf `:root` bleibt nur: `@font-face` (eindeutige Family-Namen) + `color-scheme` +
  ggf. `<html>`-Hintergrund.
- Komponenten-Selektoren bleiben unverändert — sie lösen `var()` am Wrapper auf.
- Komponenten-*Klassen*-Isolation ist eine separate Achse (erst bei der DMS relevant, per
  `.dms-`-Prefix).

---

## Ausgangslage (Ist)

| Bereich | Tokens heute | Wrapper-Element heute |
|---|---|---|
| Frontend | `:root` in 4 Token-Partials (`_colors`, `_typography`, `_spacing`, `_effects`) | `<html lang>` / `<body>` — **keine** Klasse |
| Backend | `:root` für `--color-*` + `--be-*`-Default; Theme/Palette-Overrides via `[data-be-theme]` / `[data-be-palette]` auf `<html>` | `<html data-be-* >` + `<body class="backend">` |

Befund Backend: das Theme-/Palette-System scoped seine `--be-*`-Tokens **bereits** auf
`<html data-be-*>`. Nur der generische `--color-*`-Block liegt noch auf `:root`. Backend ist
also halb am Ziel — der Umbau formalisiert nur den Wrapper und zieht `--color-*` mit.

---

## Pausen-Regel

Jeder Phasen-Endpunkt ist ein lauffähiger Zustand (deploybar). Pausen entstehen nur, weil
der Entwickler nicht anwesend ist — nicht weil der Code unfertig wäre. **PAUSE+TEST**-Punkte
markieren, wo der Entwickler das System visuell prüfen muss, bevor es weitergeht.

---

## Phasen

### C0 — Konzept + Doku ✅
- [x] ADR-018 erstellt.
- [x] `css-conventions.md` §3 + §7 auf Wrapper-Tokens umgestellt.
- [x] Dieser Bauplan.

### C1 — Frontend auf Wrapper-Tokens ✅ (2026-06-22)
Dateien:
- `packages/module-frontend/res/scss/tokens/_colors.scss` — `:root {` → `.fe {`
- `packages/module-frontend/res/scss/tokens/_typography.scss` — `:root {` → `.fe {`
- `packages/module-frontend/res/scss/tokens/_spacing.scss` — `:root {` → `.fe {`
- `packages/module-frontend/res/scss/tokens/_effects.scss` — `:root {` → `.fe {`
- `packages/module-frontend/res/view/templates/html-default-skeleton.tpl.php` — `<html …>` → `class="fe"`
- `packages/module-frontend/res/view/templates/html-fetch-skeleton.tpl.php` — Wrapper `.fe` ergänzen (Modal-/Fetch-Inhalt muss die Tokens auch sehen)

Schritte:
1. `@font-face` / `color-scheme` (falls in einem Token-Partial) auf `:root` belassen; nur die `--*`-Token-Blöcke in `.fe` verschieben.
2. Token-Set auf Vollständigkeit prüfen (alle in Komponenten genutzten `var(--…)` müssen in `.fe` definiert sein).
3. SCSS neu kompilieren → `res/assets/css/*.css`.
4. Topic-Doc [`../topics/css-frontend.md`](../topics/css-frontend.md) nachführen, `npm run docs:check` grün.

Done-Kriterium: Frontend rendert **unverändert**; alle Tokens lösen unter `.fe` auf.

### C2 — Backend auf Wrapper-Tokens (inkl. Theme/Palette-Abgleich) ✅ (2026-06-22)
Dateien:
- `packages/module-backend/res/scss/tokens/_colors.scss` — `:root { --color-* ; --be-* default }` → `.be { … }`; `[data-be-theme="dark"]` / `[data-be-palette="…"]`-Overrides **behalten** (Quell-Reihenfolge: Defaults zuerst, Overrides danach)
- `packages/module-backend/res/scss/tokens/_typography.scss` — `:root {` → `.be {`
- `packages/module-backend/res/scss/tokens/_spacing.scss` — `:root {` → `.be {`
- `packages/module-backend/res/scss/tokens/_effects.scss` — `:root {` → `.be {`
- `packages/module-backend/res/view/templates/html-default-skeleton.tpl.php` — `class="be"` auf `<html>` (trägt schon `data-be-*`)
- `packages/module-backend/res/view/templates/html-fetch-skeleton.tpl.php` — Wrapper `.be` ergänzen

Schritte:
1. `--color-*`- und `--be-*`-Default-Block nach `.be` ziehen; Palette-/Theme-Selektoren so lassen (sie sind gleich spezifisch und stehen später in der Quelle → gewinnen weiterhin). Bei Bedarf auf `.be[data-be-palette="…"]` schärfen.
2. `.be` und die `data-be-*`-Attribute liegen am selben `<html>` → Vererbung passt.
3. Vollständigkeit des Token-Sets prüfen (auch `--be-*`).
4. SCSS neu kompilieren.
5. Topic-Doc [`../topics/css-backend.md`](../topics/css-backend.md) nachführen, `docs:check` grün.

Done-Kriterium: Backend rendert identisch in **allen Paletten × light/dark**; Modals (Fetch),
Subnav, Login unverändert.

---

### ⏸ PAUSE 1 — TEST CSS-Umbau

Der Entwickler prüft visuell, BEVOR die DMS startet:
- [ ] Frontend: alle Seiten, Sprachen-Switch — Styling unverändert.
- [ ] Backend: jede Palette (citrus, coral, lagune, beere, sonne) × light/dark.
- [ ] Backend-Modals (Fetch-Skeleton), Subnav, Login, Flash/Popup-Messages.
- [ ] Keine ungestylten/„nackten" Elemente (Hinweis auf fehlenden Token im Wrapper-Set).

Erst nach Freigabe → DMS-Umbau.

---

## Danach: DMS-Umbau

Ab hier gilt [`dms-umbauplan.md`](dms-umbauplan.md) (Phasen R1–R7). Die CSS-Konvention ist
jetzt **Voraussetzung**: Das neue `module-dms` deklariert ein vollständiges `.dms`-Token-Set
und prefixt seine Komponenten mit `.dms-` (gegen Klassen-Kollision im individuellen
Frontend). Eigener **PAUSE+TEST**-Punkt am Ende des DMS-Umbaus laut jenem Plan.

---

## Was bleibt / was ändert (Überblick)

| Komponente | Aktion |
|---|---|
| Token-Partials frontend/backend (je 4 Dateien) | **ändern** (`:root` → Wrapper) |
| Skeletons frontend/backend (default + fetch, je 2) | **ändern** (Wrapper-Klasse) |
| Komponenten-SCSS (`components/*`, `layout/*`) | **bleibt** (nutzen `var()`) |
| Backend Theme/Palette-System (`[data-be-*]`) | **bleibt** (nur Scope-Abgleich) |
| `@font-face` / `color-scheme` | **bleibt** auf `:root` (Family-Namen eindeutig) |
| Topic-Docs `css-frontend.md` / `css-backend.md` | **nachführen** je Phase |

## see also

- [`dms-umbauplan.md`](dms-umbauplan.md) — Folge-Umbau; nutzt `.dms`-Wrapper + `.dms-`-Prefix
- [`../01-handbook/css-conventions.md`](../01-handbook/css-conventions.md) — autoritative Konvention (§3/§7)
