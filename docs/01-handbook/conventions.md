# Conventions

**Status:** `[CURRENT]` — config principle, file names, namespaces, JS, view layer, persistence documented
**Last updated:** 2026-06-05 · JS section translated and CSP state updated 2026-11-15

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
- A backend table is a **standard list** (`Z77\Shared\Listing`, the kernel's `partials/listHead`
  / `listFind` / `pager`): columns declared once, sort and column search in the database, paged,
  a fetch region — [`docs/topics/listing.md`](../topics/listing.md). A screen does not hand-write
  its own head, search form or filter class.

---

## File Names

Casing follows the layer a name belongs to — never by taste, never ad-hoc. Rule of
thumb: **anything reachable through a URL or the browser is kebab-case; PHP is
PascalCase/camelCase; JSON persistence keys are snake_case.**

| Artefact | Casing | Example |
|---|---|---|
| Web-served asset file (`.js` / `.css`) | kebab-case, lowercase | `password-meter.js`, `nav-desktop.css` |
| Asset subfolder | kebab-case, lowercase | `backend-user/`, `navigation-group/` |
| URL segment | kebab-case, lowercase | `/backend/system/backend-user/list` |
| CSS class (BEM) | kebab-case | `login__box`, `btn--primary` |
| `data-*` attribute / `scriptInit` key | kebab-case | `data-z77-password`, `_Z77.scriptInit['password-meter']` |
| PHP namespace / class | PascalCase | `BackendUserController` |
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

### Principle — JS where it makes sense; never an attack surface

Owner decision 2026-09-29; replaces the earlier principle «as little JavaScript as possible»,
which was too restrictive for client applications (camera, offline, PWA).

**Division of labour:**

- **Server** — logic, validation, authorization, business rules. The server is the only instance that decides; it re-checks every value the client sends.
- **JavaScript** — improves the user experience, simplifies handling, steers the client (camera, image preparation, offline buffer, fetch, live hints, focus, UI state).
- **CSS** — presentation. What CSS solves cleanly (`:checked`, `:has()`, media queries, …) stays CSS; that is simplicity, not a ban.

**Never an attack surface** — JS must never become a door for an intruder:

- **The server decides.** No validation, authorization or business rule that exists only in the client. An attacker switches JS off or sends requests directly; client validation is only a hint.
- **No secrets in script reach.** No key, token or role flag the server trusts in `localStorage`, IndexedDB or a non-HttpOnly cookie. Authentication through HttpOnly cookies (session, device cookie) or non-exportable keys.
- **No XSS.** Never insert data as HTML (`innerHTML`, `insertAdjacentHTML`, `document.write`), no `eval` / `new Function`, no inline event handlers with data. Text through `textContent`; HTML comes only escaped from the server.
- **No foreign scripts.** No CDN, no tracker, no script from a foreign domain; assets are served by the installation itself.
- **No dependencies on stock.** Every JS library is foreign code with full rights in the page — only with a reason, versioned in the repo.
- **Content Security Policy.** Scripts only from the own origin (`script-src 'self'`, no `unsafe-inline` / `unsafe-eval`), so injected code does not run even after a mistake. State 2026-11-15: the page CSP is sent as `Content-Security-Policy-Report-Only` (observes, blocks nothing); the app shell `/app` runs under an enforced CSP — [`../topics/security.md`](../topics/security.md) SEC-HEAD-001.

Examples where **JS makes sense**:
- `_Z77.core.fetch` envelope dispatch (apply server answers locally)
- `_Z77.core.fields` live validation on blur (a hint; the server checks again on submit)
- camera, shrinking an image before upload, the offline queue of a PWA

Examples where **CSS is enough**:
- mobile navigation overlay → hidden `<input type="checkbox">` + `:has()` (no `nav.js`)
- toggling top-bar elements per breakpoint → media queries

Existing places that cite «Rule 7» as the reason for «no JS» stay valid — they were the simpler
solution even without the old rule.

### Namespace Convention

All JavaScript follows the pattern `_Z77.{library}.{task}`.

| Level | Example | Meaning |
|---|---|---|
| `_Z77` | `_Z77` | Framework root — once, global |
| `_Z77.{library}` | `_Z77.core` | One JS unit (core, backend, …) |
| `_Z77.{library}.{task}` | `_Z77.core.fetch` | One task-bound unit |

`library` matches the file name of the main file (`core.js` → `_Z77.core`).
`task` matches the file name of a split-out file (`system/cache.js` → `_Z77.backend.cache`).

### File layout (3 guard lines + IIFE)

Every JS file starts with the same lines:

