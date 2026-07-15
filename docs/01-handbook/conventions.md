# Conventions

**Status:** `[CURRENT]` — config principle, file names, namespaces, JS, view layer, persistence documented
**Last updated:** 2026-06-05

---

## Configuration — semantically discoverable single source

Every configuration value lives at **one** source of truth, at a place whose **name
matches what the value is about**. This is not cosmetic — it is a hard rule, for two
reasons that increasingly carry the same weight:

1. **Single source.** A value defined in two places drifts. Changing it must mean
   editing exactly one line, not hunting N files and risking an inconsistent state.
2. **Discoverability by name.** Configuration is increasingly changed by AI
   assistants and by successor developers who do not know the codebase. Whoever is
   asked to "switch the language" searches for `language` / `locale` / `i18n` — so
   that value MUST live under a semantically named config (`i18n`), **not** be
   hidden inside an unrelated service config (`moduleManager`). A name that does not
   announce its content is effectively unfindable.

**Rules:**

- A new global setting → its own semantically named config namespace (or an existing
  one whose name already covers the domain). Never park it in an unrelated config
  just because that file happens to be global.
- The same value MUST NOT be repeated. A scope that deviates from a global default
  records **only the deviation** (override), never a copy of the global value — see
  the `i18n` / per-module `languages` pattern in
  [ADR-013](../02-decisions/adr-013-i18n-language-configuration.md), mirroring the
  `viewArea` / `public` flags from ADR-005.
- Generated config files (installer output, e.g. `moduleManager.inc.php`,
  `i18n.inc.php`) are NOT hand-edited — values come from the installer-config source,
  because the generated file is ephemeral (regenerated on reinstall).

First applied in ADR-013 (i18n). A framework-wide audit of whether this is carried
through everywhere is tracked in [roadmap.md](../03-development/roadmap.md).

---

## Reusability — build module-agnostic

The framework exists to be reused: it will grow further modules (client projects). So a
recurring pattern (list rows, action hubs, header slots, …) MUST be built as a **shared,
module-agnostic building block** — never hard-wired into a single module or controller.

**Rules:**

- A repeating UI/behaviour pattern lives in a **shared component** (generic CSS class,
  partial, trait, convention loader), not in one view. Prefer an **opt-in modifier** on a
  container (e.g. `be-tree--hub` on a `.be-tree`) over a bespoke per-view style.
- When prototyping a pattern on one view, cut it so the **roll-out to the next module is
  "drop a file + set the modifier"** — no copy-paste of logic.
- Controller-side repetition (e.g. a per-row action endpoint) → a **convention** (auto-loaded
  partial) or a **trait**, so a new module inherits it without boilerplate. Mirrors the
  shell header-slot auto-loader (`loadHeaderSlots()`) and the tree move in
  `AbstractTreeEntityController`.
- Naming stays generic (`be-*`, not `navigation-*`) so a class/partial reads as framework
  infrastructure, not one screen's private markup.

---

## File Names

Casing follows the layer a name belongs to — never by taste, never ad-hoc. Rule of
thumb: **anything reachable through a URL or the browser is kebab-case; PHP is
PascalCase/camelCase; JSON persistence keys are snake_case.**

| Artefact | Casing | Example |
|---|---|---|
| Web-served asset file (`.js` / `.css`) | kebab-case, lowercase | `password-meter.js`, `nav-desktop.css` |
| Asset subfolder | kebab-case, lowercase | `login-user/`, `navigation-group/` |
| URL segment | kebab-case, lowercase | `/backend/system/login-user/list` |
| CSS class (BEM) | kebab-case | `login__box`, `btn--primary` |
| `data-*` attribute / `scriptInit` key | kebab-case | `data-z77-password`, `_Z77.scriptInit['password-meter']` |
| PHP namespace / class | PascalCase | `LoginUserController` |
| PHP method / property | camelCase | `passwordWeak`, `getSortKey()` |
| JSON persistence key | snake_case | `password_hash`, `sort_key` |
| Template file | `{action}Action.tpl.php` (action in camelCase) | `setupAction.tpl.php` |

