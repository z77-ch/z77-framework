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
    'javascripts' => [],
];
