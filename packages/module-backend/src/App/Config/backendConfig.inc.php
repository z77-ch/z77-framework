<?php
namespace Z77\Module\Backend\App;

use Z77\Core\Config\AuthRole;

return [
    'defaultGroup'  => 'system',
    'groupDefaults' => [
        'system'    => 'dashboard',
        'content'   => 'navigation',
        'documents' => 'drive',
        'service'   => 'backup',
    ],
    // View area: this module owns a layout and is a top-level UI environment.
    // The environment identity is the module key; its display label + navigation
    // slots live here in config (not an editable entity). See ADR-022.
    'viewArea'      => true,
    // Display label of this environment (was the NavigationGroup env-row label).
    'viewAreaLabel' => 'Backend',
    // Navigation slots (render areas) of this environment: ordered map slotKey → label.
    // Full slug = `{moduleKey}-{slotKey}` (e.g. `backend-main`). `main` = topbar
    // sections/sidebar; `auth` = login/logout routing entries (not rendered in UI).
    // See ADR-022.
    'navSlots'      => [
        'main' => 'Sektionen',
        'auth' => 'Authentifizierung',
    ],
    // Not public: the admin backend is never indexed and carries no SEO metadata
    // (excluded from the backend metadata list). See docs/topics/metadata.md.
    'public'        => false,
    'loginUrl'      => '/login',
    'moduleRole'    => AuthRole::ADMIN,
    // Convention: a controller without a configured defaultAction resolves to
    // `list` (module-level fallback in getDefaultActionForController). Configure
    // a defaultAction only when a controller DEVIATES (dashboard → overview).
    // A controller without a matching action method still 404s (setAction).
    'defaultAction' => 'list',
    'cache'         => [
        'enabled' => false,
    ],
    // Access-control config is nested by group, mirroring the controller
    // namespace (`Ui/Controllers/{Group}/{Controller}`). Lookup:
    // controllers[$group][$controllerBaseName] (AuthService, ModuleManager).
    // See ADR-005 (revised 2026-06-02) + backend.md AUTH-B002/AUTH-B003.
    //
    // DEVIATION-ONLY (AUTH-B003): an entry exists ONLY when a controller
    // deviates from the module baseline. Everything unlisted inherits
    // `moduleRole` (ADMIN — a forgotten controller/action is never open) and
    // the module `defaultAction` convention (`list`). Do NOT restate ADMIN
    // per controller/action — that is redundancy that drifts.
    //
    // Full schema, for when a deviation needs it:
    //
    // '{group}' => [
    //     '{Name}Controller' => [
    //         'defaultAction'  => 'overview',          // only when not `list`
    //         'controllerRole' => AuthRole::SUPER_USER, // only when not moduleRole
    //         'actions'        => [
    //             'publicThingAction' => AuthRole::GUEST, // per-action override
    //             '*'                 => AuthRole::ADMIN, // action wildcard
    //         ],
    //     ],
    // ],
    'controllers'   => [
        'system' => [
            // GUEST — the login screen must be reachable without auth.
            'LoginController' => [
                'defaultAction'  => 'login',
                'controllerRole' => AuthRole::GUEST,
            ],
            // GUEST — first-run admin setup for non-interactive installs (no admin
            // exists yet). Self-gates via SETUP_TOKEN and locks once a user exists.
            // See SetupController / security.md.
            'SetupController' => [
                'defaultAction'  => 'setup',
                'controllerRole' => AuthRole::GUEST,
            ],
            // Entry page after login — deviates from the `list` convention only.
            'DashboardController' => [
                'defaultAction' => 'overview',
            ],
            // SystemController (POST-only fetch endpoints) deliberately has NO
            // entry: the `list` convention resolves /backend/system/system to a
            // listAction that does not exist → 404 by design (ADR-005).
        ],
        'documents' => [
            // Byte delivery only (Drive preview/thumbnail + download) — deviates
            // from the `list` convention only.
            'DocumentController' => [
                'defaultAction' => 'preview',
            ],
        ],
        // Installation service tools. Backups contain the whole user store
        // (loginUsers.json) and possibly DB dumps — SUPER_USER on every action
        // (inherited from controllerRole; ADR-021 governance, docs/topics/backup.md).
        'service' => [
            'BackupController' => [
                'controllerRole' => AuthRole::SUPER_USER,
            ],
        ],
    ],
];
