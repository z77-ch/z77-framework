<?php

/**
 * The test runner's map (tests/run.php, docs/topics/testing.md): runner settings and the groups.
 *
 * - `groups`: area => harness names (file name without `.php` / `.mjs`). Every harness in tests/
 *   must be in at least one group — `php tests/run.php --list` names the orphans and exits 1, and
 *   tests/run-selftest.php checks that. `all` is implicit (every harness) and not listed here.
 * - What a harness COVERS (for `--changed`) is not typed here: the runner reads it from the
 *   harness's source (package paths, namespaces, required support files, fixtures). `covers` adds
 *   only what a source cannot show — a path built at runtime.
 * - `settings`: `jobs` side by side, the watchdog's `idleSeconds` (no output) and `limitSeconds`
 *   (total per harness). Command-line `--jobs` / `--idle` / `--limit` win.
 *
 * The groups were built from what the harnesses load (`php tests/run.php --list` shows each
 * harness's coverage); an area group is what a builder runs before reporting done.
 */
return [
    'settings' => [
        'jobs'         => 4,
        'idleSeconds'  => 300,
        'limitSeconds' => 3600,
    ],

    'groups' => [
        // Bookkeeping and everything that posts or pays: financial, bank, creditor, debtor, receipt, VAT, mandator.
        'finance' => [
            'creditor-ai', 'creditor-recognition', 'financial-bank', 'financial-gross-entry', 'module-creditor',
            'module-debtor', 'module-financial', 'module-mandator', 'module-receipt', 'module-vat', 'money', 'pdf',
            'project-health', 'project-health-fresh',
        ],
        // Sales side: article, order, contact, mailing (outbox).
        'sales' => ['module-article', 'module-contact', 'module-mailing', 'module-order'],
        // DMS, uploads, file delivery, ZIP.
        'dms' => [
            'content-media-blocks', 'content-media-editor', 'dms-active-content', 'dms-active-content-flow', 'dms-activity',
            'dms-chunked-upload', 'dms-media-invalidate', 'dms-media-picker', 'dms-principal-roles', 'dms-upload-types',
            'dms-zip-bundle', 'dms-zip-layout', 'file-delivery-browser', 'file-delivery-policy', 'file-response-range',
            'upload-component', 'zip-archiver-symlinks',
        ],
        // Structured content and the public frontend.
        'content' => [
            'content-blueprints', 'content-editor-template', 'content-media-blocks', 'content-media-editor',
            'content-page-editor', 'content-variants', 'frontend-starter-pages',
        ],
        // Login, sessions, 2FA, CSRF, security headers, public-form guards.
        'auth' => [
            'backend-2fa', 'backend-access', 'backend-password-reset', 'backend-user-identities', 'country-blocklist',
            'csrf-service', 'form-geo-guard', 'form-post-csrf', 'login-code-store', 'member-2fa', 'security-headers',
            'state-changing-get',
        ],
        // Member accounts (customer login).
        'member' => [
            'member-2fa', 'member-idle-clock', 'member-language', 'member-operator-invite', 'member-shell-actions',
            'member-tenant-noun',
        ],
        // module-api, the app, devices.
        'api' => [
            'api-contract-throttle', 'api-device-channel', 'api-stateless-guard', 'app-queue', 'app-shell',
            'login-code-store', 'module-api-app-users-ui',
        ],
        // Backend shell and shared backend building blocks.
        'backend' => [
            'backend-2fa', 'backend-access', 'backend-dashboard', 'backend-password-reset', 'backend-user-identities',
            'form-actions', 'listing', 'mail-sender-check', 'modal-page', 'module-api-app-users-ui', 'navigation-delete', 'navigation-seeds',
            'project-health', 'project-health-fresh', 'web-stats', 'web-stats-report',
        ],
        // Kernel: config, routing, cache, i18n, errors, identity.
        'kernel' => [
            'alert-service', 'apcu-stamp', 'config-split', 'file-response-range', 'language-session', 'money', 'pdf',
            'routing-alias-first', 'site-identity', 'translation-catalog', 'uncaught-error-status',
        ],
        // schema-reserved-words: no table / column / alias is a keyword of MySQL 8.x or MariaDB ≤ 13 (offline).
        'persistence' => ['entity-announcement', 'persistence-doctrine', 'persistence-doctrine-migrations', 'persistence-doctrine-tx', 'persistence-file-merge', 'schema-reserved-words'],
        // Installer, backup, deploy (.releases).
        'installer' => [
            'backup-restore', 'backup-retention', 'fresh-install-setup', 'installer-admin-prompt', 'installer-asset-cleanup', 'installer-asset-publish',
            'installer-module-tree', 'installer-starter-content', 'releases-export', 'releases-override-check',
        ],
        'mail'   => ['alert-service', 'mail-html-to-text', 'mail-sender-check', 'mail-sender-identity', 'mail-standard', 'module-mailing'],
        'kibot'  => ['creditor-ai', 'kibot', 'kibot-adapter', 'kibot-assistant'],
        // Client-side JavaScript (node; the browser one needs Chrome or Edge) and the .min.js markers.
        'js'     => ['app-queue', 'core-js', 'core-js-windows-browser', 'js-min-markers'],
        // The runner itself.
        'runner' => ['run-selftest'],
    ],

    'covers' => [
        // Its self-test drives the runner and its classes, which it names only as paths it builds.
        'run-selftest' => ['tests/run.php', 'tests/groups.php', 'tests/support/runner'],
    ],
];
