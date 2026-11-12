<?php
namespace Z77\Module\Api\App;

use Z77\Core\Config\AuthRole;

// ⚠️ A project override of this file REPLACES it (first-source-match) — an
// override must carry the FULL config, not just the added keys. What a project
// typically adds: `apiKeyResolver`, `apiServices`, `rateLimitPerHour`; it keeps
// `apiDeviceResolver` when the installation offers the device channel (ADR-057).
return [
    'defaultGroup'  => 'main',
    'groupDefaults' => [
        'main' => 'gateway',
    ],
    'defaultAction' => 'handle',
    // Roles never gate the API — the ApiKeyGuard replaces the AccessGuard on
    // the stateless path. GUEST here only keeps the config shape consistent.
    'moduleRole'    => AuthRole::GUEST,

    // Page caching MUST stay off: the reserved route maps ALL of /api/* onto
    // ONE 4-tuple while the tenant rides in a header — with caching enabled
    // every tenant and endpoint would share a single cache entry (the D2
    // tuple collision, see routing.md ROUTE-DYN-001). Do not rely on the
    // response-type check; declare it.
    'cache' => [
        'enabled' => false,
    ],

    // The stateless reserved route (routing.md → Stateless reserved routes):
    // /api/v1/units → slugs [v1, units]; the GatewayController parses version
    // and endpoint and dispatches to the declared service.
    'reservedRoutes' => [
        '/api' => [
            'module'     => 'api',
            'group'      => 'main',
            'controller' => 'gateway',
            'action'     => 'handle',
            'stateless'  => true,
        ],
    ],

    // Requests per tenant and hour (all endpoints, health included). 429 +
    // Retry-After past it. Sized for the snapshot pattern: sites revalidate
    // at TTL, monitoring polls health — hundreds/hour is generous headroom.
    'rateLimitPerHour' => 600,

    // endpoint => FQCN implementing Z77\Shared\Api\ApiServiceInterface.
    // Declared by the PROJECT override; `health` and `devices` are built in
    // (ServiceRegistry) and cannot be redeclared. A service that implements
    // ScopedApiServiceInterface is open to POST and to devices (ADR-057).
    'apiServices' => [],

    // FQCN implementing Z77\Shared\Auth\TenantKeyResolverInterface — declared
    // by the PROJECT override (exactly one per installation; the ApiKeyGuard
    // fails fast otherwise).
    // 'apiKeyResolver' => \Override\Namespace\ApiKeyResolver::class,

    // The device channel (ADR-057): paired phones/PCs reach the gateway with the
    // HttpOnly cookie `z77_api_device` instead of a bearer key. FQCN implementing
    // Z77\Shared\Auth\DeviceKeyResolverInterface; module-api ships it. Remove the
    // key (or leave it out of an override) and the channel is OFF: the cookie is
    // ignored, `POST /api/v1/devices/pair` answers 401 like any keyless call.
    'apiDeviceResolver' => \Z77\Module\Api\Services\ApiDevices::class,

    'controllers' => [
        'main' => [
            'GatewayController' => [
                'defaultAction'  => 'handle',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'handleAction' => AuthRole::GUEST,
                ],
            ],
        ],
    ],
];
