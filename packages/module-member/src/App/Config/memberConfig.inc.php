<?php
namespace Z77\Module\Member\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Member\Services\MemberAuthBridge;

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

    // Daily cleanup (job 'member-cleanup', or bin/member-cleanup.php by hand):
    // never-confirmed accounts older than this are deleted; 'confirmed'
    // accounts always wait for an operator.
    'cleanupAfterDays' => 30,

    // Background jobs this module offers to the runner (ADR-031). The key is
    // what a queue entry stores — never a class name or a script path, so a
    // backend form can only ever pick from this list.
    //
    // No 'defaultSchedule' on purpose: this job DELETES. It runs when an
    // operator switches a schedule on, or when someone queues it by hand —
    // never merely because the module was installed.
    'jobs' => [
        'member-cleanup' => [
            'class'       => \Z77\Module\Member\Jobs\MemberCleanupJob::class,
            'label'       => 'Member-Bereinigung',
            'runAs'       => AuthRole::CRON_JOB,
            'maxAttempts' => 3,
        ],
    ],

    // How many login links one address and one browser may trigger per hour.
    // Feeds BOTH layers: the per-address throttle (file-based, survives a new
    // session) and the login form's per-session limit (silent — the waiting
    // page appears either way, MEM-005/MEM-010). It protects a stranger's
    // mailbox from being flooded through this form; raise it where support or
    // testing needs more headroom.
    'loginRequestsPerHour' => 5,

    'viewArea'      => true,
    'viewAreaLabel' => 'Member',
    'public'        => true,
    'navSlots'      => [
        'main' => 'Hauptnavigation',
    ],
    'moduleRole'    => AuthRole::GUEST,

    // The member login feeds the framework ACL (ADR-029, second decision):
    // the bridge projects the member session into `auth_user` (realm member,
    // role `customer`) before AccessGuard runs — protected routes below carry
    // AuthRole::CUSTOMER instead of guarding themselves.
    'authBridges'   => [MemberAuthBridge::class],

    // Where AccessGuard sends a visitor who lacks the required role in THIS
    // module — the member login, not the admin login.
    'loginUrl'      => '/member/main/login',

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
            // B8 — login is GUEST by nature (nobody is signed in yet).
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
            // Signed-in customers only — enforced by AccessGuard via the
            // AuthBridge projection; a guest is sent to `loginUrl` above. The
            // controller still loads its account through MemberAuth (it needs
            // the entity, not just the role).
            'ProfileController' => [
                'defaultAction'  => 'index',
                'controllerRole' => AuthRole::CUSTOMER,
                'actions'        => [
                    'indexAction'           => AuthRole::CUSTOMER,
                    'totpAction'            => AuthRole::CUSTOMER,
                    'totpRemoveAction'      => AuthRole::CUSTOMER,
                    'deviceRemoveAction'    => AuthRole::CUSTOMER,
                    'deviceRemoveAllAction' => AuthRole::CUSTOMER,
                ],
            ],
        ],
    ],
];
