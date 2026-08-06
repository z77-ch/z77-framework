<?php
namespace Z77\Module\Member\App;

use Z77\Core\Config\AuthRole;

/**
 * Member module — registration with confirmed e-mail (B7 origin).
 *
 * A view-area of its own with a deliberately minimal layout (see
 * Ui/Config/layoutConfig.inc.php — override whole per project to restyle).
 * All registration pages are GUEST by nature: the module's whole point is
 * that nobody is logged in yet.
 *
 * Routes (convention: /member/{group}/{controller}/{action}):
 *   /member/main/register          the form (GET render / POST submit)
 *   /member/main/register/danke    PRG target
 *   /member/main/register/check    per-field blur validation (fetch)
 *   /member/main/confirm?token=…   confirmation landing
 *   /member/main/resend            request a fresh link (+ danke, check)
 */
return [
    'defaultGroup'  => 'main',
    'groupDefaults' => [
        'main' => 'register',
    ],
    'defaultAction' => 'index',

    'viewArea'      => true,
    'viewAreaLabel' => 'Member',
    'public'        => true,

    'moduleRole'    => AuthRole::GUEST,

    // Form pages carry CSRF tokens and per-user state — never page-cached.
    'cache' => [
        'enabled' => false,
    ],

    'controllers' => [
        'main' => [
            'RegisterController' => [
                'defaultAction'  => 'index',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'indexAction' => AuthRole::GUEST,
                    'dankeAction' => AuthRole::GUEST,
                    'checkAction' => AuthRole::GUEST,
                ],
            ],
            'ConfirmController' => [
                'defaultAction'  => 'index',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'indexAction' => AuthRole::GUEST,
                ],
            ],
            'ResendController' => [
                'defaultAction'  => 'index',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'indexAction' => AuthRole::GUEST,
                    'dankeAction' => AuthRole::GUEST,
                    'checkAction' => AuthRole::GUEST,
                ],
            ],
        ],
    ],
];
