<?php
namespace Z77\Module\Frontend\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Frontend\Content\Renderer\HeroRenderer;
use Z77\Module\Frontend\Content\Renderer\FeatureGridRenderer;
use Z77\Module\Frontend\Content\Renderer\ProseSectionRenderer;
use Z77\Module\Frontend\Content\Renderer\TextSectionRenderer;
use Z77\Module\Frontend\Content\Renderer\CardsRenderer;
use Z77\Module\Frontend\Content\Renderer\ImageTextRenderer;
use Z77\Module\Frontend\Content\Renderer\CtaRenderer;
use Z77\Module\Frontend\Content\Renderer\FormRenderer;
use Z77\Module\Frontend\Content\Renderer\FooterRenderer;

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
        TextSectionRenderer::class,
        CardsRenderer::class,
        ImageTextRenderer::class,
        CtaRenderer::class,
        FormRenderer::class,
        FooterRenderer::class,
    ],
    // The starter pages (ADR-044 addendum 2026-10-22): one content document per
    // page and language (data/content/<slug>.<lang>.json, seeded on the first
    // install only), its structure fixed here. IndexController reads the slots
    // (PageContent) and hands each section partial its values plus the page-
    // editor marker. Together the pages show every starter block type once.
    // Deleting a page = deleting its documents (backend Webseiten › Inhalte) and
    // its menu entry; the URL then answers 404. A project that changes a
    // structure overrides this file (and its templates) — never vendor/.
    'contentBlueprints' => [
        'home' => [
            ['key' => 'hero',  'type' => 'hero',         'label' => 'Kopf'],
            ['key' => 'intro', 'type' => 'text-section', 'label' => 'Einleitung'],
            ['key' => 'cards', 'type' => 'cards',        'label' => 'Karten'],
            ['key' => 'cta',   'type' => 'cta',          'label' => 'Aufruf zum Kontakt'],
        ],
        'about' => [
            ['key' => 'hero',  'type' => 'hero',         'label' => 'Kopf'],
            ['key' => 'text',  'type' => 'text-section', 'label' => 'Text'],
            ['key' => 'image', 'type' => 'image-text',   'label' => 'Bild und Text'],
        ],
        'services' => [
            ['key' => 'hero',  'type' => 'hero',  'label' => 'Kopf'],
            ['key' => 'cards', 'type' => 'cards', 'label' => 'Leistungen'],
        ],
        'contact' => [
            ['key' => 'intro', 'type' => 'text-section', 'label' => 'Einleitung'],
            ['key' => 'form',  'type' => 'form',         'label' => 'Text über dem Formular'],
        ],
        'thanks' => [
            ['key' => 'hero', 'type' => 'hero', 'label' => 'Bestätigung'],
        ],
        // Impressum: the usual Swiss headings; a section left empty is not shown.
        'legal' => [
            ['key' => 'hero',            'type' => 'hero',         'label' => 'Kopf'],
            ['key' => 'address',         'type' => 'text-section', 'label' => 'Kontaktadresse'],
            ['key' => 'representatives', 'type' => 'text-section', 'label' => 'Vertretungsberechtigte Personen'],
            ['key' => 'register',        'type' => 'text-section', 'label' => 'Handelsregistereintrag'],
            ['key' => 'vat',             'type' => 'text-section', 'label' => 'Mehrwertsteuernummer'],
            ['key' => 'disclaimer',      'type' => 'text-section', 'label' => 'Haftungsausschluss'],
            ['key' => 'links',           'type' => 'text-section', 'label' => 'Haftung für Links'],
            ['key' => 'copyright',       'type' => 'text-section', 'label' => 'Urheberrechte'],
        ],
        // Datenschutzerklärung: the usual headings; a section left empty is not shown.
        'privacy' => [
            ['key' => 'hero',       'type' => 'hero',         'label' => 'Kopf'],
            ['key' => 'controller', 'type' => 'text-section', 'label' => 'Verantwortliche Stelle'],
            ['key' => 'visit',      'type' => 'text-section', 'label' => 'Daten beim Besuch der Website'],
            ['key' => 'cookies',    'type' => 'text-section', 'label' => 'Cookies'],
            ['key' => 'contact',    'type' => 'text-section', 'label' => 'Kontaktformular und E-Mail'],
            ['key' => 'thirdParty', 'type' => 'text-section', 'label' => 'Dienste Dritter'],
            ['key' => 'abroad',     'type' => 'text-section', 'label' => 'Bekanntgabe ins Ausland'],
            ['key' => 'retention',  'type' => 'text-section', 'label' => 'Aufbewahrungsdauer'],
            ['key' => 'rights',     'type' => 'text-section', 'label' => 'Ihre Rechte'],
            ['key' => 'changes',    'type' => 'text-section', 'label' => 'Änderungen'],
        ],
        // Shared by every page: the footer texts (partials/footer).
        'footer' => [
            ['key' => 'footer', 'type' => 'footer', 'label' => 'Fusszeile'],
        ],
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
        // Pages with an address but no menu place (owner 2026-10-22: «/thanks», the contact
        // form's PRG target) — the slot is rendered nowhere, like the backend's `auth`.
        'route' => 'Ohne Menü',
    ],
    // Public environment: publicly reachable + indexable → its pages warrant SEO
    // metadata (drives the backend metadata list scope). See docs/topics/metadata.md.
    'public'        => true,
    // Languages this environment offers in its switch UI (opt-in, ADR-013 / i18n.md).
    // A subset of the global `config/i18n` whitelist; > 1 entry shows the switch.
    // Omitting this key = no switch (e.g. the admin backend stays single-language).
    // The starter content ships in German and English (owner 2026-10-22). A
    // project with more languages overrides this file and adds its documents.
    'languages'     => ['de', 'en'],
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
            // replaces the '*' wildcard entirely → every action requires ADMIN,
            // except the page editor switch, which an editor needs (ADR-045 §4).
            'AdminPanelController' => [
                'controllerRole' => AuthRole::ADMIN,
                'actions' => [
                    'toggleContentEditAction' => AuthRole::EDITOR,
                ],
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
