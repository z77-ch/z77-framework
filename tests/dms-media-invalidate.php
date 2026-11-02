<?php

/**
 * Folder changes and the static copies, the real flow (CLI, temp installation, PHP's built-in server,
 * which serves an existing file itself like Apache) — documents.md DMS-INVALIDATE-001. A public copy
 * left behind is a security failure, a hand-placed file removed is a data loss. What is load-bearing:
 *
 *   (A) a gate change (protected, inactive) on a folder: no copy of its documents or its subfolders'
 *       stays reachable; hand-placed files, the guard and a link survive, the link's target untouched;
 *   (B) a rename of a folder and of a partition: nothing reachable at the old path, the document
 *       answers at the new one, hand-placed files and their guard stay where they were, empty
 *       directories go, a partition with nothing else left goes with its guard;
 *   (C) a move, a delete, a document in the trash, a stale variant, a copy from older rules;
 *   (D) a link: never followed by the invalidation, no copy written behind one, no folder walk into it;
 *   (E) a new partition does not take over a hand-placed directory; a partition keeps its own slug.
 *
 * Run: php tests/dms-media-invalidate.php
 */

$root = str_replace('\\', '/', dirname(__DIR__));
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "dms-media-invalidate: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require __DIR__ . '/support/real-flow-site.php';
require __DIR__ . '/support/real-flow-server.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** Directory link: symlink where allowed, NTFS junction on Windows. */
function makeDirLink(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        exec('cmd /c mklink /J "' . str_replace('/', '\\', $link) . '" "' . str_replace('/', '\\', $target) . '" 2>NUL', $out, $rc);
        return $rc === 0;
    }
    return false;
}

function removeDirLink(string $link): void
{
    if (!@rmdir($link)) {
        @unlink($link);
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-media-invalidate-' . getmypid() . '-' . bin2hex(random_bytes(3));
$site = $base . '/site';
mkdir($base, 0777, true);
$links = [];
register_shutdown_function(static function () use ($base, &$links): void {
    foreach ($links as $l) {
        removeDirLink($l); // a link first: the tree walk below must not reach through it
    }
    removeTree($base);
});

$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [['id' => 1, 'username' => 'max', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0]]);
$file = $site . '/config/moduleManager.inc.php';
file_put_contents($file, str_replace("'backend' => \n", "'dms' => [], 'backend' => \n", (string) file_get_contents($file)));
$file = $site . '/config/fileFinder.inc.php';
file_put_contents($file, str_replace("        'Z77\\\\Module\\\\Backend\\\\'", "        'Z77\\\\Module\\\\Dms\\\\'      => \$entry('module-dms'),\n        'Z77\\\\Module\\\\Backend\\\\'", (string) file_get_contents($file)));
$assets = $root . '/packages/module-dms/res/assets';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS)) as $asset) {
    $to = $site . '/public/assets/dms' . str_replace('\\', '/', substr($asset->getPathname(), strlen($assets)));
    if ($asset->isFile()) {
        @mkdir(dirname($to), 0777, true);
        copy($asset->getPathname(), $to);
    }
}

