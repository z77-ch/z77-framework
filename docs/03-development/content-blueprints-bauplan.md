# content-blueprints-bauplan.md — Feste, benannte Block-Struktur pro Content-Dokument

**Status:** GEBAUT auf dem Branch, Schritte 1–4 (2026-09-21); offen: Merge + Pilot — Branch `feat/content-blueprints` (Worktree
`../z77-ch-framework-content-blueprints`, parallel zur laufenden VAT-Arbeit auf `main`).
Entscheid: [`../02-decisions/adr-044-content-blueprints.md`](../02-decisions/adr-044-content-blueprints.md).
Topic-Doc: [`../topics/content.md`](../topics/content.md).
Erster Konsument: zihlundsee.ch, Seite «Gut zu wissen» (Handoff
`work/docs/handoff-structured-content-2026-09-21.md` im Projekt).

## Ausgangslage (verifiziert 2026-09-21)

- `Content` (slug, language, title, active, blocks[]) in `data/content/<slug>.<lang>.json`,
  Document-Store, atomar geschrieben (tmp + rename).
- `BlockRenderer::schema()` (ADR-011) → `BlockRegistry::schemas()` → Editor-Formulare
  (`edit.tpl.php` Closures + `content/editor.js`). Freier Block-Strom: add/remove/reorder.
- `ContentValidator::validateBlocks()` prüft nur JSON + bekannte Typen, keine Feldwerte.
- `InlineMarkdown::toHtml()` wendet fett/kursiv/Link auf jedes Feld an; `safeUrl()` lässt
  `//host` durch (erstes Zeichen `/`) — widerspricht der content.md-Regel «protocol-relative
  MUST be rejected». Wird mitkorrigiert.
- `editor.js::readBlock()` schreibt nur `type` + Schema-Felder zurück — ein `key` am Block
  ginge beim Speichern verloren.
- `ContentController` speichert ohne `entity_hash`; `EntityStateHash` + `guardStoredState()`
  existieren (Muster `NavigationController`: Validator VOR `mapFromArray` bauen, Guard, dann mappen).
- Tests sind Einzeldatei-Harnesses (`php tests/<name>.php`, `require` der Klassen, kein DI).

## Design-Entscheide

1. **Blueprints = Modul-Config `contentBlueprints`** (slug ⇒ Liste von Slots
   `{key, type, label}`), **Actions = Modul-Config `contentActions`** (name ⇒
   `{href, attributes}`). Beide eingesammelt von `ContentExtensions::assemble()` (walkt die
   Module wie `BlockRegistry::assemble()`, kein DI-Service — ADR-012).
2. **Schema-bewusster Lesepfad `ContentView`** (`ContentService::view($slug, $lang)`):
   hält Content + Schemas + Actions + Sprache; `keyed($key)` liefert ein `BlockView` MIT
   Schema. Nur dort gilt das Feld-Profil (Standard: reiner Text).
   **Der alte Pfad bleibt unverändert** (`InlineMarkdown::toHtml($text)` ohne Profil,
   `BlockView` ohne Schema, bestehende Renderer) — keine Migration bestehender Sites.
3. **`InlineProfile`** aus dem Descriptor: `inline` (bold|italic|link|break), `links`
   (`targets` page|media|external|mailto|action, `localize` bool). `localize` → Seitenlink
   durch `localizedUrl($url, <Dokumentsprache>)`; `/media/…`, extern, mailto nie.
   `action:<name>` → `<a href="<localized fallback>" <attributes>>`; unbekannte Action oder
   nicht erlaubtes Ziel → Literaltext.
4. **Slot-Durchsetzung serverseitig** (`Blueprint::enforce()`): gespeichert wird genau ein
   Block pro Slot in Slot-Reihenfolge, `type` + `key` vom Slot erzwungen, Werte aus dem POST;
   Blöcke ohne passenden Slot kommen aus dem GESPEICHERTEN Dokument (nicht aus dem POST) und
   bleiben erhalten.
5. **Feldvalidierung** (`BlockSchemaValidator`): required, maxLength, list min/max, URL-Schema
   bei `kind: url`. Meldungen gesammelt als Feldfehler `blocks` (Slot-Label + Feld-Label).
6. **Editor-Blueprint-Modus**: Karten in Slot-Reihenfolge mit Slot-Label, ohne ↑/↓/×, ohne
   «Block hinzufügen»; Listen tragen `data-min`/`data-max` (JS blendet + / × aus); Hinweis pro
   Feld, welche Formatierung erlaubt ist. `data-key` an jeder Karte, `readBlock()` schreibt
   `key` zurück (auch im freien Modus).
7. **Sperre**: `entity_hash` im Formular, `guardStoredState()` vor dem Mappen.

## Schritte

1. Reine Klassen + Harness `tests/content-blueprints.php`: `InlineProfile`, `InlineMarkdown`
   (Profil + `//`-Fix), `BlockSchemaValidator`, `Blueprint` (arrange/enforce), `BlockView`
   (Schema), `Content::keyed()`.
2. `ContentExtensions`, `ContentView`, `ContentService::view()`.
3. Backend: `ContentController` (Blueprint, Sperre, Feldvalidierung), `edit.tpl.php`,
   `editor.js`, `editor.css`, `ContentValidator`.
4. Doku: `topics/content.md`, `topics/block-types.md`, Idea-Doc-Notiz, `npm run docs:check`.
5. Merge in `main`, sobald die VAT-Arbeit dort committet ist; dann Pilot im Projekt.

Stand 2026-09-21: 1–4 erledigt (Commits `content-blueprints: Teil 1/2/3`), Harnesses
`tests/content-blueprints.php` (31) + `tests/content-editor-template.php` (14) grün,
`docs:check` ohne neue Verstösse. Noch nicht im echten Backend geklickt — das geschieht
mit dem Pilot.

## Offen

- Backend-Liste: Blueprint-Dokumente markieren / fehlende Slugs anbieten (später).
- Datei-Werkzeug Pull/Push mit Prüfsumme: zuerst im Projekt (zihlundsee).
