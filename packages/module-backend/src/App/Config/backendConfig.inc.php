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
    'cache'         => [
        'enabled' => false,
    ],
    // Access-control config is nested by group, mirroring the controller
    // namespace (`Ui/Controllers/{Group}/{Controller}`). The group is the outer
    // key — controller base names only need to be unique WITHIN a group, so two
    // controllers with the same base name in different groups never collide.
    // Lookup: controllers[$group][$controllerBaseName] (AuthService,
    // ModuleManager). See ADR-005 (revised 2026-06-02) + backend.md AUTH-B002.
    'controllers'   => [
        'system' => [
            'LoginController' => [
                'defaultAction'  => 'login',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'loginAction'  => AuthRole::GUEST,
                    'logoutAction' => AuthRole::GUEST,
                ],
            ],
            // First-run admin setup for non-interactive installs. Must be reachable
            // without auth (no admin exists yet) — GUEST. Self-gates via SETUP_TOKEN
            // and locks once a user exists. See SetupController / security.md.
            'SetupController' => [
                'defaultAction'  => 'setup',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'setupAction' => AuthRole::GUEST,
                ],
            ],
            'DashboardController' => [
                'defaultAction'  => 'overview',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'overviewAction' => AuthRole::ADMIN,
                ],
            ],
            'SystemController' => [
                // No defaultAction (P1): /backend/system/system would resolve to a POST-only
                // action via GET, which is incoherent. SystemController is reachable only
                // through explicit actions (clear-cache, toggle-debug, save-preferences),
                // invoked from JS.
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'clearCacheAction'      => AuthRole::ADMIN,
                    'toggleDebugAction'     => AuthRole::ADMIN,
                    'savePreferencesAction' => AuthRole::ADMIN,
                ],
            ],
            'LoginUserController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'listAction'          => AuthRole::ADMIN,
                    'addAction'           => AuthRole::ADMIN,
                    'editAction'          => AuthRole::ADMIN,
                    'confirmDeleteAction' => AuthRole::ADMIN,
                    'removeAction'        => AuthRole::ADMIN,
                    'checkFieldAction'    => AuthRole::ADMIN,
                    'moveAction'          => AuthRole::ADMIN,
                ],
            ],
        ],
        'content' => [
            'ContentController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'listAction'          => AuthRole::ADMIN,
                    'addAction'           => AuthRole::ADMIN,
                    'editAction'          => AuthRole::ADMIN,
                    'confirmDeleteAction' => AuthRole::ADMIN,
                    'removeAction'        => AuthRole::ADMIN,
                ],
            ],
            'NavigationController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'listAction'          => AuthRole::ADMIN,
                    'addAction'           => AuthRole::ADMIN,
                    'editAction'          => AuthRole::ADMIN,
                    'confirmDeleteAction' => AuthRole::ADMIN,
                    'removeAction'        => AuthRole::ADMIN,
                    'moveAction'          => AuthRole::ADMIN,
                    'checkFieldAction'    => AuthRole::ADMIN,
                ],
            ],
            'MetaDataController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'listAction'          => AuthRole::ADMIN,
                    'addAction'           => AuthRole::ADMIN,
                    'editAction'          => AuthRole::ADMIN,
                    'confirmDeleteAction' => AuthRole::ADMIN,
                    'removeAction'        => AuthRole::ADMIN,
                ],
            ],
            'NavigationAliasController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'listAction'          => AuthRole::ADMIN,
                    'addAction'           => AuthRole::ADMIN,
                    'editAction'          => AuthRole::ADMIN,
                    'confirmDeleteAction' => AuthRole::ADMIN,
                    'removeAction'        => AuthRole::ADMIN,
                ],
            ],
            'TranslationController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'listAction'          => AuthRole::ADMIN,
                    'addAction'           => AuthRole::ADMIN,
                    'editAction'          => AuthRole::ADMIN,
                    'confirmDeleteAction' => AuthRole::ADMIN,
                    'removeAction'        => AuthRole::ADMIN,
                ],
            ],
        ],
        // Document management UI (DMS). The DMS Drive ({@see DriveController}, R6) is the
        // whole management surface (folder tree | list | preview; upload / rename / move /
        // delete / new-folder / delivery-mode / ACL). DocumentController is reduced to byte
        // delivery (preview / download) for the Drive. The legacy list/tree UI + its
        // FolderController were retired 2026-07-01 (the Drive replaced them). All ADMIN.
        'documents' => [
            // DMS Drive (R6): the 3-pane `.dms` fragment surface (folder tree | list |
            // preview). Embedded in the backend host; loads the module-dms `dms.css` bundle.
            'DriveController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'listAction' => AuthRole::ADMIN,
                    // In-place pane refresh (folder/file click → replace-html), R6c.
                    'paneAction' => AuthRole::ADMIN,
                    // Upload modal + multipart handler (redirects back to the target folder), R6c.
                    'addAction'    => AuthRole::ADMIN,
                    'uploadAction' => AuthRole::ADMIN,
                    // Document actions (modal + handler), success = in-place pane refresh, R6c.
                    // `edit` is the COMBINED modal (name / delivery mode / ACL, 2026-07-03) —
                    // the separate mode/acl actions were folded into it.
                    'editAction'          => AuthRole::ADMIN,
                    'moveAction'          => AuthRole::ADMIN,
                    'confirmDeleteAction' => AuthRole::ADMIN,
                    'removeAction'        => AuthRole::ADMIN,
                    // Folder actions (new/edit/move/delete), success = in-place pane refresh, R6c.
                    // `folder-edit` is the combined modal (+ `key` for a SUPER_USER, ADR-021).
                    'folderAddAction'           => AuthRole::ADMIN,
                    'folderEditAction'          => AuthRole::ADMIN,
                    'folderMoveAction'          => AuthRole::ADMIN,
                    'folderConfirmDeleteAction' => AuthRole::ADMIN,
                    'folderRemoveAction'        => AuthRole::ADMIN,
                    // Trash: list soft-deleted docs + restore / permanent delete, R6c.
                    'trashAction'      => AuthRole::ADMIN,
                    // Per-row action hub (⋮) + inline active toggle, R6c.
                    'actionsAction'    => AuthRole::ADMIN,
                ],
            ],
            // Byte delivery only (Drive preview/thumbnail + download); the management UI is
            // the Drive. `preview` is also the default (no list page).
            'DocumentController' => [
                'defaultAction'  => 'preview',
                'controllerRole' => AuthRole::ADMIN,
                'actions'        => [
                    'previewAction'  => AuthRole::ADMIN,
                    'downloadAction' => AuthRole::ADMIN,
                ],
            ],
        ],
        // Installation service tools. Backups contain the whole user store
        // (loginUsers.json) and possibly DB dumps — SUPER_USER only, on every
        // action (ADR-021 governance; see docs/topics/backup.md).
        'service' => [
            'BackupController' => [
                'defaultAction'  => 'list',
                'controllerRole' => AuthRole::SUPER_USER,
                'actions'        => [
                    'listAction'          => AuthRole::SUPER_USER,
                    'runAction'           => AuthRole::SUPER_USER,
                    'downloadAction'      => AuthRole::SUPER_USER,
                    'actionsAction'       => AuthRole::SUPER_USER,
                    'confirmDeleteAction' => AuthRole::SUPER_USER,
                    'removeAction'        => AuthRole::SUPER_USER,
                ],
            ],
        ],
    ],
];