$jpeg = "\xFF\xD8\xFF\xE0 jpeg bytes \0\xff";
$pdf  = "%PDF-1.4\n";
$row = static function (int $id, int $folder, string $slug, string $ext, string $mime, string $kind, array $variants = [], array $extra = []) use ($site, $jpeg, $pdf): array {
    $bytes = $ext === 'pdf' ? $pdf : $jpeg;
    @mkdir("{$site}/data/blobs/0/{$id}", 0777, true);
    file_put_contents("{$site}/data/blobs/0/{$id}/orig.{$ext}", $bytes);
    foreach ($variants as $name => $meta) {
        file_put_contents("{$site}/data/blobs/0/{$id}/{$name}.{$meta['ext']}", $bytes);
    }

    return $extra + ['id' => $id, 'folder_id' => $folder, 'display_name' => $slug, 'original_name' => "{$slug}.{$ext}", 'ext' => $ext, 'mime_type' => $mime,
        'kind' => $kind, 'size_bytes' => strlen($bytes), 'checksum' => str_repeat(dechex($id % 16), 64), 'active' => true, 'slug' => $slug, 'sort_key' => $id, 'variants' => $variants];
};
$w480 = ['w480' => ['ext' => 'jpg', 'mime' => 'image/jpeg', 'w' => 480, 'h' => 320, 'size' => 10]];
mkdir($site . '/data/documents', 0777, true);
file_put_contents($site . '/data/documents/documents.json', json_encode([
    $row(1, 2, 'foto', 'jpg', 'image/jpeg', 'image', $w480),
    $row(2, 3, 'bild', 'jpg', 'image/jpeg', 'image', $w480),
    $row(3, 4, 'schrift', 'pdf', 'application/pdf', 'pdf'),
    $row(4, 5, 'gezuegelt', 'jpg', 'image/jpeg', 'image'),
    $row(5, 7, 'einzig', 'jpg', 'image/jpeg', 'image'),
    $row(6, 3, 'weg', 'jpg', 'image/jpeg', 'image', [], ['deleted_at' => date(DATE_ATOM)]),
    $row(7, 9, 'dahinter', 'jpg', 'image/jpeg', 'image'),
], JSON_PRETTY_PRINT));
$folder = static fn(int $id, ?int $parent, string $name, string $slug): array => ['id' => $id, 'name' => $name, 'active' => true, 'slug' => $slug, 'delivery_mode' => null, 'sort_key' => $id, 'parent_id' => $parent];
file_put_contents($site . '/data/documents/folders.json', json_encode([
    ['id' => 1, 'name' => 'Drive', 'key' => 'drive', 'system' => true, 'active' => true, 'slug' => 'drive', 'delivery_mode' => null, 'sort_key' => 0, 'parent_id' => null],
    ['delivery_mode' => 'public'] + $folder(2, 1, 'Offen', 'offen'),
    $folder(3, 2, 'Bilder', 'bilder'),
    $folder(4, 3, 'Tief', 'tief'),
    $folder(5, 2, 'Reise', 'reise'),
    ['delivery_mode' => 'public'] + $folder(6, 1, 'Ziel', 'ziel'),
    ['delivery_mode' => 'public'] + $folder(7, 1, 'Allein', 'allein'),
    $folder(8, 2, 'Leer', 'leer'),
    ['delivery_mode' => 'public'] + $folder(9, 1, 'Verlinkt', 'verlinkt'),
], JSON_PRETTY_PRINT));

$media = $site . '/public/media';
$place = static function (string $rel, string $body) use ($media): void {
    @mkdir(dirname($media . '/' . $rel), 0777, true);
    file_put_contents($media . '/' . $rel, $body);
};
// The hand-placed tree: in the partition, in a subfolder's directory, in a directory no folder has, beside the partitions.
$hand = [
    'offen/hand.js'             => 'hand js',
    'offen/logo-hand.png'       => 'hand png',
    'offen/bilder/hand.jpg'     => 'hand jpg',
    'offen/bilder/tief/hand.txt' => 'hand txt',
    'offen/eigen/x.png'         => 'hand x',
    'demo/app.js'               => 'beside js',
    'demo/sub/app.css'          => 'beside css',
];
foreach ($hand as $rel => $body) {
    $place($rel, $body);
}
$handIntact = static function () use (&$hand, $media): array {
    $bad = [];
    foreach ($hand as $rel => $body) {
        if ((string) @file_get_contents($media . '/' . $rel) !== $body) {
            $bad[] = $rel;
        }
    }
    return $bad;
};