```javascript
var _Z77 = _Z77 || {};
_Z77.core = _Z77.core || {};          // adapt to the library

_Z77.core.flash = (function () {
    // private internals

    function show(type, text) { /* ... */ }
    function clear() { /* ... */ }

    return { show: show, clear: clear };
})();
```

The guard lines are idempotent — several files of the same library can load in parallel without
overwriting each other.

### Dependencies inside a library

Tasks in the same file can reference each other directly — the order of the definitions decides
availability:

```javascript
// core.js
_Z77.core.flash = (function () { ... })();   // first

_Z77.core.fetch = (function () {
    function _handleEnvelope(env) {
        _Z77.core.flash.show(m.type, m.text); // works: flash is already defined
    }
    return { post: post, get: get };
})();
```

### Multi-file library

A larger library is split into several files — all under the same library namespace (an
illustrative example; the framework ships no map library):

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

Load order through `defer` and the declaration order in the layoutConfig.

### Minification

Production expects `{name}.min.js` — produced with terser (`{ mangle: false }`), either by the
VS Code «autominify» extension on save or with `npm run build:js -- <file.js>` (same bytes);
`php tests/js-min-markers.php` checks that every `.min.js` matches its source
([js-build.md](../topics/js-build.md)). Debug mode loads `{name}.js` directly; `JavascriptManager`
picks the suffix automatically.

### Directory structure

**Shared** — cross-module code (flash / message / fetch envelope dispatch and the shared UI
helpers). Every module loads it through `nameSpace: 'Z77\Shared'` in its layoutConfig.

```
packages/kernel/shared/res/assets/js/
├── core.js                   _Z77.core.flash, .message, .fetch, .wire, .fields, .menu, .windows, …
└── core.min.js
```

**Module** — module-specific code (own UI, form logic, extensions of the shared core through
`_Z77.core.fetch.registerEnvelopeHandler` / `registerCommand`).

```
res/assets/js/
├── appearance.js             _Z77.backend.appearance (backend example: palette / dark mode)
├── appearance.min.js
├── system/
│   ├── cache.js              _Z77.backend.cache
│   └── cache.min.js
└── navigation/
    ├── edit.js               _Z77.scriptInit['navigation-edit'] — lazy-loaded via load-script
    └── edit.min.js
```

The shared core MUST come before the module files in the layoutConfig — module extensions call
`_Z77.core.fetch.register*` while loading and need the shared core initialised.

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
DI::getRequest()->getBaseUrl()                    // instead of $_SERVER['HTTP_HOST']
```

`$_SERVER`, `$_POST`, and `$_GET` are only accessed inside `Request` itself.

`getBaseUrl()` is the one source for absolute URLs that leave the request — mail links, the
SEO canonical/hreflang set, anything rendered into a cacheable page. It returns the
installation's `canonicalBaseUrl` (`config/client/systemConfig.inc.php`, ADR-030) and throws when
that is unset rather than guessing: the `Host` header is the client's to choose, and the page
cache keys on path only, so deriving an origin from it lets one forged request decide what
every later visitor is served (SEC-005).

## Security

### CSRF token — two levels

z77 uses two CSRF protection levels, depending on the risk of the action:

| Level | When | Implementation |
|---|---|---|
| **Per session** | Every write. Utility actions without risk of data loss (clear cache, toggle, status) need nothing more | `CsrfService::getToken()` — token in `<meta name="csrf-token">`; `core.js` sends it as `X-CSRF-Token` header and `AccessGuard` checks every fetch POST centrally. A page-mode form POST sends it as the `csrf_token` field and declares `#[Csrf]` on the action |
| **Per entity** | Actions that delete or overwrite a record | `CsrfService::generateEntityToken(string $context, int\|string $id)` — an HMAC of `{context}:{id}` keyed by the session token, rendered as the hidden field `entity_csrf`; the action checks it with `validateEntityToken($token, $context, $id)` before it writes |

**Context:** the entity's name, the same string on both sides — e.g. `'invoice'`, `'sales-order'`,
`'navigation'` — with the record id as the second argument.

**Rule:** every action that deletes or overwrites data checks an entity token in addition to the
session token. Utility actions (no data loss possible) use the session token only.

---

## Database

The framework needs **no database** by default: the **File driver** (one JSON file per
record) is the standard backend. How persistence works — the driver-abstracted Repository
layer, the `#[Entity]` contract, and the driver's known limits — lives in
[persistence-architecture.md](../topics/persistence-architecture.md); the file-per-record
rationale is [ADR-010](../02-decisions/adr-010-file-per-record-storage.md).

