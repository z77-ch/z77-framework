<?php
namespace Z77\Module\Frontend\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Frontend\Content\Renderer\HeroRenderer;
use Z77\Module\Frontend\Content\Renderer\FeatureGridRenderer;
use Z77\Module\Frontend\Content\Renderer\ProseSectionRenderer;

return [
    'defaultGroup'  => 'main',
    'groupDefaults' => [
        'main' => 'index',
    ],

    // Content block types this module contributes to the BlockRegistry
    // (folded in on demand by BlockRegistry::assemble(), adr-012). Renderers are
    // stateless (no-arg constructible).
    'contentBlocks' => [
        HeroRenderer::class,
        FeatureGridRenderer::class,
        ProseSectionRenderer::class,
    ],
    // View area: this module owns a layout and is a top-level UI environment.
    // The environment identity is the module key; its display label + navigation
    // slots live here in config (not an editable entity). See ADR-022.
    'viewArea'      => true,
    // Display label of this environment (was the NavigationGroup env-row label).
    'viewAreaLabel' => 'Frontend',
    // Navigation slots (render areas) of this environment: ordered map slotKey → label.
    // The full slug used in data + templates is `{moduleKey}-{slotKey}` (e.g.
    // `frontend-meta`). A slot only renders where a layout template calls
    // NavigationService::getBySlot('{slug}'); adding an area = a slot here + one
    // render call (ADR-022).
    'navSlots'      => [
        'main' => 'Hauptnavigation',
        'meta' => 'Fusszeile',
    ],
    // Public environment: publicly reachable + indexable → its pages warrant SEO
    // metadata (drives the backend metadata list scope). See docs/topics/metadata.md.
    'public'        => true,
    // Languages this environment offers in its switch UI (opt-in, ADR-013 / i18n.md).
    // A subset of the global `config/i18n` whitelist; > 1 entry shows the switch.
    // Omitting this key = no switch (e.g. the admin backend stays single-language).
    'languages'     => ['de', 'en', 'fr'],
    'defaultAction' => 'home',
    'moduleRole'    => AuthRole::SUPER_USER,
    'cache'         => [
        'enabled' => true,
        'ttl'     => 86400,
        'controllers' => [
            // Keys are URL SEGMENTS (Request::getController()/getAction()), not
            // class or method names.
            'index' => [
                'actions' => [
                    // The contact form carries a CSRF token and per-user form
                    // state — never share that from a page cache (cache.md).
                    'contact' => ['enabled' => false],
                    'check'   => ['enabled' => false],   // POST endpoint
                ],
            ],
        ],
    ],

    // Full structure (module-default → controller-override → action-override):
    //
    // 'cache' => [
    //     'enabled' => true,
    //     'ttl'     => 86400,
    //     'controllers' => [
    //         'contact' => [                              // URL segment
    //             'enabled' => true,
    //             'ttl'     => 600,
    //             'actions' => [
    //                 'send' => ['enabled' => false],     // POST endpoint, never cache
    //             ],
    //         ],
    //         'news' => ['ttl' => 60],
    //     ],
    // ],

    // Access-control config is nested by group (outer key), mirroring the
    // controller namespace. The inner `'*'` is a controller wildcard within the
    // group. See backendConfig + ADR-005 (revised 2026-06-02).
    'controllers' => [
        'main' => [
            // Overlay endpoint (adminOverlay form posts). The specific entry
            // replaces the '*' wildcard entirely → every action requires ADMIN.
            'AdminPanelController' => [
                'controllerRole' => AuthRole::ADMIN,
            ],
            '*' => [
                'controllerRole' => AuthRole::MEMBER,
                'actions' => [
                    'xindexAction' => AuthRole::VISITOR,
                    '*'            => AuthRole::GUEST,
                ],
            ],
        ],
        // Document delivery moved to module-dms (OutputController, ADR-017 §8 / R4c).
        // The former `media` group + MediaController + /media NavigationAlias are gone.
    ],
];