// A link inside a partition: to a tree outside, which must never be touched.
$outside = $base . '/outside';
mkdir($outside, 0777, true);
file_put_contents($outside . '/foto.jpg', 'outside foto');
file_put_contents($outside . '/bild.jpg', 'outside bild');
$hasLink = makeDirLink($outside, $media . '/offen/bilder/verknuepft');
if ($hasLink) {
    $links[] = $media . '/offen/bilder/verknuepft';
}

mkdir($base . '/sessions', 0777, true);
$port = realFlowServer($site, $base . '/sessions', $base . '/server.log');
$guest = static fn(): RealFlowBrowser => new RealFlowBrowser($port);
$admin = new RealFlowBrowser($port);
$tok = $admin->pageToken('/login');
$r = $admin->go('/backend/system/login/login', ['csrf_token' => $tok, 'username' => 'max', 'password' => 'Passwort-Sehr-Lang-1']);
check('signed in', $r[0] === 302 || $r[0] === 303, (string) $r[0]);
$token = $admin->pageToken('/backend/system/dashboard/overview');
$api = '/backend/documents/drive/';
$entity = static fn(int $id): string => hash_hmac('sha256', 'folder:' . $id, $token);

$gateMode = static function (int $id, string $name, string $mode) use ($admin, $api, $entity, $token): array {
    return $admin->fetchPost($api . 'folder-edit?id=' . $id, ['op' => 'save', 'id' => $id, 'entity_csrf' => $entity($id), 'name' => $name, 'mode' => $mode], $token);
};
$rename = static function (int $id, string $name, string $mode = '') use ($admin, $api, $entity, $token): array {
    return $admin->fetchPost($api . 'folder-edit?id=' . $id, ['op' => 'save', 'id' => $id, 'entity_csrf' => $entity($id), 'name' => $name, 'mode' => $mode], $token);
};
$active = static function (int $id, bool $value) use ($admin, $api, $token): array {
    return $admin->fetchPost($api . 'actions?type=folder&id=' . $id, ['value' => $value], $token);
};
/** A public hit: the copy is written, the second one is static. */
$fill = static function (string $path) use ($guest, $media): bool {
    $g = $guest();
    $r = $g->go('/media/' . $path);
    return $r[0] === 200 && is_file($media . '/' . $path);
};
$reachable = static function (string $path) use ($guest, $media): string {
    $before = is_file($media . '/' . $path);
    $r = $guest()->go('/media/' . $path);
    $after = is_file($media . '/' . $path);

    return $before || $after || $r[0] === 200 ? 'on disk before: ' . (int) $before . ', after: ' . (int) $after . ', status ' . $r[0] : '';
};

// ── (A) a gate change ───────────────────────────────────────────────────────
echo "(A) gate change\n";
foreach (['offen/foto.jpg', 'offen/foto.w480.jpg', 'offen/bilder/bild.jpg', 'offen/bilder/bild.w480.jpg', 'offen/bilder/tief/schrift.pdf', 'offen/reise/gezuegelt.jpg'] as $p) {
    check("a public hit writes the copy: {$p}", $fill($p));
}
check('the partition guard exists', str_contains((string) @file_get_contents($media . '/offen/.htaccess'), 'ForceType None'));
$guard = (string) file_get_contents($media . '/offen/.htaccess');

$r = $gateMode(3, 'Bilder', 'protected');
check('folder set to protected', !str_contains($r[2], '"status":"error"'), substr($r[2], 0, 200));
foreach (['offen/bilder/bild.jpg', 'offen/bilder/bild.w480.jpg', 'offen/bilder/tief/schrift.pdf'] as $p) {
    check("protected: no copy, no answer for a guest: {$p}", $reachable($p) === '', $reachable($p));
}
check('protected: the sibling\'s copies stay', is_file($media . '/offen/foto.jpg') && is_file($media . '/offen/reise/gezuegelt.jpg'));
check('protected: every hand-placed file stays', $handIntact() === [], implode(',', $handIntact()));
check('protected: the guard stays, unchanged', (string) @file_get_contents($media . '/offen/.htaccess') === $guard);
if ($hasLink) {
    check('protected: the link stays, its target untouched', is_dir($media . '/offen/bilder/verknuepft') && file_get_contents($outside . '/foto.jpg') === 'outside foto' && file_get_contents($outside . '/bild.jpg') === 'outside bild');
}