A module that needs a relational database uses the **Doctrine driver**
(`z77/persistence-doctrine`, [ADR-039](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md))
behind the same Repository API. What holds there:

- **One connection config**: `config/client/database.inc.php`. Host, database name and
  credentials live nowhere else — not in a module config, not in the backup config.
- **Engine and encoding**: MariaDB 10.6+, InnoDB; `utf8mb4` / `utf8mb4_unicode_ci` for every
  table and every string column. The driver sets it on the connection and as the table
  default; a migration never names another collation.
- **Entities announce themselves**: `#[Entity('doctrine')]` plus Doctrine's mapping
  attributes, and the class listed under `doctrineEntities` in the module config — a
  project's own entity in `override/z77/module/{module}/src/App/Config/doctrineEntitiesConfig.inc.php`
  (a plain list, additive; never a copy of the module config). Nothing scans a directory.
- **Money is `DECIMAL(15,2)`** through `MoneyType` (`#[ORM\Column(type: MoneyType::NAME)]` on
  a `Money` property) — a decimal string in both directions, never a float, read in the
  installation's base currency ([ADR-042](../02-decisions/adr-042-ledger-and-money.md)).
- **Table and column names** are `snake_case` (JSON persistence keys follow the same rule —
  the name is a key, see File Names); the PHP property stays `camelCase`.
- **Schema only through migrations**, run from the CLI (ADR-039 decisions 12–14):
  `php vendor/bin/z77-db migrate | status | diff | generate | setup`. Never `SchemaTool`, never DDL
  from a web request or a job. `setup` is the everyday path after changing an entity: it applies
  pending migrations, writes the missing migration where the entity class lives (module or
  project), shows the SQL and refuses a DROP without `--allow-drop` (ADR-039 addendum 2026-10-08).
- **A module owns its migrations**: `res/migrations/` next to `src/`, namespace
  `{Module}\Migrations`, class `Version{YmdHis}` — `z77-db diff --namespace="Z77\Module\X\Migrations"`
  writes the draft, `generate` an empty one; `--namespace` is always required, and the
  directory must exist before the first `diff`. Migrations run in timestamp order across all
  modules. Review the generated `up()`: a table no entity maps is proposed for `DROP`.
- **Every migration is expand/contract**: `current` and `next` are two releases on one database.
  Add first (a column, a table, a nullable field), switch the release, remove in a LATER release.
  Never rename or drop what the running release still reads; never combine a data migration
  with a `DROP` in one file. `down()` is for development only.
- **Collation in migrations**: `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` on every
  `CREATE TABLE` (the generated draft carries it); never another one, never omitted.
- **Deploy order**: run `z77-db migrate` on the NEW release (`next`) before switching `current`
  to it. Then «Cache leeren» in the backend if the host runs `opcache.validate_timestamps = 0`
  (see `persistence-doctrine.md` DOCTRINE-CACHE-001).

Driver-specific behaviour a consumer must not depend on (flush scope, `remove()` timing,
Identity Map, `reorder()`) is listed as known issues in
[persistence-architecture.md](../topics/persistence-architecture.md).

---

## View Layer

Quick reference. For depth see [concepts/view-layer.md](../03-development/concepts/view-layer.md).

### Module Structure

```
packages/module-X/
├── src/Ui/Config/layoutConfig.inc.php
├── src/Ui/Controllers/{Group}/{Name}Controller.php
└── res/view/templates/
    ├── html-default-skeleton.tpl.php
    ├── html-fetch-skeleton.tpl.php
    ├── {Group}/{Controller}/{action}Action.tpl.php
    └── partials/...
```

Action template is auto-resolved by convention: `Main\IndexController::homeAction()` → `Main/IndexController/homeAction.tpl.php` (group-nested, ADR-005; [templates.md](templates.md#template-location-group-nested)).

### Three Layout Modes

| Mode | When | How |
|---|---|---|
| Pure Config | Standard pages, all use the same layout | All partials in `layoutConfig.inc.php`. Action only returns context. |
| Config + Action | A page needs an extra/missing element | Config sets defaults. Action calls `addPartials()`/`removeSection()`/`addCss()`. |
| All in Action template | Special pages (landing, fullwidth, error) | Markup lives in `{Group}/{Controller}/{action}Action.tpl.php`. Other body sections stay empty. |

### `levelElements` Syntax (three forms)

```php
'body' => [
    'header' => 'partials/header',                                              // string shortcut
    'main'   => ['partials/intro', 'partials/cta'],                             // array of strings
    'sidebar' => [                                                              // full form
        ['nameSpace' => 'Z77\\Shared', 'path' => 'partials', 'name' => 'sidebar'],
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
