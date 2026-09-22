<?php

/**
 * Backend access + menu harness (CLI) — ADR-045 §1 + §2.
 *
 * What is load-bearing here:
 *
 *   - the role `editor` sits at level 50, between cronJob and admin;
 *   - AuthService::requiredRole() — the ONE resolution AccessGuard and the
 *     backend menu share — over the REAL backendConfig array: the content
 *     editor, the dashboard and save-preferences are EDITOR, deleting content
 *     and every other backend screen stays ADMIN (or stricter), login GUEST;
 *   - BackendMenu::allowsIn() / visibleIn(): a leaf is shown when its target is
 *     reachable, a ref follows its target, a section or opener is shown only
 *     with a visible child, a leaf without target is hidden.
 *
 * Run: php tests/backend-access.php
 * No DI: the classes are required through a PSR-4 map of this checkout; the
 * reachability callable below does what AuthService::canReach() does minus the
 * module lookup (the config array is passed in directly).
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Module\\Backend\\' => '/../packages/module-backend/src/',
        'Z77\\Shared\\'          => '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\'     => '/../packages/kernel/persistence/src/',
        'Z77\\Core\\'            => '/../packages/kernel/core/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Core\Config\AuthRole;
use Z77\Module\Backend\Ui\BackendMenu;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Services\AuthService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

// ── the role ────────────────────────────────────────────────────────────────
echo "AuthRole\n";
$h = AuthRole::getRoleHierarchy();
check('editor exists at level 50', ($h[AuthRole::EDITOR] ?? null) === 50);
check('editor between cronJob and admin', $h[AuthRole::CRON_JOB] < $h[AuthRole::EDITOR] && $h[AuthRole::EDITOR] < $h[AuthRole::ADMIN]);
check('levels stay unique', count(array_unique($h)) === count($h));
check('editor satisfies member, not admin', AuthRole::rolesSatisfy(['editor'], AuthRole::MEMBER) && !AuthRole::rolesSatisfy(['editor'], AuthRole::ADMIN));

// ── the resolution over the real backend config ─────────────────────────────
echo "AuthService::requiredRole (backendConfig)\n";
$config = require __DIR__ . '/../packages/module-backend/src/App/Config/backendConfig.inc.php';
$role   = fn(string $group, string $controller, string $action): string => AuthService::requiredRole(
    $config['moduleRole'], $config['controllers'], $group,
    Naming::toCamelCase($controller) . 'Controller', Naming::toActionMethod($action)
);

$expect = [
    ['content', 'content',    'list',            AuthRole::EDITOR],
    ['content', 'content',    'edit',            AuthRole::EDITOR],
    ['content', 'content',    'add',             AuthRole::EDITOR],
    ['content', 'content',    'actions',         AuthRole::EDITOR],
    ['content', 'content',    'toggle-active',   AuthRole::EDITOR],
    ['content', 'content',    'create-variant',  AuthRole::EDITOR],
    ['content', 'content',    'confirm-publish', AuthRole::EDITOR],
    ['content', 'content',    'publish',         AuthRole::EDITOR],
    ['content', 'content',    'confirm-restore', AuthRole::EDITOR],
    ['content', 'content',    'restore',         AuthRole::EDITOR],
    ['content', 'content',    'confirm-delete',  AuthRole::ADMIN],
    ['content', 'content',    'remove',          AuthRole::ADMIN],
    ['system',  'dashboard',  'overview',        AuthRole::EDITOR],
    ['system',  'system',     'save-preferences', AuthRole::EDITOR],
    ['system',  'system',     'toggle-debug',    AuthRole::ADMIN],
    ['system',  'system',     'toggle-noindex',  AuthRole::ADMIN],
    ['system',  'system',     'clear-cache',     AuthRole::ADMIN],
    ['system',  'login',      'logout',          AuthRole::GUEST],
    ['system',  'backend-user', 'list',          AuthRole::ADMIN],
    ['content', 'navigation', 'list',            AuthRole::ADMIN],
    ['content', 'meta-data',  'list',            AuthRole::ADMIN],
    ['service', 'backup',     'list',            AuthRole::SUPER_USER],
    ['content', 'unknown',    'list',            AuthRole::ADMIN],   // a forgotten controller is never open
];
foreach ($expect as [$group, $controller, $action, $want]) {
    $got = $role($group, $controller, $action);
    check("{$group}/{$controller}/{$action} → {$want}", $got === $want, $got);
}

// Pure-rule details independent of the shipped config.
$cfg = ['g' => ['FooController' => ['controllerRole' => 'editor', 'actions' => ['barAction' => 'admin', '*' => 'member']]],
        'w' => ['*' => ['controllerRole' => 'guest']]];
check('action role wins over controller role', AuthService::requiredRole('admin', $cfg, 'g', 'FooController', 'barAction') === 'admin');
check('action wildcard', AuthService::requiredRole('admin', $cfg, 'g', 'FooController', 'bazAction') === 'member');
check('group controller wildcard', AuthService::requiredRole('admin', $cfg, 'w', 'AnyController', 'xAction') === 'guest');
check('unlisted → module role', AuthService::requiredRole('admin', $cfg, 'g', 'OtherController', 'xAction') === 'admin');
check('no module role → guest', AuthService::requiredRole(null, [], 'g', 'OtherController', 'xAction') === 'guest');

$editor = new AuthUser(['id' => 5, 'user_name' => 'anna', 'roles' => ['editor']]);
$admin  = new AuthUser(['id' => 1, 'user_name' => 'root', 'roles' => ['admin']]);
check('gate: editor passes an EDITOR route', AuthService::hasSufficientRole($editor, $role('content', 'content', 'publish')));
check('gate: editor refused on delete', !AuthService::hasSufficientRole($editor, $role('content', 'content', 'remove')));
check('gate: admin passes delete', AuthService::hasSufficientRole($admin, $role('content', 'content', 'remove')));
check('gate: unknown required role denies (fail-secure kept)', !AuthService::hasSufficientRole($admin, ''));

// ── the menu rule ───────────────────────────────────────────────────────────
echo "BackendMenu\n";

function nav(int $id, ?int $parent, string $name, string $target = '', ?int $ref = null): Navigation
{
    $parts = $target === '' ? ['', '', '', ''] : explode('/', $target);
    $n = new Navigation(['name' => $name, 'module' => $parts[0], 'group' => $parts[1],
                         'controller' => $parts[2], 'action' => $parts[3], 'ref' => $ref]);
    $n->setParentId($parent);
    (new ReflectionProperty(Navigation::class, 'id'))->setValue($n, $id);
    return $n;
}

// Webseiten: Inhalte (EDITOR), Metadaten (ADMIN)
// Stammdaten: Navigation (ADMIN) with a child «Nav Alias» (ADMIN); a ref to Inhalte
// Service: Backup (SUPER_USER)
// Leer: only an inert leaf
// Tief: an opener whose only child is ADMIN
$entries = [
    nav(1, null, 'Webseiten'),
    nav(19, 1, 'Inhalte', 'backend/content/content/list'),
    nav(20, 1, 'Metadaten', 'backend/content/meta-data/list'),
    nav(2, null, 'Stammdaten'),
    nav(6, 2, 'Navigation', 'backend/content/navigation/list'),
    nav(21, 6, 'Nav Alias', 'backend/content/navigation_alias/list'),
    nav(40, 2, 'Texte (Verweis)', '', 19),
    nav(25, null, 'Service'),
    nav(26, 25, 'Backup', 'backend/service/backup/list'),
    nav(50, null, 'Leer'),
    nav(51, 50, 'Platzhalter'),
    nav(60, null, 'Tief'),
    nav(61, 60, 'Gruppe'),
    nav(62, 61, 'Benutzer', 'backend/system/backend-user/list'),
    nav(70, null, 'Kaputt'),
    nav(71, 70, 'Verweis ins Leere', '', 999),
];
$byId     = [];
foreach ($entries as $e) { $byId[$e->getId()] = $e; }
$children = fn(Navigation $e): array => array_values(array_filter($entries, fn(Navigation $c) => $c->getParentId() === $e->getId()));
$findById = fn(int $id): ?Navigation => $byId[$id] ?? null;

$menuFor = function (AuthUser $user) use ($config, $children, $findById): Closure {
    $reachable = fn(string $m, string $g, string $c, string $a): bool => AuthService::hasSufficientRole($user, AuthService::requiredRole(
        $config['moduleRole'], $config['controllers'], $g, Naming::toCamelCase($c) . 'Controller', Naming::toActionMethod($a)
    ));
    $allows = fn(Navigation $e): bool => BackendMenu::allowsIn($e, $findById, $reachable);
    $memo   = [];
    return function (Navigation $e) use ($children, $allows, &$memo): bool {
        return BackendMenu::visibleIn($e, $children, $allows, $memo);
    };
};

$ed = $menuFor($editor);
check('editor sees «Webseiten»', $ed($byId[1]));
check('editor sees «Inhalte»', $ed($byId[19]));
check('editor does not see «Metadaten»', !$ed($byId[20]));
check('editor sees «Stammdaten» only through the ref to Inhalte', $ed($byId[2]) && $ed($byId[40]) && !$ed($byId[6]));
check('editor does not see «Service»', !$ed($byId[25]));
check('nobody sees a section of inert leaves', !$ed($byId[50]) && !$menuFor($admin)($byId[50]));
check('editor does not see an opener with only admin children', !$ed($byId[61]) && !$ed($byId[60]));
check('a dangling ref is hidden', !$ed($byId[71]) && !$ed($byId[70]));

$ad = $menuFor($admin);
check('admin sees «Metadaten» + «Navigation» + nested «Benutzer»', $ad($byId[20]) && $ad($byId[6]) && $ad($byId[60]));
check('admin does not see the SUPER_USER «Service»', !$ad($byId[25]));
$su = $menuFor(new AuthUser(['id' => 2, 'user_name' => 'su', 'roles' => ['superUser']]));
check('superUser sees «Service»', $su($byId[25]));
$guest = $menuFor(new AuthUser());
check('guest sees nothing', !$guest($byId[1]) && !$guest($byId[2]) && !$guest($byId[25]));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
