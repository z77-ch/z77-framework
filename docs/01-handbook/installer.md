# Installer — Automatic Project Setup

**Date:** 2026-04-06
**Status:** `[DONE]`
**File:** `packages/kernel/core/src/Installer/Install.php`

---

## What is the Installer?

The installer is a Composer script that runs automatically after every `composer install` and `composer update`. It takes the project configuration from `composer.json` and:

1. Copies public files (entry point, favicon, etc.) into the project
2. Creates the complete directory structure
3. Generates five PHP config files that the framework reads at runtime

No manual setup is required after installation. The installer ensures every new project is immediately runnable.

---

## When Does It Run?

```json
"scripts": {
    "post-install-cmd": ["Z77\\Core\\Installer\\Install::run"],
    "post-update-cmd":  ["Z77\\Core\\Installer\\Install::run"]
}
```

Both hooks call the same method. `Install::run()` creates a fresh installer instance on every call — state is held in instance properties, so repeated calls are always clean.

---

## Configuration in `composer.json`

All installer configuration lives in the `extra` section of the project's `composer.json`:

```json
"extra": {
    "core-bootstrap": { ... },
    "core-module-manager": { ... },
    "directories": { ... }
}
```

### `core-bootstrap`

Controls the fundamental paths and behaviour of the framework.

| Key | Default | Description |
|---|---|---|
| `debug` | `true` | Enable debug mode (affects error display, cache behaviour) |
| `cacheDebug` | `false` | Enable cache hit/miss logging |
| `timeZone` | `Europe/Zurich` | PHP timezone for date formatting in generated files |
| `htmlRoot` | `public` | Public web root directory name |
| `cacheDir` | `lib/cache/` | Directory for file-based cache |
| `overrideDir` | `override` | Root directory for CE override files |
| `moduleDir` | `module` | Sub-directory name inside the override tree for modules |
| `assetDir` | `assets` | Asset directory name inside the web root |
| `tplDir` | `res/view/templates` | Template directory path relative to a module root |

**Example:**
```json
"core-bootstrap": {
    "debug": true,
    "timeZone": "Europe/Zurich",
    "cacheDir": "lib/cache",
    "htmlRoot": "public",
    "overrideDir": "override",
    "moduleDir": "module",
    "assetDir": "assets",
    "tplDir": "res/view/templates"
}
```

---

### `core-module-manager`

Defines the namespace convention for framework packages and modules.

| Key | Default | Description |
|---|---|---|
| `frameworkPrefix` | `Z77` | Top-level namespace of the framework (e.g. `Z77`) |
| `modulePrefix` | `Module` | Second-level namespace segment for modules (e.g. `Module`) |
| `defaultModule` | `frontend` | Module loaded when no route matches |

These two prefix values determine which installed packages are recognised as framework packages (`Z77\*`) and which are modules (`Z77\Module\*`).

**Example:**
```json
"core-module-manager": {
    "frameworkPrefix": "Z77",
    "modulePrefix": "Module",
    "defaultModule": "frontend"
}
```

---

### `directories`

Defines which directories the installer creates. All paths support placeholders (see below).

#### `moduleTree`

Directory structure created for each detected module. The installer iterates over every module namespace found in `autoload.psr-4` and creates this tree for each one.

```json
"moduleTree": {
    "controllers": "<*overrideDir*>/<moduleDir>/<*module*>/src/Ui/Controllers",
    "appServices":  "<*overrideDir*>/<moduleDir>/<*module*>/src/App/Services",
    "domain": {
        "entities":     "<*overrideDir*>/<moduleDir>/<*module*>/src/Dom/Entities",
        "repositories": "<*overrideDir*>/<moduleDir>/<*module*>/src/Dom/Repositories",
        "validators":   "<*overrideDir*>/<moduleDir>/<*module*>/src/Dom/Validators",
        "services":     "<*overrideDir*>/<moduleDir>/<*module*>/src/Dom/Services"
    },
    "res": "<*overrideDir*>/<moduleDir>/<*module*>/<tplDir>"
}
```

Nested objects are supported — the installer recurses into them.

#### `publicAssetTree`

Directory structure created for public assets for each module. Only module namespaces (`Z77\Module\*`) get these directories.

```json
"publicAssetTree": {
    "asset_css":     "<htmlRoot>/<assetDir>/<*module*>/css",
    "asset_js":      "<htmlRoot>/<assetDir>/<*module*>/js",
    "asset_images":  "<htmlRoot>/<assetDir>/<*module*>/images",
    "asset_storage": "<htmlRoot>/storage"
}
```

#### `logs`

Log directory. Accepts a string or array of strings.

```json
"logs": "logs"
```

---

### Placeholders

