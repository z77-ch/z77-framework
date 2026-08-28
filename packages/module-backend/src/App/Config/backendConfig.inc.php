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
        // (backendUsers.json) and possibly DB dumps — SUPER_USER on every action
        // (inherited from controllerRole; ADR-021 governance, docs/topics/backup.md).
        'service' => [
            'BackupController' => [
                'controllerRole' => AuthRole::SUPER_USER,
            ],
            // Deciding WHEN a job runs is installation governance: a job runs
            // with the role its module declares and may delete data (ADR-031).
            'JobController' => [
                'controllerRole' => AuthRole::SUPER_USER,
            ],
            // Data import (ADR-032): adopts shipped/foreign records into the
            // installation's data — writes navigation, aliases, metadata.
            // Installation governance like backup/jobs.
            'ImportController' => [
                'controllerRole' => AuthRole::SUPER_USER,
            ],
        ],
    ],

    // Importable entities (ADR-032): the whitelist the backend data import
    // works on — aggregated by ModuleManager::getImportEntities(). The screen
    // only ever offers these; an entity class never comes from a request.
    'importEntities' => [
        \Z77\Shared\Entities\Navigation::class,
        \Z77\Shared\Entities\NavigationAlias::class,
        \Z77\Shared\Entities\MetaData::class,
    ],

    // Background jobs (ADR-031). The services themselves live in the kernel,
    // but jobs are declared per MODULE and this module owns the service section
    // that operates them — so the entries sit here. A project without the
    // backend keeps the manual CLI entry (`vendor/bin/z77-backup`).
    //
    // Backup: one class, three keys — the type travels in the payload. No
    // 'defaultSchedule': how often an installation is backed up, and how much
    // disk that may cost, is the operator's call. The schedule is switched on
    // in the backend, not by installing a package.
    'jobs' => [
        'backup-data' => [
            'class'       => \Z77\Shared\Jobs\BackupJob::class,
            'label'       => 'Backup — Daten',
            'runAs'       => AuthRole::SUPER_USER,
            'maxAttempts' => 2,
            'payload'     => ['type' => 'data'],
        ],
        'backup-db' => [
            'class'       => \Z77\Shared\Jobs\BackupJob::class,
            'label'       => 'Backup — Datenbank',
            'runAs'       => AuthRole::SUPER_USER,
            'maxAttempts' => 2,
            'payload'     => ['type' => 'db'],
        ],
        'backup-full' => [
            'class'       => \Z77\Shared\Jobs\BackupJob::class,
            'label'       => 'Backup — Gesamtprojekt',
            'runAs'       => AuthRole::SUPER_USER,
            'maxAttempts' => 2,
            'payload'     => ['type' => 'full'],
        ],
        // Applies the CURRENT import plan (ADR-032) — queued from the import
        // screen for bulk sets a web request must not carry. No payload: the
        // plan store is the single source. maxAttempts 1: a stale plan must
        // never be retried against data that moved.
        'import-apply' => [
            'class'       => \Z77\Shared\Jobs\ImportApplyJob::class,
            'label'       => 'Import — Plan anwenden',
            'runAs'       => AuthRole::SUPER_USER,
            'maxAttempts' => 1,
        ],
        // The form log's broom (geo guard, see docs/topics/forms.md). No
        // 'defaultSchedule': it DELETES the installation's data, so an
        // operator switches it on — the delete-vs-schedule rule, same as
        // member-cleanup.
        'form-log-cleanup' => [
            'class'       => \Z77\Shared\Forms\FormLogSweepJob::class,
            'label'       => 'Formular-Protokoll-Bereinigung',
            'runAs'       => AuthRole::CRON_JOB,
            'maxAttempts' => 2,
        ],
        // ⚠️ SHIPS a schedule, and that is not a violation of the rule above:
        // this job replaces a file it downloaded itself, and doing so is a
        // LICENCE OBLIGATION (GeoLite EULA: keep current, destroy the
        // previous version within 30 days). A duty that waits for an operator
        // to remember it is not a duty being met. Weekly, although the fetch
        // only fires past `maxAgeDays` (30): the schedule is the knock on the
        // door, the age is the answer — a just-expired database is renewed
        // within days instead of within a month.
        //
        // Registered HERE because the geo guard is a kernel capability of
        // every public form (PublicFormHandler::withGeoGuard()), operated
        // from this module's service section. It used to be registered by
        // module-member; a project override of memberConfig that still
        // carries the entry double-declares the key — fail-fast — and must
        // drop it.
        'geoip-update' => [
            'class'           => \Z77\Shared\GeoIp\GeoIpUpdateJob::class,
            'label'           => 'GeoIP-Datenbank erneuern',
            'runAs'           => AuthRole::CRON_JOB,
            'maxAttempts'     => 2,
            'defaultSchedule' => 'weekly@mon,04:20',
        ],
    ],
];