### Asset files: why kebab-case, lowercase

Asset files are delivered under a URL (`/assets/backend/js/password-meter.js`), so
they follow the URL-segment rule. Two concrete reasons, not preference:

1. **Case-sensitivity safety.** `passwordMeter.js` works on case-insensitive
   Windows but breaks after deploy to case-sensitive Linux the moment a reference's
   casing drifts. All-lowercase eliminates this entire class of "works locally,
   404 in production" bugs.
2. **Name = key.** The file name is not cosmetic — it is derived into runtime keys:
   `core.js` → `_Z77.core`; `navigation/edit.js` → the lazy-load init key
   `_Z77.scriptInit['navigation-edit']`; `password-meter.js` → the `load-script`
   init `'password-meter'`. The folder+file path maps 1:1 to the kebab key.
   Renaming the file in another style breaks that derivation.

**MUST (JS):** every web-served JS file ships its minified sibling next to the
source — `{name}.js` + `{name}.min.js`. Production loads `.min`, debug loads the
plain file (`JavascriptManager`, see [JavaScript → Minification](#minification)).
A missing `.min.js` is a production 404, not a cosmetic gap.

**CSS has no `.min` sibling.** The SCSS build emits a single, already-compressed
`{name}.css` (`npm run build`, `--style=compressed`) and `StylesheetManager`
always serves `{name}.css` — no min suffix, no debug branch. CSS compression
happens at build time, in place; JS minification is a separate `.min.js` file.

## Icons (SVG sprite)

Backend icons are defined once as `<symbol>` in the sprite partial
(`partials/icon-sprite.tpl.php`, loaded once per page via the layout `iconSprite`
section) and referenced via `<use>`. Never inline an `<svg>` path in a template.

| Artefact | Rule | Example |
|---|---|---|
| Symbol id | `icon-{name}`, kebab-case, lowercase | `icon-chevron-down`, `icon-edit` |
| Icon name | semantic, not glyph-internal | `edit` (not `square-pen`), `close` (not `x`) |
| Markup | `<svg class="be-icon" width=".." height=".."><use href="#icon-{name}"/></svg>` | |

- `viewBox` is uniform `0 0 24 24` (Lucide grid) — required so every `<use>` aligns.
- Presentation (stroke, fill, `currentColor`, caps) lives once in `.be-icon`
  (`components/_icon.scss`); symbols carry geometry only. Display size comes from the
  consuming `<svg>` `width`/`height`.
- One glyph per concept — do not add a second variant of an existing icon. Adding a
  new icon means adding one `<symbol>` to the sprite, nothing else.

---

## Namespaces

PHP namespaces mirror the URL schema (see ADR-005).

```text
URL:        /{module}/{group}/{controller}/{action}
Namespace:  Z77\Module\{Module}\Ui\Controllers\{Group}\{Controller}Controller
```

`BackendAbstractController` (and any other abstract base controller) stays flat at
`Z77\Module\Backend\Ui\Controllers\` — abstract bases have no group affiliation.

### Group Naming

| Element | Casing | Example |
|---|---|---|
| URL segment | kebab-case, ASCII, lowercase | `user-management` |
| PHP namespace component | PascalCase, ASCII | `UserManagement` |
| Config key in `groupDefaults` | matches URL form | `'user-management'` |

`Naming::toCamelCase()` converts URL → namespace deterministically.

`group` is a UI/navigation namespace, NOT a business-domain boundary. It exists to
organise sidebar/topbar sections inside a module. Domain boundaries are expressed
through modules and their dependency DAG, not through groups.

---

## JavaScript

### Grundsatz — JS nur, wenn CSS oder Server es nicht abdecken

z77 verfolgt das Prinzip **so wenig JavaScript wie möglich**. Reihenfolge der bevorzugten Lösungen:

1. **CSS** — wenn der Effekt mit modernen CSS-Mechanismen (`:checked`, `:has()`, `:focus-within`, `:target`, container queries, view transitions, scroll-driven animations) erreichbar ist → kein JS.
2. **Serverseitig generiertes CSS zur Laufzeit** — für dynamische Werte aus Entities (z.B. CSS-only Slider, datengetriebene Layouts): via `LayoutManager::createCss()` ein versioniertes CSS-File rendern, das aus dem Cache geliefert wird, sofern verfügbar. Siehe [`docs/topics/stylesheet.md`](../topics/stylesheet.md#generated-css-data-driven).
3. **JavaScript** — nur dann, wenn weder (1) noch (2) reichen: echte Interaktion mit State, der nicht durch Form-Controls oder Page-Reload abbildbar ist (z.B. Fetch-Envelope, Live-Validierung, Map-Controls).

Beispiele für **bewusst CSS-only** in z77:
- Mobile Navigation Overlay → hidden `<input type="checkbox">` + `:has()` (kein `nav.js`)
- Sichtbarkeitsumschaltung von Topbar-Elementen per Breakpoint → Media Queries

Beispiele für **JS-berechtigt**:
- `_Z77.core.fetch` Envelope-Dispatch (Server-Antworten lokal anwenden)
- `_Z77.core.fields` Live-Validierung beim Blur (Server-Call ohne Page-Reload)

Bevor du eine JS-Datei anlegst, beantworte schriftlich (im PR / Commit-Message): _Warum geht das nicht mit CSS oder serverseitig generiertem CSS?_ Wenn die Antwort schwammig ist → kein JS.

### Namespace Convention

All JavaScript follows the pattern `_Z77.{library}.{task}`.

| Level | Example | Bedeutung |
|---|---|---|
| `_Z77` | `_Z77` | Framework root — einmal global |
| `_Z77.{library}` | `_Z77.core` | Eine JS-Einheit (core, backend, openlayers, …) |
| `_Z77.{library}.{task}` | `_Z77.core.fetch` | Eine aufgabenbezogene Einheit |

`library` entspricht dem Dateinamen der Hauptdatei (`core.js` → `_Z77.core`).
`task` entspricht dem Dateinamen bei ausgelagerten Dateien (`openlayers/controls.js` → `_Z77.openlayers.controls`).

### Datei-Aufbau (3 Guard-Zeilen + IIFE)

Jede JS-Datei beginnt mit denselben drei Zeilen:

```javascript
var _Z77 = _Z77 || {};
_Z77.core = _Z77.core || {};          // library-spezifisch anpassen

_Z77.core.flash = (function () {
    // private internals

    function show(type, text) { /* ... */ }
    function clear() { /* ... */ }

    return { show: show, clear: clear };
})();
```

Die Guard-Zeilen sind idempotent — mehrere Dateien derselben Library können parallel geladen werden ohne sich gegenseitig zu überschreiben.

### Abhängigkeiten innerhalb einer Library

Tasks in derselben Datei können sich direkt referenzieren — Reihenfolge der Definitionen bestimmt die Verfügbarkeit:

```javascript
// core.js
_Z77.core.flash = (function () { ... })();   // zuerst

_Z77.core.fetch = (function () {
    function _handleEnvelope(env) {
        _Z77.core.flash.show(m.type, m.text); // funktioniert: flash ist bereits definiert
    }
    return { post: post, get: get };
})();
```

### Multi-File Library

Grössere Libraries (z.B. OpenLayers) werden auf mehrere Dateien aufgeteilt — alle unter demselben Library-Namespace:

```javascript
// openlayers/map.js
var _Z77 = _Z77 || {};
_Z77.openlayers = _Z77.openlayers || {};
_Z77.openlayers.map = (function () { ... })();

// openlayers/controls.js
var _Z77 = _Z77 || {};
_Z77.openlayers = _Z77.openlayers || {};
_Z77.openlayers.controls = (function () { ... })();
```

Ladereihenfolge über `defer` und die Deklarationsreihenfolge im layoutConfig sicherstellen.

### Minification

In production wird `{name}.min.js` erwartet — generiert durch VS Code on Save (z.B. JS & CSS Minifier).
Debug-Modus lädt `{name}.js` direkt. `AssetVersionService` steuert den Suffix automatisch.

### Verzeichnisstruktur

**Shared** — modulübergreifender Code (flash/message/fetch envelope dispatch). Wird von jedem Modul über `nameSpace: 'Z77\\Shared'` im layoutConfig geladen.

```
packages/kernel/shared/res/assets/js/
├── core.js                   _Z77.core.flash, _Z77.core.message, _Z77.core.fetch, _Z77.core.wire
└── core.min.js
```

**Modul** — module-spezifischer Code (eigene UI, Form-Logik, Erweiterungen des shared core via `_Z77.core.fetch.registerEnvelopeHandler` / `registerCommand`).

```
res/assets/js/
├── appearance.js             _Z77.backend.appearance (Backend-Beispiel: Palette/Dark-Mode)
├── appearance.min.js
├── system/
│   ├── cache.js              _Z77.backend.cache
│   └── cache.min.js
├── navigation/
│   ├── edit.js               _Z77.scriptInit['navigation-edit'] — lazy-loaded via load-script
│   └── edit.min.js
└── openlayers/
    ├── map.js                _Z77.openlayers.map
    ├── map.min.js
    ├── controls.js           _Z77.openlayers.controls
    └── controls.min.js
```

Shared core MUSS im layoutConfig vor Modul-Files stehen — Modul-Extensions rufen `_Z77.core.fetch.register*` beim Laden auf und brauchen das shared core bereits initialisiert.

## Coding Standards

### Naming transformations — always via `Naming`

String transformations for routing, class names, methods, and properties belong in `Z77\Shared\Libraries\Convention\Naming`.

Before writing inline `strtolower` / `str_replace` / `ucwords` logic: check `Naming` first.
If the method is missing and reusable → add it to `Naming`.

```php
Naming::toCamelCase('my_string')          // my_string   → MyString
Naming::toLcFirstCamelCase('my_string')   // my_string   → myString
Naming::toSnakeCase('MyString')           // MyString    → my_string  (sanitizes arbitrary input)
Naming::toActionMethod('login')           // login       → loginAction
Naming::toActionUrlSegment('loginAction') // loginAction → login
Naming::toControllerUrlSegment('LoginController') // LoginController → login
Naming::toControllerClassName($prefix, 'system', 'login') // → ...Ui\Controllers\System\LoginController
Naming::toControllerClassName($prefix, '', 'login')        // → ...Ui\Controllers\LoginController  (flat, empty group)
Naming::toGetter('firstName')            // firstName   → getFirstName
Naming::toSetter('firstName')            // firstName   → setFirstName
```

### HTTP Input — always via Request

Never access `$_SERVER`, `$_POST`, or `$_GET` directly in controllers.
Use `DI::getRequest()` — it wraps all HTTP input and provides a clean API:

```php
DI::getRequest()->isPost()                        // instead of $_SERVER['REQUEST_METHOD'] === 'POST'
DI::getRequest()->getPostParameter('username')    // instead of $_POST['username']
DI::getRequest()->getGetParameter('q')            // instead of $_GET['q']
```

`$_SERVER`, `$_POST`, and `$_GET` are only accessed inside `Request` itself.

## Security

### CSRF-Token — zwei Stufen

z77 verwendet zwei CSRF-Schutzstufen je nach Risiko der Action:

| Stufe | Wann | Implementierung |
|---|---|---|
| **Per-Session** | Utility-Actions ohne Datenverlustrisiko (Clear-Cache, Toggle, Status) | `CsrfService::getToken()` — Token im `<meta name="csrf-token">`, `FetchCommunicator` schickt `X-CSRF-Token` Header, `AccessGuard` validiert zentral |
| **Per-Action** | Destructive Actions (Delete, Update Entity) | `CsrfService::getTokenFor(string $action)` — Token als `<input type="hidden">` im Formular, Controller validiert mit `validateFor($action, $token)` |

**Per-Action-Token-Format:** `'{entity}.{operation}.{id}'` — z.B. `'user.delete.42'`, `'order.update.7'`

**Regel:** Jede Action die Daten löscht oder überschreibt bekommt einen action-scoped Token.
Utility-Actions (kein Datenverlust möglich) verwenden den Per-Session-Token.

> `CsrfService::getTokenFor()` und `validateFor()` werden implementiert wenn der Editor gebaut wird.

---

## Database

The framework needs **no database** by default: the **File driver** (one JSON file per
record) is the standard backend. How persistence works — the driver-abstracted Repository
layer, the `#[Entity]` contract, and the driver's known limits — lives in
[persistence-architecture.md](../topics/persistence-architecture.md); the file-per-record
rationale is [ADR-010](../02-decisions/adr-010-file-per-record-storage.md).

SQL/Doctrine conventions (schema, migrations, query patterns) will be added here **when the
Doctrine driver is built** — it is designed-for behind the same Repository API but not yet
implemented. Until then there are no database-specific conventions to follow.

---

## View Layer

Quick reference. For depth see [concepts/view-layer.md](../03-development/concepts/view-layer.md).

### Module Structure

```
packages/module-X/
├── src/Ui/Config/layoutConfig.inc.php
├── src/Ui/Controllers/{Name}Controller.php
└── res/view/templates/
    ├── html-default-skeleton.tpl.php
    ├── html-fetch-skeleton.tpl.php
    ├── {Controller}/{action}.tpl.php
    └── partials/...
```

Action template is auto-resolved by convention: `IndexController::indexAction()` → `IndexController/indexAction.tpl.php`.

### Three Layout Modes

| Mode | When | How |
|---|---|---|
| Pure Config | Standard pages, all use the same layout | All partials in `layoutConfig.inc.php`. Action only returns context. |
| Config + Action | A page needs an extra/missing element | Config sets defaults. Action calls `addPartials()`/`removeSection()`/`addCss()`. |
| All in Action template | Special pages (landing, fullwidth, error) | Markup lives in `{Controller}/{action}.tpl.php`. Other body sections stay empty. |

### `levelElements` Syntax (three forms)

```php
'body' => [
    'header' => 'partials/header',                                              // string shortcut
    'main'   => ['partials/intro', 'partials/cta'],                             // array of strings
    'sidebar' => [                                                              // full form
        ['nameSpace' => 'Z77\\Module\\Shared', 'path' => 'partials', 'name' => 'sidebar'],
    ],
],
```

`nameSpace` defaults to current module if omitted.

### Reserved Names

Body section keys must NOT be: `head`, `css`, `jsHead`, `jsFooter` — these are layout system variables.

### Template Variables

| Template | Variables |
|---|---|
| Skeleton | Action context + body section variables (`$header`, `$main`, `$footer`, …) + `$head` `$css` `$jsHead` `$jsFooter` |
| Partials | Action context only |
| Action template | Action context only |

### Helper Functions

```php
<?= e($value) ?>                                  // HTML-escape
<?= raw($trustedHtml) ?>                          // pass-through
<?= replacePlaceholders('Hi {$name}', $vars) ?>   // {$key} → value, escaped by default
<?= $this->partial('partials/userCard', $ctx) ?>  // include other template
<?= $this->partial('foo', $ctx, 'Other\\Module') ?>   // foreign module
```

### Asset Pipeline

CSS / JS auto-versioned by source `filemtime()`. Versioned copy `{name}_at-{mtime}.css` is created on demand, old copies cleaned up. Filename versioning (not query strings) — guaranteed to bypass any CDN/proxy cache.

```php
// Module config
'styleSheets' => [
    ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'main', 'media' => ''],
],

// Action
$this->layoutManager->addCss('extra', 'Z77\\Module\\Frontend');
$this->layoutManager->removeCss('desktop');
```

In production, JS loads `.min.js` source files; debug mode uses non-minified JS. CSS has no `.min` variant — `StylesheetManager` always serves a single `{name}.css` (compressed in place by the SCSS build). See [File Names](#file-names).

### RequestMode → Skeleton

| RequestMode | Skeleton | Contents |
|---|---|---|
| `Page` | `html-default-skeleton.tpl.php` | Full HTML document |
| `Fetch` | `html-fetch-skeleton.tpl.php` | Minimal, just `$main` |

→ See [concepts/request-mode.md](../03-development/concepts/request-mode.md).