Directory paths can contain two types of placeholders:

| Placeholder | Resolved From | Example Value |
|---|---|---|
| `<htmlRoot>` | `core-bootstrap.htmlRoot` | `public` |
| `<*overrideDir*>` | `core-bootstrap.overrideDir` + `frameworkPrefix` | `override/z77` |
| `<moduleDir>` | `core-bootstrap.moduleDir` | `module` |
| `<assetDir>` | `core-bootstrap.assetDir` | `assets` |
| `<tplDir>` | `core-bootstrap.tplDir` | `res/view/templates` |
| `<*module*>` | Module name derived from namespace | `frontend` |

Placeholders surrounded by `<*...*>` are dynamic (per-module values). Placeholders surrounded by `<...>` are static (from config).

---

## PSR-4 Autoload — Override Paths

The installer also reads the project's `autoload.psr-4` section to find the override directory paths:

```json
"autoload": {
    "psr-4": {
        "Z77\\Core\\":             ["override/z77/core/src/"],
        "Z77\\Shared\\":           ["override/z77/shared/src/"],
        "Z77\\Persistence\\":      ["override/z77/persistence/src/"],
        "Z77\\Module\\Frontend\\": ["override/z77/module/frontend/src/"]
    }
}
```

These paths serve two purposes:

1. **Override directories** — The installer creates the directories so they are ready to receive override files (CE principle).
2. **FileFinder config** — These paths become the `sourcePaths` entries in `fileFinder.inc.php`, telling the FileFinder where to look for override files before falling back to vendor.

Namespaces matching `Z77\Module\*` are additionally recognised as modules and get their own `moduleTree` and `publicAssetTree` directories.

---

## What the Installer Creates

### Copied Files — `public/`

The installer copies the entire `public/` directory from the `z77/kernel` package (`core/public/`) into the project's web root:

| File | Purpose |
|---|---|
| `index.php` | Application entry point — bootstraps the framework |
| `.htaccess` | Apache mod_rewrite: www redirect, HTTPS force, all requests → `index.php` |
| `favicon.ico`, `favicon.svg`, `favicon-96x96.png` | Browser favicon |
| `apple-touch-icon.png` | iOS home screen icon |
| `site.webmanifest` | Web app manifest |
| `web-app-manifest-192x192.png`, `web-app-manifest-512x512.png` | PWA icons |

Overwrite behaviour depends on the `debug` flag:
- `debug=true` — always overwrite (development: keeps public files in sync with vendor)
- `debug=false` + interactive terminal — prompts before overwriting
- `debug=false` + non-interactive (CI) — skips existing files

---

### Generated Config Files — `config/`

Five PHP files are generated in the project's `config/` directory (`bootstrap`, `moduleManager`, `auth`, `i18n`, `fileFinder`). They are not meant to be edited manually — they are regenerated on every `composer install`.

#### `config/bootstrap.inc.php`

The merged result of the defaults and the project's `core-bootstrap` config. Read by `Bootstrap` at startup.

```php
<?php
// Auto-generated by Z77 Core Installer
return [
    'debug'           => true,
    'timeZone'        => 'Europe/Zurich',
    'htmlRoot'        => 'public',
    'overrideDir'     => 'override',
    // ...
];
```

#### `config/moduleManager.inc.php`

The merged result of the defaults and the project's `core-module-manager` config, plus the auto-detected module list. Read by `ModuleManager` at startup.

```php
<?php
// Auto-generated by Z77 Core Installer
return [
    'frameworkPrefix' => 'Z77',
    'modulePrefix'    => 'Module',
    'modules' => [
        'frontend' => [],
    ],
];
```

The `modules` array is populated automatically from the module namespaces found in `autoload.psr-4`. No manual registration is required.

#### `config/auth.inc.php`

The merged result of the defaults and the project's `core-auth` config. Holds the installation-wide `passwordTier` (bound to the `Z77\Shared\Auth\PasswordTier` enum). See [`docs/topics/security.md`](../topics/security.md).

```php
<?php
// Auto-generated by Z77 Core Installer
return [
    'passwordTier' => 'strong',
];
```

#### `config/i18n.inc.php`

The merged result of the defaults and the project's `core-i18n` config — the system language policy (ADR-013).

```php
<?php
// Auto-generated by Z77 Core Installer
return [
    'defaultLanguage' => 'de',
    'languages'       => ['de', 'en', 'fr'],
];
```

---

### Seeded Data Files — `data/`

JSON files are written on first install only. If the file already exists it is never overwritten — these are live data files that the application writes to.

| File | Source default |
|---|---|
| `data/framework/routing/navigation.json` | `packages/kernel/core/data/framework/routing/navigation.default.json` |
| `data/framework/seo/metadata.json` | `packages/kernel/core/data/framework/seo/metadata.default.json` |

