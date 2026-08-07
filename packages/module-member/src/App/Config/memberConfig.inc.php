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

    // FQCN of an invokable class `__invoke(MemberAccount): ?string` — the
    // project side of activation (AXO3: creates the tenant, returns its ref).
    // Null = accounts activate without a project attachment. A project sets
    // this by overriding THIS FILE whole (override tree, first match wins).
    'activationHook' => null,

    // Path the «Sie sind freigeschaltet» mail links to (absolute URL is built
    // from the request host at send time) — the B8 login page.
    'memberEntryPath' => '/member/main/login',

    // Daily cleanup (bin/member-cleanup.php): never-confirmed accounts older
    // than this are deleted; 'confirmed' accounts always wait for an operator.
    'cleanupAfterDays' => 30,

    'viewArea'      => true,
    'viewAreaLabel' => 'Member',
    'public'        => true,
    'navSlots'      => [
        'main' => 'Hauptnavigation',
    ],
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
            // B8 — login is GUEST by nature (nobody is signed in yet); the
            // profile guards itself via MemberAuth (member sessions are not
            // the framework ACL — that one belongs to the admin login).
            'LoginController' => [
                'defaultAction'  => 'index',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'indexAction'  => AuthRole::GUEST,
                    'wartenAction' => AuthRole::GUEST,
                    'statusAction' => AuthRole::GUEST,
                    'dankeAction'  => AuthRole::GUEST,
                    'redeemAction' => AuthRole::GUEST,
                    'totpAction'   => AuthRole::GUEST,
                    'checkAction'  => AuthRole::GUEST,
                ],
            ],
            'LogoutController' => [
                'defaultAction'  => 'index',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'indexAction' => AuthRole::GUEST,
                ],
            ],
            'ProfileController' => [
                'defaultAction'  => 'index',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'indexAction'           => AuthRole::GUEST,
                    'totpAction'            => AuthRole::GUEST,
                    'totpRemoveAction'      => AuthRole::GUEST,
                    'deviceRemoveAction'    => AuthRole::GUEST,
                    'deviceRemoveAllAction' => AuthRole::GUEST,
                ],
            ],
        ],
    ],
];
