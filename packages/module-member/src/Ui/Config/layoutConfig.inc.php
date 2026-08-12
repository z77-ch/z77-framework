<?php
namespace Z77\Module\Member\Ui\Config;

/**
 * Member view-area layout — deliberately minimal and neutral: a lean skeleton,
 * one stylesheet, no navigation. The module works out of the box in any z77
 * project; a project that wants its own look overrides THIS FILE whole
 * (override/z77/module/member/src/Ui/Config/layoutConfig.inc.php — first
 * FileFinder sourcePaths match wins, no merging) and swaps the styleSheets
 * entry for its own CSS — the module stylesheet is then not loaded at all.
 * Individual templates/partials are overridable the same way.
 */
return [
    'documentTpl' => [
        'name'      => 'html-default-skeleton',
        'nameSpace' => 'Z77\\Module\\Member',
    ],
    'styleSheets' => [
        ['nameSpace' => 'Z77\\Module\\Member', 'name' => 'member', 'media' => ''],
    ],
    'levelElements' => [
        'head' => [
            'meta' => 'partials/head/meta',
        ],
        'body' => [
            // 'main' is intentionally absent — resolved dynamically from current controller/action
            'flash' => 'partials/flashMessages',
        ],
    ],
    // core.js on every member page — it is the framework's ONE browser-side
    // transport (fetch + CSRF header + envelope/flash/command dispatch) plus
    // the shared `data-*` wiring (copy, fetch-post, fetch-get). Leaving it out
    // was a false economy: the member area then hand-rolled its own fetch,
    // forgot the `X-CSRF-Token` header the guard insists on, and `data-copy`
    // silently did nothing (FETCH-CSRF-001, 2026-08-12). The flash container
    // it writes into (`#flash-messages`) is already part of this module.
    'javascripts' => [
        ['name' => 'core', 'nameSpace' => 'Z77\\Shared', 'defer' => true],
    ],
];
