<?php
namespace Z77\Module\Member\App;

use Z77\Core\Config\AuthRole;

/**
 * Member module — registration with confirmed e-mail (B7 origin).
 *
 * No view-area of its own (yet): the module currently ships domain work
 * (entities, token mechanism, account lifecycle). The registration routes and
 * their rendering surface follow with the controllers; whether they render
 * through an own layout or borrow a host module's view-area is decided there.
 */
return [
    'defaultGroup'  => 'main',
    'groupDefaults' => [
        'main' => 'register',
    ],
    'defaultAction' => 'index',
    'moduleRole'    => AuthRole::GUEST,

    // Registration pages carry forms and per-request state — never page-cached.
    'cache' => [
        'enabled' => false,
    ],

    // Controllers (register / confirm / resend) arrive with the route stage.
    'controllers' => [],
];