$r = $gateMode(3, 'Bilder', '');
check('public again', !str_contains($r[2], '"status":"error"'), substr($r[2], 0, 200));
check('...the copy is written again', $fill('offen/bilder/bild.jpg') && $fill('offen/bilder/tief/schrift.pdf'));

$active(3, false);
foreach (['offen/bilder/bild.jpg', 'offen/bilder/tief/schrift.pdf'] as $p) {
    check("inactive: nothing reachable: {$p}", $reachable($p) === '', $reachable($p));
}
check('inactive: hand-placed files stay', $handIntact() === []);
$active(3, true);
$fill('offen/bilder/bild.jpg');
$fill('offen/bilder/tief/schrift.pdf');

// The whole partition protected: the guard and the hand-placed files of the partition stay.
$r = $gateMode(2, 'Offen', 'protected');
check('partition protected', !str_contains($r[2], '"status":"error"'), substr($r[2], 0, 200));
foreach (['offen/foto.jpg', 'offen/foto.w480.jpg', 'offen/bilder/bild.jpg', 'offen/reise/gezuegelt.jpg'] as $p) {
    check("partition protected: nothing reachable: {$p}", $reachable($p) === '', $reachable($p));
}
check('partition protected: the guard stays', (string) @file_get_contents($media . '/offen/.htaccess') === $guard);
check('partition protected: hand-placed files stay', $handIntact() === []);
check('partition protected: an empty directory goes', !is_dir($media . '/offen/reise'));
$gateMode(2, 'Offen', 'public');

// ── (B) a rename ────────────────────────────────────────────────────────────
echo "(B) rename\n";
foreach (['offen/bilder/bild.jpg', 'offen/bilder/tief/schrift.pdf', 'offen/foto.jpg', 'offen/reise/gezuegelt.jpg'] as $p) {
    $fill($p);
}
$r = $rename(3, 'Galerie');
check('subfolder renamed', !str_contains($r[2], '"status":"error"'), substr($r[2], 0, 200));
foreach (['offen/bilder/bild.jpg', 'offen/bilder/bild.w480.jpg', 'offen/bilder/tief/schrift.pdf'] as $p) {
    check("renamed: nothing at the old path: {$p}", $reachable($p) === '', $reachable($p));
}
check('renamed: the hand-placed files stay at the old path', $handIntact() === [], implode(',', $handIntact()));
$st = $guest()->go('/media/offen/bilder/hand.jpg');
check('renamed: a hand-placed file is still served', $st[0] === 200 && $st[2] === 'hand jpg', (string) $st[0]);
check('renamed: the document answers at the new path', $fill('offen/galerie/bild.jpg'), (string) $guest()->go('/media/offen/galerie/bild.jpg')[0]);
$rename(3, 'Bilder');
check('renamed back: nothing at the path of the moment', ($x = $reachable('offen/galerie/bild.jpg')) === '', $x);
check('...the empty directory of that path is gone', !is_dir($media . '/offen/galerie'));
check('...a hand-placed tree is whole', $handIntact() === []);

