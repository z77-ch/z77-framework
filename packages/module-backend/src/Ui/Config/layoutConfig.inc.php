<?php
namespace Z77\Module\Backend\Ui\Config;

return [
    // The 3-column shell skeleton — the backend's document template. Login/setup override
    // this to 'html-guest-skeleton' via their controller configs. (The legacy
    // 'html-default-skeleton' was removed in the shell cleanup; `LayoutDefaults::SKELETON`
    // stays a generic core default, unused here since this config always sets documentTpl.)
    'documentTpl' => [
        'name'      => 'html-shell-skeleton',
        'nameSpace' => 'Z77\\Module\\Backend',
    ],
    // Single stylesheet: base.css carries tokens + all components + their own responsive
    // rules (the shell + overview are self-contained). The legacy per-breakpoint layout
    // files (mobile/tablet/desktop/nav-*) were retired in the shell cleanup.
    'styleSheets' => [
        ['nameSpace' => 'Z77\\Module\\Backend', 'name' => 'base', 'media' => ''],
    ],
    'levelElements' => [
        'head' => [
            'meta' => 'partials/head/meta',
            'seo'  => 'partials/head/seo',
        ],
        'body' => [
            'iconSprite' => 'partials/icon-sprite',
            'noindexBanner' => 'partials/shell/noindex-banner', // site-wide crawl-block Störer (SEO-NOINDEX-001)
            'systemBanner'  => 'partials/shell/system-banner',  // unconfigured installation identity (ADR-030)
            'shellTopbar' => 'partials/shell/topbar', // shell topbar (used by html-shell-skeleton)
            'subnav'  => 'partials/subnav',
            // 'preview' (shell column 3) removed 2026-08-08 — never switched on; detail beside
            // a list belongs to the workspace (.z77-split). See html-shell-skeleton.tpl.php.
            // 'main' is intentionally absent — resolved dynamically from current controller/action
            'flash'    => 'partials/flashMessages',
            'messages' => 'partials/popupMessages',
        ],
    ],
    // Available keys per entry: name, nameSpace, position ('head' | 'footer', default 'footer'), defer (bool), async (bool).
    // Use position 'head' for scripts that must run before first paint (analytics, anti-FOUC, global config).
    // Example (commented):
    //     ['name' => 'analytics', 'nameSpace' => 'Z77\\Module\\Backend', 'position' => 'head', 'async' => true],
    'javascripts' => [
        ['name' => 'core',         'nameSpace' => 'Z77\\Shared',          'defer' => true],
        ['name' => 'panel-toggle', 'nameSpace' => 'Z77\\Shared',          'defer' => true],
        // Shared pane resizing + narrow-screen detail overlay (z77-split). Drives the shell's
        // own column-1 handle as well as any workspace inside column 2.
        ['name' => 'split',        'nameSpace' => 'Z77\\Shared',          'defer' => true],
        ['name' => 'appearance',   'nameSpace' => 'Z77\\Module\\Backend', 'defer' => true],
        ['name' => 'system/cache', 'nameSpace' => 'Z77\\Module\\Backend', 'defer' => true],
        ['name' => 'shell',        'nameSpace' => 'Z77\\Module\\Backend', 'defer' => true],
    ],
];
