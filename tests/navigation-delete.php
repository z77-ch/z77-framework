<?php

/**
 * Deleting a navigation entry (CLI, real stack in PHP's built-in server) — navigation.md NAV-DELETE-001.
 * The child owns the tree link (`parent_id`), so removing a parent used to leave its children
 * orphaned: in no slot, invisible in the editor, still routable. What is load-bearing:
 *
 *   (A) the confirm dialog of an entry with children names their count and the way out
 *       («Zuerst die Unterpunkte entfernen oder verschieben») and offers no delete;
 *   (B) the remove POST refuses it as well (a crafted or stale request), the file unchanged;
 *   (C) a leaf is deleted as before; once the last child is gone, the parent is deleted too.
 *   (D) what is keyed to the entry alone goes with it: its aliases and its metadata (every
 *       language) are named in the confirm and removed by the delete — another entry's aliases
 *       and metadata stay. Nothing is left pointing at a deleted id.
 *   (E) an entry a ref entry points at is refused (confirm and POST), like one with children.
 *
 * Run: php tests/navigation-delete.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "navigation-delete: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$root = str_replace('\\', '/', dirname(__DIR__));
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-navigation-delete-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base, 0777, true);
register_shutdown_function(static function () use ($base): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($base);
});

$site = $base . '/site';
$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [['id' => 1, 'username' => 'max', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0]]);

// A parent with two children on top of the seed.
$navFile = $site . '/data/framework/routing/navigation.json';
$entry   = static fn(int $id, string $name, string $slot, ?int $parent, int $sort): array => [
    'id' => $id, 'key' => null, 'name' => $name, 'module' => 'frontend', 'group' => 'main', 'controller' => 'index',
    'action' => strtolower($name), 'slot' => $slot, 'ref' => null, 'active' => true, 'param' => '', 'sort_key' => $sort, 'parent_id' => $parent,
];
$rows   = json_decode((string) file_get_contents($navFile), true);
$rows[] = $entry(20, 'Angebot', 'frontend-main', null, 9);
$rows[] = $entry(21, 'Kurse', '', 20, 0);
$rows[] = $entry(22, 'Lager', '', 20, 1);
// A ref entry (a menu entry of its own) pointing at 22, hung under the seeded home page.
$rows[] = ['id' => 23, 'key' => null, 'name' => 'Zum Lager', 'module' => '', 'group' => '', 'controller' => '', 'action' => '',
    'slot' => '', 'ref' => 22, 'active' => true, 'param' => '', 'sort_key' => 0, 'parent_id' => 3];
file_put_contents($navFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$ids = static fn(): array => array_column(json_decode((string) file_get_contents($navFile), true), 'id');

// Aliases + metadata: two aliases and two languages for 21, one alias for 22, the seed's own
// (alias /home and metadata id 1 both belong to entry 3).
$aliasFile = $site . '/data/framework/routing/navigation_aliases.json';
$metaFile  = $site . '/data/framework/seo/metadata.json';
$aliases   = json_decode((string) file_get_contents($aliasFile), true);
$aliases[] = ['id' => 901, 'navigation_id' => 21, 'path' => '/kurse', 'is_canonical' => true, 'active' => true];
$aliases[] = ['id' => 902, 'navigation_id' => 21, 'path' => '/kurse-alt', 'is_canonical' => false, 'active' => true];
$aliases[] = ['id' => 903, 'navigation_id' => 22, 'path' => '/lager', 'is_canonical' => true, 'active' => true];
file_put_contents($aliasFile, json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$metas   = json_decode((string) file_get_contents($metaFile), true);
$metas[] = ['id' => 901, 'navigation_id' => 21, 'language' => 'de', 'title' => 'Kurse', 'description' => '', 'theme_color' => '', 'application_ld' => []];
$metas[] = ['id' => 902, 'navigation_id' => 21, 'language' => 'en', 'title' => 'Courses', 'description' => '', 'theme_color' => '', 'application_ld' => []];
file_put_contents($metaFile, json_encode($metas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$aliasIds = static fn(): array => array_column(json_decode((string) file_get_contents($aliasFile), true), 'id');
$metaIds  = static fn(): array => array_column(json_decode((string) file_get_contents($metaFile), true), 'id');
// navigation_id values in the alias + metadata files that name no existing entry.
$dangling = static function () use ($aliasFile, $metaFile, $ids): array {
    $fks = array_merge(
        array_column(json_decode((string) file_get_contents($aliasFile), true), 'navigation_id'),
        array_column(json_decode((string) file_get_contents($metaFile), true), 'navigation_id')
    );
    return array_values(array_diff($fks, $ids()));
};

$port = realFlowServer($site, $base . '/sessions', $base . '/server.log');
$b    = new RealFlowBrowser($port);
$r    = $b->go('/backend/system/login/login', ['csrf_token' => $b->pageToken('/login'), 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
check('signed in', in_array($r[0], [302, 303], true), (string) $r[0]);
$token = $b->pageToken('/backend/system/dashboard/overview');
check('a token from the shell', strlen($token) >= 20);

// The entity token is HMAC(context:id) over the session token (CsrfService::generateEntityToken()).
$entityToken = static fn(int $id): string => hash_hmac('sha256', 'navigation:' . $id, $token);

echo "(A) confirm dialog\n";
$c = $b->fetchGet('/backend/content/navigation/confirm-delete?id=20');
check('a parent: count and way out named', $c[0] === 200 && str_contains($c[2], '2 Unterpunkte') && str_contains($c[2], 'Zuerst die Unterpunkte entfernen oder verschieben'), $c[0] . ' ' . substr(strip_tags($c[2]), 0, 300));
check('…no delete form', !str_contains($c[2], 'navigation/remove'));
$c = $b->fetchGet('/backend/content/navigation/confirm-delete?id=21');
check('a leaf: the usual confirm with the delete form', str_contains($c[2], 'navigation/remove') && str_contains($c[2], 'wirklich löschen'));
check('…its entity token is the one the harness computes', str_contains($c[2], 'value="' . $entityToken(21) . '"'));
check('…names the aliases going with it', str_contains($c[2], 'Alias-Adressen: 2') && str_contains($c[2], '/kurse, /kurse-alt'), substr(strip_tags($c[2]), 0, 400));
check('…and the metadata (two languages)', str_contains($c[2], 'Metadaten (2 Sprachen)'));
$c = $b->fetchGet('/backend/content/navigation/confirm-delete?id=23');
check('an entry without aliases or metadata: no such list', str_contains($c[2], 'navigation/remove') && !str_contains($c[2], 'Alias-Adressen') && !str_contains($c[2], 'Metadaten'));

echo "(B) remove refused\n";
$r = $b->fetchPost('/backend/content/navigation/remove', ['id' => 20, 'entity_csrf' => $entityToken(20)], $token);
check('remove of a parent: refused', in_array(20, $ids(), true) && in_array(21, $ids(), true) && in_array(22, $ids(), true), $r[0] . ' ' . substr($r[2], 0, 200));
check('…with the message', str_contains($r[2], 'Zuerst die Unterpunkte entfernen oder verschieben'), substr($r[2], 0, 300));

echo "(C) leaf, then parent\n";
$r = $b->fetchPost('/backend/content/navigation/remove', ['id' => 21, 'entity_csrf' => $entityToken(21)], $token);
check('a leaf is deleted', !in_array(21, $ids(), true) && in_array(20, $ids(), true), $r[0] . ' ' . substr($r[2], 0, 200));

echo "(D) aliases and metadata go with the entry\n";
check('its aliases are gone', !in_array(901, $aliasIds(), true) && !in_array(902, $aliasIds(), true), implode(',', $aliasIds()));
check("…another entry's alias and the seed's stay", in_array(903, $aliasIds(), true) && in_array(1, $aliasIds(), true), implode(',', $aliasIds()));
check('its metadata (both languages) is gone', !in_array(901, $metaIds(), true) && !in_array(902, $metaIds(), true), implode(',', $metaIds()));
check("…the seed's metadata stays", in_array(1, $metaIds(), true), implode(',', $metaIds()));
check('nothing points at a deleted id', $dangling() === [], implode(',', $dangling()));

echo "(E) an entry a ref points at\n";
$c = $b->fetchGet('/backend/content/navigation/confirm-delete?id=22');
check('confirm: the ref named, no delete form', str_contains($c[2], 'zeigt ein Verweis') && str_contains($c[2], 'Zuerst die Verweise auf diesen Eintrag entfernen') && !str_contains($c[2], 'navigation/remove'), substr(strip_tags($c[2]), 0, 300));
$r = $b->fetchPost('/backend/content/navigation/remove', ['id' => 22, 'entity_csrf' => $entityToken(22)], $token);
check('remove: refused, entry and alias unchanged', in_array(22, $ids(), true) && in_array(903, $aliasIds(), true) && str_contains($r[2], 'Zuerst die Verweise'), $r[0] . ' ' . substr($r[2], 0, 200));
$b->fetchPost('/backend/content/navigation/remove', ['id' => 23, 'entity_csrf' => $entityToken(23)], $token);
check('the ref entry itself is deleted', !in_array(23, $ids(), true));
$c = $b->fetchGet('/backend/content/navigation/confirm-delete?id=20');
check('one child left: «einen Unterpunkt»', str_contains($c[2], 'einen Unterpunkt') && !str_contains($c[2], 'navigation/remove'));
$b->fetchPost('/backend/content/navigation/remove', ['id' => 22, 'entity_csrf' => $entityToken(22)], $token);
check('without the ref the last child is deleted, its alias with it', !in_array(22, $ids(), true) && !in_array(903, $aliasIds(), true));
$r = $b->fetchPost('/backend/content/navigation/remove', ['id' => 20, 'entity_csrf' => $entityToken(20)], $token);
check('without children the parent is deleted', !in_array(20, $ids(), true) && !in_array(22, $ids(), true), $r[0] . ' ' . substr($r[2], 0, 200));

$log = (string) @file_get_contents($site . '/logs/php-error.log') . (string) @file_get_contents($base . '/server.log');
check('no PHP fatal in the logs', !preg_match('/PHP Fatal/', $log), (string) (preg_match('/.*PHP Fatal.*/', $log, $m) ? $m[0] : ''));

echo "\nnavigation-delete: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