// A partition with hand-placed files.
foreach (['offen/foto.jpg', 'offen/bilder/bild.jpg'] as $p) {
    $fill($p);
}
$r = $rename(2, 'Geoeffnet', 'public');
check('partition renamed', !str_contains($r[2], '"status":"error"'), substr($r[2], 0, 200));
foreach (['offen/foto.jpg', 'offen/bilder/bild.jpg'] as $p) {
    check("partition renamed: nothing at the old path: {$p}", $reachable($p) === '', $reachable($p));
}
check('partition renamed: the old directory keeps its files and its guard', is_file($media . '/offen/.htaccess') && $handIntact() === []);
check('partition renamed: at the new path, with a guard of its own', $fill('geoeffnet/foto.jpg') && str_contains((string) @file_get_contents($media . '/geoeffnet/.htaccess'), 'ForceType None'));
$rename(2, 'Offen', 'public');
check('renamed back onto its old directory', is_file($media . '/offen/hand.js') && $reachable('geoeffnet/foto.jpg') === '');
$slugOf = static fn(int $id): string => (string) (array_column(json_decode((string) file_get_contents($site . '/data/documents/folders.json'), true), null, 'id')[$id]['slug'] ?? '');
check('...which a partition may not, since a hand-placed directory stands there: a new slug', $slugOf(2) === 'offen-2', $slugOf(2));

// A partition with nothing else in it goes with its guard.
check('a lone partition\'s copy', $fill('allein/einzig.jpg') && is_file($media . '/allein/.htaccess'));
$r = $rename(7, 'Solo', 'public');
check('renamed: the old directory is gone, guard and all', !is_dir($media . '/allein'), json_encode(@scandir($media . '/allein')));
check('...the document answers at the new path', $fill('solo/einzig.jpg'));

// ── (C) move, delete, trash, stale files ───────────────────────────────────
echo "(C) move, delete, trash, stale files\n";
$off = $slugOf(2);
check('a copy to move', $fill($off . '/reise/gezuegelt.jpg'));
$r = $admin->fetchPost($api . 'folder-move', ['id' => 5, 'entity_csrf' => $entity(5), 'folder_id' => 6], $token);
check('moved', !str_contains($r[2], '"status":"error"'), substr($r[2], 0, 200));
check('moved: nothing at the old path', ($x = $reachable($off . '/reise/gezuegelt.jpg')) === '', $x);
check('moved: the empty directory is gone', !is_dir($media . '/' . $off . '/reise'));
check('moved: it answers at the new one', $fill('ziel/reise/gezuegelt.jpg'));

// A trashed document's copy and a stale variant: both copies of documents of this folder.
$bilder = $off . '/bilder';
@mkdir($media . '/' . $bilder, 0777, true);
$place($bilder . '/bild.jpg', 'a copy');
$place($bilder . '/weg.jpg', 'a copy of a document in the trash');
$place($bilder . '/bild.alt.jpg', 'a variant the document no longer lists');
$place($bilder . '/bild.pdf', 'an earlier format');
$place($bilder . '/bild.html', 'not on the safe list and not its name: not provably a copy');
$place($bilder . '/fremd.jpg', 'hand-placed, no document of that name');
$gateMode(3, 'Bilder', 'protected');
check('trash: its copy goes', !is_file($media . '/' . $bilder . '/weg.jpg'));
check('stale variant: goes', !is_file($media . '/' . $bilder . '/bild.alt.jpg'));
check('earlier format: goes', !is_file($media . '/' . $bilder . '/bild.pdf'));
check('the current copy: goes', !is_file($media . '/' . $bilder . '/bild.jpg'));
check('another name: stays', is_file($media . '/' . $bilder . '/fremd.jpg') && is_file($media . '/' . $bilder . '/bild.html'));
$gateMode(3, 'Bilder', '');

// A delete: an empty folder owning a directory with a hand-placed file, and one without.
$leer = $off . '/leer';
$place($leer . '/hand.gif', 'hand gif');
$r = $admin->fetchPost($api . 'folder-remove', ['id' => 8, 'entity_csrf' => $entity(8)], $token);
check('deleted', !str_contains($r[2], '"status":"error"'), substr($r[2], 0, 200));
check('deleted: its hand-placed file stays', (string) @file_get_contents($media . '/' . $leer . '/hand.gif') === 'hand gif');

