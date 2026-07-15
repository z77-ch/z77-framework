<?php
namespace Z77\Module\Frontend\Ui\Config;

return [
    'documentTpl' => [
        'name'      => 'html-default-skeleton',
        'nameSpace' => 'Z77\\Module\\Frontend',
    ],
    'styleSheets' => [
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'base',        'media' => ''],
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'mobile',      'media' => 'screen and (max-width: 767px)'],
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'tablet',      'media' => 'screen and (min-width: 768px) and (max-width: 1199px)'],
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'desktop',     'media' => 'screen and (min-width: 1200px)'],
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'nav-mobile',  'media' => 'screen and (max-width: 767px)'],
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'nav-tablet',  'media' => 'screen and (min-width: 768px) and (max-width: 1199px)'],
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'nav-desktop', 'media' => 'screen and (min-width: 1200px)'],
        ['nameSpace' => 'Z77\\Module\\Frontend', 'name' => 'messages',    'media' => ''],
    ],
    'levelElements' => [
        'head' => [
            'meta'            => 'partials/head/meta',
            'seo'             => 'partials/head/seo',
            'favicon'         => 'partials/head/favicon',
            'fonts'           => 'partials/head/fonts',
            'social'          => 'partials/head/social',
            'structured-data' => 'partials/head/structured-data',
        ],
        'body' => [
            'header' => 'partials/header',
            // 'main' is intentionally absent — resolved dynamically from current controller/action
            'footer'   => 'partials/footer',
            'flash'    => 'partials/flashMessages',
            'messages' => 'partials/popupMessages',
        ],
    ],
    'javascripts' => [
        ['name' => 'core', 'nameSpace' => 'Z77\\Shared', 'defer' => true],
    ],
];