`data/framework/auth/loginUsers.json` is **not** seeded from a default — no credential is shipped (the framework is open source). The admin is created by `provisionAdmin()`: an interactive install prompts for a password; a non-interactive install writes a one-time `SETUP_TOKEN` under `data/framework/auth/` and defers admin creation to `/backend/system/setup/setup`. See [`docs/topics/security.md`](../topics/security.md).

---

#### `config/fileFinder.inc.php`

The path map used by `FileFinder` to locate files at runtime. Contains the override paths first, then the vendor paths — this is what implements the CE (Customer Extension) principle.

```php
<?php
// Auto-generated by Z77 Core Installer
$vendorDir = dirname(__DIR__).'/vendor/';
$baseDir   = dirname(__DIR__).'/';

return [
    'resourceDir' => [
        'sourceDir' => 'src',
        'assetDir'  => 'assets',
        'tplDir'    => 'res/view/templates',
    ],
    'namespaces' => [
        'Z77\\Module\\Frontend\\' => [
            'sourcePaths' => [$baseDir.'override/z77/module/frontend', $vendorDir.'z77/module-frontend'],
            'assetPaths'  => [$baseDir.'public/assets/frontend', $baseDir.'public/assets/vendor/z77/module-frontend'],
        ],
        // ... more namespaces
    ],
];
```

The order of `sourcePaths` is significant: override paths come first, vendor paths come second. `FileFinder` uses this order to implement the override lookup.

---

### Created Directories

For a typical project with a `frontend` module, the installer creates:

```
override/
└── z77/
    ├── core/src/                              ← override for z77/kernel (Core)
    ├── shared/src/                            ← override for z77/kernel (Shared)
    ├── persistence/src/                       ← override for z77/kernel (Persistence)
    └── module/
        └── frontend/src/                      ← override for z77/module-frontend
            ├── Ui/Controllers/
            ├── App/Services/
            ├── Dom/Entities/
            ├── Dom/Repositories/
            ├── Dom/Validators/
            ├── Dom/Services/
            └── res/view/templates/

public/
└── assets/
    └── frontend/
        ├── css/
        ├── js/
        └── images/
    storage/

logs/
```

Directories that already exist are silently skipped.

---

## Execution Flow (Step by Step)

```
composer install
    │
    └── Install::run()
            │
            └── new Install($event)->execute()
                    │
                    ├── 1. Load config (extra → merge with defaults)
                    ├── 2. Validate mandatory config keys
                    │
                    ├── 3. Read PSR-4 paths from autoload (override dirs)
                    ├── 4. Build path maps (configPaths, publicAssetPaths)
                    │       ├── override paths from autoload.psr-4
                    │       └── vendor paths from installed packages
                    │
                    ├── 5. Copy public/ → project web root
                    │
                    ├── 6. Create directories
                    │       ├── Override dirs (from autoload.psr-4)
                    │       ├── Module tree (per module from directories.moduleTree)
                    │       ├── Public asset tree (per module from directories.publicAssetTree)
                    │       └── Logs dir
                    │
                    ├── 7. Write config/bootstrap.inc.php
                    ├── 8. Write config/moduleManager.inc.php
                    ├── 9. Write config/fileFinder.inc.php
                    └── 10. Seed data files (skip if already exist)
                            ├── data/framework/routing/navigation.json
                            ├── data/framework/auth/loginUsers.json
                            └── data/framework/seo/metadata.json
```

---

## Adding a New Module

To add a new module to a project, add its override namespace to `autoload.psr-4`:

```json
"autoload": {
    "psr-4": {
        "Z77\\Module\\Blog\\": ["override/z77/module/blog/src/"]
    }
}
```

Then run:

```bash
composer install
```

The installer automatically:
- Creates the `override/z77/module/blog/src/` directory
- Creates the full `moduleTree` structure for `blog`
- Creates the `publicAssetTree` directories for `blog`
- Registers `blog` in `config/moduleManager.inc.php`
- Adds `blog` paths to `config/fileFinder.inc.php`

No other changes are required.

---

## Defaults Reference

If a key is missing from `composer.json`, the framework default is used:

| Key | Default |
|---|---|
| `debug` | `true` |
| `cacheDebug` | `false` |
| `timeZone` | `Europe/Zurich` |
| `htmlRoot` | `public` |
| `cacheDir` | `lib/cache/` |
| `overrideDir` | `override` |
| `moduleDir` | `module` |
| `assetDir` | `assets` |
| `frameworkPrefix` | `Z77` |
| `modulePrefix` | `Module` |
| `defaultModule` | `frontend` |
| `tplDir` | `res/view/templates` |