// ── (D) links ──────────────────────────────────────────────────────────────
echo "(D) links\n";
if ($hasLink) {
    // A partition directory that IS a link: no copy behind it, PHP answers, the invalidation stays out.
    $linked = $base . '/linked-target';
    mkdir($linked, 0777, true);
    file_put_contents($linked . '/fremd.jpg', 'outside file');
    file_put_contents($linked . '/dahinter.jpg', 'outside, named like the document');
    makeDirLink($linked, $media . '/verlinkt');
    $links[] = $media . '/verlinkt';
    // The built-in server would serve the file by path: ask for a document the link does not hold.
    unlink($linked . '/dahinter.jpg');
    $g = $guest()->go('/media/verlinkt/dahinter.jpg');
    check('behind a link: PHP answers, no copy is written', $g[0] === 200 && !is_file($linked . '/dahinter.jpg'), $g[0] . ' ' . json_encode(scandir($linked)));
    file_put_contents($linked . '/dahinter.jpg', 'outside, named like the document');
    $gateMode(9, 'Verlinkt', 'protected');
    check('the invalidation does not follow the link: its files stay', is_file($linked . '/fremd.jpg') && is_file($linked . '/dahinter.jpg'));
    check('...and it says so in the log', str_contains((string) file_get_contents($site . '/logs/php-error.log'), "'verlinkt' is a link, not followed"));
} else {
    echo "  (no directory link possible here: section skipped)\n";
}

// ── (E) a new partition and a hand-placed directory ────────────────────────
echo "(E) new partitions\n";
$r = $admin->fetchPost($api . 'folder-add', ['name' => 'Demo'], $token);
$rows = json_decode((string) file_get_contents($site . '/data/documents/folders.json'), true);
$new  = array_values(array_filter($rows, static fn(array $f): bool => ($f['name'] ?? '') === 'Demo'))[0] ?? [];
check('a new partition avoids a hand-placed directory', ($new['slug'] ?? '') === 'demo-2', json_encode($new));
check('...which stays as it was, without a guard', !is_file($media . '/demo/.htaccess') && $handIntact() === [], implode(',', $handIntact()));
$r = $admin->fetchPost($api . 'folder-add', ['name' => 'Frei'], $token);
$rows = json_decode((string) file_get_contents($site . '/data/documents/folders.json'), true);
$frei = array_values(array_filter($rows, static fn(array $f): bool => ($f['name'] ?? '') === 'Frei'))[0] ?? [];
check('a free name stays as it is', ($frei['slug'] ?? '') === 'frei', json_encode($frei));
$place('frei/keep.txt', 'in its own directory');
$r = $rename((int) $frei['id'], 'frei');
$rows = json_decode((string) file_get_contents($site . '/data/documents/folders.json'), true);
$frei = array_column($rows, null, 'id')[(int) $frei['id']];
check('a rename that keeps the slug keeps its own directory', ($frei['slug'] ?? '') === 'frei', json_encode($frei));
// A subfolder of the same name beside a directory: only partitions avoid the disk.
$r = $admin->fetchPost($api . 'folder-add?parent=6', ['name' => 'Demo'], $token);
$rows = json_decode((string) file_get_contents($site . '/data/documents/folders.json'), true);
$sub  = array_values(array_filter($rows, static fn(array $f): bool => ($f['name'] ?? '') === 'Demo' && ($f['parent_id'] ?? 0) === 6))[0] ?? [];
check('a subfolder is not asked about the top of the media tree', ($sub['slug'] ?? '') === 'demo', json_encode($sub));

$src = (string) file_get_contents($root . '/packages/module-dms/src/Services/DocumentService.php');
check('no whole-directory wipe is left', !str_contains($src, 'rrmdir'));

$log = (string) @file_get_contents($site . '/logs/php-error.log') . (string) @file_get_contents($base . '/server.log');
check('no PHP fatal or warning in the logs', !preg_match('/PHP (Fatal|Warning|Parse)/', $log), (string) (preg_match('/.*PHP (Fatal|Warning|Parse).*/', $log, $m) ? $m[0] : ''));

echo "\ndms-media-invalidate: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
