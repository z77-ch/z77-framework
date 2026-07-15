# Templates

**Status:** `[CURRENT]`
**Last updated:** 2026-05-02

---

## How templates are filled

`AbstractBaseController::html(array $context)` injects variables into every template.
The context is passed through `LayoutManager` → `HtmlView` → `extract()`.

---

## Template location (group-nested)

Action templates and controller-owned partials mirror the controller's physical
location — the view tree matches the controller tree (ADR-005, revised
2026-06-02):

```text
src/Ui/Controllers/{Group}/{Controller}.php
res/view/templates/{Group}/{Controller}/{action}.tpl.php
```

Examples:

```text
res/view/templates/System/DashboardController/overviewAction.tpl.php
res/view/templates/Content/NavigationController/listAction.tpl.php
res/view/templates/Main/IndexController/homeAction.tpl.php
```

- The action template is resolved automatically by `LayoutManager` from the
  controller's namespace group + base name — no manual wiring.
- Controller-owned partials add the same prefix explicitly:
  `addPartials('edit', 'Content/NavigationController', self::NAMESPACE)`.
- **Module-wide partials stay flat** under `templates/partials/` (header,
  footer, head/, flashMessages, …) — they are not controller-bound.
- Controller layout configs follow the same nesting:
  `src/Ui/Config/{Group}/{controller}Config.inc.php`.

Group nesting prevents collisions: two controllers with the same base name in
different groups (`Content\NavigationController`, `System\NavigationController`)
get distinct template and config paths.

---

## Templates contain no logic

`.tpl.php` files are pure templates. They contain display markup with variable output (`<?= e($x) ?>`) and trivial control flow that drives *display variants* (`foreach`, `if` around markup variations). Nothing else.

**What does NOT belong in templates:**

- **Defensive guards** (`if (empty($x)) return;`) — belong in the controller or in layout routing.
  *Example: the previous `if (empty($authUser) || !$authUser->isLoggedIn()) return;` in the backend header was a guard against an unauthenticated render context. The proper fix is a separate layout for unauthenticated pages, not a template-level escape hatch.*
- **State synchronisation** (inline `<script>` that syncs `localStorage` with server state, etc.) — belongs in the JS asset file or as a `data-*` attribute set server-side.
  *Example: the previous `<script>` block in `html-default-skeleton.tpl.php` that mirrored `$userPreferences` into `localStorage`. Replaced by `data-be-palette` / `data-be-theme` attributes on `<html>`, set directly from controller-provided context.*
- **Domain logic** (URL construction, active-state detection, ref resolution, palette token lookup) — belongs in a service or view helper.
- **Transformations / computations** (bool→string conversion, ternary chains beyond simple display, format calculations) — belongs in the controller, which passes the finished view model to the template.
  *Example: `$context['beTheme'] = $userPreferences->isDarkMode() ? 'dark' : 'light';` is set in the controller. The template only writes `<?= e($beTheme) ?>`.*

**Why:** Logic in templates is not testable, not reusable, and obscures the render path. Templates should be "dumb" — they receive a finished view model and render it. When a template has a conditional, it must control a *display variant*, not *what happens*.

**How to apply:** When writing or reviewing a `.tpl.php` file, question every `if`, every `?:`, every function call: *Is this display or is this logic?* Logic moves to service / controller / view helper. Inline `<script>` blocks are a smell — they belong in asset files.

---

## Always-available variables

Every partial and action template receives these automatically — no manual passing needed:

| Variable | Type | Source |
|---|---|---|
| `$navigationService` | `NavigationService` | DI singleton |
| `$navigation` | `?Navigation` | current matched entry, `null` on convention routes |
| `$language` | `string` | `'de'`, `'fr'`, … |
| `$metaData` | `?MetaData` | resolved from `$navigation->getId()` + `$language`, `null` if no entry |

Action-specific variables are merged on top via `return $this->html(['key' => $value])`.

---

## NavigationService API

```php
$navigationService->getByTag('main')                        // Navigation[]
$navigationService->findByUrl('/about')                     // ?Navigation
$navigationService->getCurrent()                            // ?Navigation (same as $navigation)
$navigationService->findMetaData($navigation->getId(), $language)  // ?MetaData
```

---

## Entity fields

**Navigation**
```php
$entry->getId()          // ?int
$entry->getName()        // string
$entry->getUrl()         // string
$entry->getModule()      // string
$entry->getController()  // string
$entry->getAction()      // string
$entry->getTag()         // ?string (tree-root tag slug; null for children)
$entry->getParentId()    // ?int (parent entry id; null = top-level root)
// children: use $navigationService->getChildren($entry) — there is no entity children accessor
```

**MetaData**
```php
$metaData->getTitle()          // string
$metaData->getDescription()    // string
$metaData->getThemeColor()     // string
$metaData->getApplicationLd()  // array (JSON-LD data)
$metaData->getLanguage()       // string
$metaData->getNavigationId()   // ?int
```

---

## Output escaping

Always use `e()` — never `htmlspecialchars()` directly:

```php
<?= e($entry->getName()) ?>
<?= e($metaData?->getTitle() ?: 'Fallback') ?>
```

For trusted HTML: `<?= raw($html) ?>`

---

## IDE type hints

Templates receive variables at runtime — the IDE cannot infer them statically.
Add `@var` hints at the top of the file to suppress false errors and enable autocomplete:

```php
<?php
/** @var \Z77\Core\Services\NavigationService $navigationService */
/** @var \Z77\Shared\Entities\Navigation|null $navigation */
/** @var \Z77\Shared\Entities\MetaData|null $metaData */
/** @var string $language */
?>
```

Only declare what the template actually uses.

---

## Navigation tags (convention)

Tags are set per entry in `data/framework/routing/navigation.json`.

| Tag | Usage |
|---|---|
| `main` | Main header navigation |
| `frontend` | All frontend-visible entries |

Project-specific tags are defined freely — no framework restriction.
