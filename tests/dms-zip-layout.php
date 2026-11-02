<?php

/**
 * Virtual ZIP harness (CLI, offline, no DI) — DMS-ZIP-001, phase Z1.
 *
 * `ZipLayout` answers any byte range of a ZIP from the original files, so nothing is stored
 * twice (`docs/03-development/dms-zip-download-bauplan.md`). What is load-bearing:
 *
 *   (A) every range composition equals the whole: random ranges, random pieces, a piece
 *       boundary inside a local header, inside data, inside the central directory and inside
 *       the end record, single bytes; a range outside the size is refused;
 *   (B) the bytes on the wire: store, no data descriptor, the CRC in the local header, flag
 *       bit 11, DOS time from the entry's mtime; a small archive holds no ZIP64 at all;
 *   (C) a reader agrees: libzip with CHECKCONS (names, sizes, CRC, method, time, content);
 *   (D) Windows `tar` (libarchive) lists and extracts it — skipped with a note when absent;
 *   (E) a file that ended early or vanished throws instead of answering fewer bytes; a file
 *       no range reaches is never opened;
 *   (F) `touched()`: a header clipped counts, the central directory counts for everyone;
 *   (G) ZIP64 arithmetic on fake sizes, no disk: exactly at and just below the limits;
 *   (H) ZIP64 for real: a sparse file above 4 GB and an entry behind the 4 GB mark, read by
 *       libzip and tar — skipped with a note where a sparse file cannot be made;
 *   (I) more than 65535 entries (layout only): ZIP64 end record, read by libzip;
 *   (J) names: hostile names cannot leave the target directory (extracted for real by libzip
 *       and tar), control characters, reserved names, duplicates, umlauts, length, bad UTF-8;
 *       a layout rebuilt from its own names has the same bytes (the service rebuilds it).
 *
 * Run: php tests/dms-zip-layout.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "dms-zip-layout: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Dms\Zip\ZipLayout;

$pass = 0;
$fail = 0;
$notes = [];

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function note(string $text): void
{
    global $notes;
    $notes[] = $text;
    echo "  NOTE {$text}\n";
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** The bytes of one range as a string. */
function piece(ZipLayout $layout, int $start, int $end): string
{
    $out = fopen('php://memory', 'w+b');
    $layout->emit($start, $end, $out);
    rewind($out);
    $bytes = (string) stream_get_contents($out);
    fclose($out);
    return $bytes;
}

function whole(ZipLayout $layout): string
{
    return piece($layout, 0, $layout->size() - 1);
}

function crcOf(string $path): int
{
    return (int) hexdec((string) hash_file('crc32b', $path));
}

function entryOf(string $name, string $path, int $mtime = 1_700_000_000): array
{
    return ['name' => $name, 'path' => $path, 'size' => (int) filesize($path), 'crc' => crcOf($path), 'mtime' => $mtime];
}

/** Deterministic bytes (same run, same content). */
function bytesOf(int $length, string $seed): string
{
    $out = '';
    for ($i = 0; strlen($out) < $length; $i++) {
        $out .= hash('sha256', $seed . $i, true);
    }
    return substr($out, 0, $length);
}

/** The first signature `$sig` at or after `$from`, or -1. */
function findSig(string $bytes, string $sig, int $from = 0): int
{
    $pos = strpos($bytes, $sig, $from);
    return $pos === false ? -1 : $pos;
}

/** A tar that reads ZIP (libarchive): Windows' own, or a bsdtar on the PATH. Null = none. */
function zipTar(): ?string
{
    $candidates = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe';
    }
    $candidates[] = 'bsdtar';
    $candidates[] = 'tar';
    foreach ($candidates as $tar) {
        $h = @proc_open([$tar, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($h)) {
            continue;
        }
        $version = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($h);
        if (stripos($version, 'libarchive') !== false || stripos($version, 'bsdtar') !== false) {
            return $tar;
        }
    }
    return null;
}

/** @return array{0: int, 1: string} exit code, stdout */
function run(array $command): array
{
    $h = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($h)) {
        return [127, ''];
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($h), $out];
}

/**
 * Prepare a file for holes. Windows wants a flag first (`fsutil`), without it a seek past the
 * end writes real zeros; Linux and macOS file systems make holes by themselves.
 */
function makeSparse(string $path): bool
{
    if (file_put_contents($path, '') === false) {
        return false;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return true;
    }
    [$code] = run(['fsutil', 'sparse', 'setflag', str_replace('/', '\\', $path)]);
    return $code === 0;
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-ziplayout-' . getmypid();
rrmdir($root);
mkdir($root . '/in', 0777, true);
register_shutdown_function(static fn () => rrmdir($root));
mt_srand(77);

// ── fixtures: real files of every kind ───────────────────────────────────────────
$mtime = mktime(14, 30, 42, 3, 15, 2024);
file_put_contents($root . '/in/kurz.txt', 'Max Muster, example.ch');
file_put_contents($root . '/in/leer.bin', '');
$umlautBytes = bytesOf(70_000, 'umlaut');
file_put_contents($root . '/in/umlaut.bin', $umlautBytes);
$bigBytes = bytesOf(3 * 1024 * 1024 + 17, 'big');
file_put_contents($root . '/in/gross.bin', $bigBytes);
file_put_contents($root . '/in/letzt.txt', 'am Schluss');

$entries = [
    entryOf('kurz.txt', $root . '/in/kurz.txt', $mtime),
    entryOf('leer.bin', $root . '/in/leer.bin', $mtime),
    entryOf('Übersicht März.bin', $root . '/in/umlaut.bin', $mtime),
    entryOf('gross.bin', $root . '/in/gross.bin', $mtime),
    entryOf('letzt.txt', $root . '/in/letzt.txt', $mtime),
];
$layout = new ZipLayout($entries);
$size   = $layout->size();
$bytes  = whole($layout);

echo "(A) every range composition equals the whole\n";
check('size() is the number of bytes emitted', strlen($bytes) === $size, strlen($bytes) . ' vs ' . $size);
$bad = 0;
for ($i = 0; $i < 400; $i++) {
    $a = mt_rand(0, $size - 1);
    $b = min($size - 1, $a + mt_rand(0, $i % 2 === 0 ? 200 : 2_000_000));
    if (piece($layout, $a, $b) !== substr($bytes, $a, $b - $a + 1)) {
        $bad++;
    }
}
check('400 random ranges equal the whole at their place', $bad === 0, "{$bad} differ");
$bad = 0;
for ($round = 0; $round < 20; $round++) {
    $joined = '';
    for ($pos = 0; $pos < $size; $pos = $end + 1) {
        $end = min($size - 1, $pos + mt_rand(0, 900_000));
        $joined .= piece($layout, $pos, $end);
    }
    if ($joined !== $bytes) {
        $bad++;
    }
}
check('20 random partitions join to the whole', $bad === 0, "{$bad} differ");

$centralAt = findSig($bytes, "PK\x01\x02");
$endAt     = findSig($bytes, "PK\x05\x06");
$secondAt  = findSig($bytes, "PK\x03\x04", 4);
$cuts = [
    'inside a local header, its fixed part' => 7,
    'inside a local header, its name'       => 32,
    'at the end of a local header'          => 30 + strlen('kurz.txt'),
    'inside data'                           => 30 + strlen('kurz.txt') + 5,
    'on a data / next header boundary'      => $secondAt,
    'inside the data of the large file'     => $size - 2000,
    'inside the central directory'          => $centralAt + 11,
    'on the central directory / end border' => $endAt,
    'inside the end record'                 => $endAt + 9,
];
foreach ($cuts as $label => $cut) {
    check("a piece boundary {$label}", piece($layout, 0, $cut - 1) . piece($layout, $cut, $size - 1) === $bytes);
}
$single = '';
for ($i = 0; $i < 120; $i++) {
    $single .= piece($layout, $i, $i);
}
check('single bytes across a header and an empty entry', $single === substr($bytes, 0, 120));
$tail = '';
for ($i = $size - 200; $i < $size; $i++) {
    $tail .= piece($layout, $i, $i);
}
check('single bytes across the central directory and the end record', $tail === substr($bytes, -200));
$refused = 0;
foreach ([[-1, 5], [0, $size], [5, 4], [$size, $size]] as [$a, $b]) {
    try {
        piece($layout, $a, $b);
    } catch (InvalidArgumentException) {
        $refused++;
    }
}
check('a range outside the size is refused', $refused === 4, (string) $refused);

echo "(B) the bytes on the wire\n";
$hdr = unpack('Vsig/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', substr($bytes, 0, 30));
check('local header: signature, store, version 2.0', $hdr['sig'] === 0x04034B50 && $hdr['method'] === 0 && $hdr['version'] === 20, json_encode($hdr));
check('flag bit 11 set, bit 3 (data descriptor) not', ($hdr['flags'] & 0x0800) !== 0 && ($hdr['flags'] & 0x0008) === 0, (string) $hdr['flags']);
check('the CRC and sizes stand in the local header', $hdr['crc'] === crcOf($root . '/in/kurz.txt') && $hdr['csize'] === 22 && $hdr['usize'] === 22, json_encode($hdr));
check('no extra field in a plain entry', $hdr['xlen'] === 0);
check('DOS time from the mtime (2-second steps)', $hdr['time'] === ((14 << 11) | (30 << 5) | (42 >> 1)), (string) $hdr['time']);
check('DOS date from the mtime', $hdr['date'] === (((2024 - 1980) << 9) | (3 << 5) | 15), (string) $hdr['date']);
check('no data descriptor signature anywhere in a plain archive', findSig(substr($bytes, 0, 200), "PK\x07\x08") === -1);
check('a small archive holds no ZIP64: no end record, no locator', findSig($bytes, "PK\x06\x06") === -1 && findSig($bytes, "PK\x06\x07") === -1);
$early = (new ZipLayout([['name' => 'a', 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => mktime(0, 0, 0, 6, 1, 1975)]]));
$h = unpack('vtime/vdate', substr(piece($early, 0, 29), 10, 4));
check('a date before 1980 becomes 1980-01-01', $h['date'] === ((0 << 9) | (1 << 5) | 1) && $h['time'] === 0, json_encode($h));
$late = (new ZipLayout([['name' => 'a', 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => mktime(0, 0, 0, 6, 1, 2120)]]));
$h = unpack('vtime/vdate', substr(piece($late, 0, 29), 10, 4));
check('a date after 2107 becomes the last one', $h['date'] === ((127 << 9) | (12 << 5) | 31), json_encode($h));

echo "(C) libzip reads it (CHECKCONS)\n";
$file = $root . '/plain.zip';
file_put_contents($file, $bytes);
$zip = new ZipArchive();
$opened = $zip->open($file, ZipArchive::CHECKCONS);
check('open with CHECKCONS', $opened === true, var_export($opened, true));
if ($opened === true) {
    check('five entries', $zip->numFiles === 5, (string) $zip->numFiles);
    foreach ($entries as $i => $e) {
        $s = $zip->statIndex($i);
        check("entry {$i} {$e['name']}: name, size, CRC, store",
            $s['name'] === $e['name'] && $s['size'] === $e['size'] && $s['comp_size'] === $e['size'] && ($s['crc'] & 0xFFFFFFFF) === $e['crc'] && $s['comp_method'] === 0, json_encode($s));
        // libzip reads a DOS time in the OS zone, this script writes it in PHP's: compare within whole zones.
        check("entry {$i}: the time is the mtime, rounded down to 2 s (modulo the zone libzip reads it in)", (($s['mtime'] - ($e['mtime'] - ($e['mtime'] % 2))) % 900) === 0 && abs($s['mtime'] - $e['mtime']) < 50_400, (string) $s['mtime']);
    }
    check('content of the small file', $zip->getFromName('kurz.txt') === 'Max Muster, example.ch');
    check('content of the empty file', $zip->getFromName('leer.bin') === '');
    check('content of the umlaut name', $zip->getFromName('Übersicht März.bin') === $umlautBytes);
    check('content of the large file', $zip->getFromName('gross.bin') === $bigBytes);
    $zip->close();
}

echo "(D) Windows tar (libarchive)\n";
$tar = zipTar();
if ($tar === null) {
    note('no libarchive tar found — (D) skipped');
} else {
    [$code, $list] = run([$tar, '-tf', $file]);
    check('tar lists every entry', $code === 0 && substr_count($list, "\n") === 5 && str_contains($list, 'gross.bin') && str_contains($list, 'letzt.txt'), "{$code}: {$list}");
    mkdir($root . '/tar', 0777, true);
    [$code] = run([$tar, '-xf', $file, '-C', $root . '/tar']);
    check('tar extracts them, byte-identical (large file, empty file)',
        $code === 0 && @file_get_contents($root . '/tar/gross.bin') === $bigBytes && is_file($root . '/tar/leer.bin') && filesize($root . '/tar/leer.bin') === 0, (string) $code);
    check('… and the umlaut name arrives as UTF-8', @file_get_contents($root . '/tar/Übersicht März.bin') === $umlautBytes, implode(', ', scandir($root . '/tar') ?: []));
    rrmdir($root . '/tar');
}

echo "(E) a file that does not hold what the layout promises\n";
$short = $root . '/in/kurzgeworden.bin';
file_put_contents($short, str_repeat('x', 100));
$shrunk = new ZipLayout([['name' => 'a.bin', 'path' => $short, 'size' => 500, 'crc' => 0, 'mtime' => $mtime]]);
$err = null;
try { piece($shrunk, 0, $shrunk->size() - 1); } catch (RuntimeException $e) { $err = $e; }
check('a file that ends early throws, it does not answer fewer bytes', $err !== null && str_contains($err->getMessage(), 'ended early'), $err ? $err->getMessage() : 'nothing thrown');
$gone = new ZipLayout([['name' => 'a.bin', 'path' => $root . '/in/fehlt.bin', 'size' => 10, 'crc' => 0, 'mtime' => $mtime]]);
$err = null;
try { piece($gone, 30, 35); } catch (RuntimeException $e) { $err = $e; }
check('a vanished file throws', $err !== null && str_contains($err->getMessage(), 'cannot open'), $err ? $err->getMessage() : 'nothing thrown');
$noPath = new ZipLayout([
    ['name' => 'unberuehrt.bin', 'path' => '', 'size' => 10, 'crc' => 0, 'mtime' => $mtime],
    ['name' => 'b.txt', 'path' => $root . '/in/kurz.txt', 'size' => 22, 'crc' => crcOf($root . '/in/kurz.txt'), 'mtime' => $mtime],
]);
$start = 30 + strlen('unberuehrt.bin') + 10;
check('a file no range reaches is never opened', str_contains(piece($noPath, $start, $start + 30 + 5 + 22 - 1), 'Max Muster, example.ch'));
check('… a range that reaches it throws', (static function () use ($noPath): bool {
    try { piece($noPath, 30 + strlen('unberuehrt.bin'), 30 + strlen('unberuehrt.bin')); return false; } catch (RuntimeException) { return true; }
})());

echo "(F) touched()\n";
// The offsets from the entries, not from a signature scan: random data may hold one by chance.
$starts = [];
$pos = 0;
foreach ($layout->names() as $i => $name) {
    $starts[$i] = $pos;
    $pos += 30 + strlen($name) + $entries[$i]['size'];
}
check('the first byte touches the first entry', $layout->touched(0, 0) === [0]);
check('a range that clips only the next header touches it too (its name and CRC are in there)',
    $layout->touched($starts[2] - 1, $starts[2] + 3) === [1, 2], json_encode($layout->touched($starts[2] - 1, $starts[2] + 3)));
check('an empty entry between two is touched when the range crosses it', in_array(1, $layout->touched($starts[0], $starts[2]), true));
check('inside one file only that file', $layout->touched($starts[3] + 1000, $starts[3] + 2000) === [3]);
check('a range across several', $layout->touched($starts[2] + 40, $starts[4]) === [2, 3, 4]);
check('the central directory carries every name: a range that reaches it touches all', $layout->touched($centralAt, $centralAt) === [0, 1, 2, 3, 4]);
check('… as the end record does', $layout->touched($size - 1, $size - 1) === [0, 1, 2, 3, 4]);
check('the last byte before the central directory touches the last entry only', $layout->touched($centralAt - 1, $centralAt - 1) === [4]);

echo "(G) ZIP64 arithmetic at the limits (no disk)\n";
$limit = 0xFFFFFFFF;
$fake = static fn (array $sizes): ZipLayout => new ZipLayout(array_map(
    static fn (int $i, int $s): array => ['name' => "f{$i}", 'path' => '', 'size' => $s, 'crc' => 0, 'mtime' => $mtime],
    array_keys($sizes),
    $sizes
));
$below = $fake([$limit - 1]);
$head  = unpack('vxlen', substr(piece($below, 0, 29), 28, 2));
check('size just below the limit: no extra field in the local header', $head['xlen'] === 0);
$at   = $fake([$limit]);
$raw  = piece($at, 0, 30 + 2 + 20 - 1);
$h    = unpack('vversion', substr($raw, 4, 2)) + unpack('Vcsize/Vusize/vnlen/vxlen', substr($raw, 18, 12));
$ex   = unpack('vtag/vlen/Pusize/Pcsize', substr($raw, 32));
check('size exactly at the limit: sentinels, version 4.5, extra with both sizes',
    $h['version'] === 45 && $h['csize'] === $limit && $h['usize'] === $limit && $h['xlen'] === 20
    && $ex['tag'] === 1 && $ex['len'] === 16 && $ex['usize'] === $limit && $ex['csize'] === $limit, json_encode($h + $ex));
$sizeAt = $at->size();
$cdAt   = 30 + 2 + 20 + $limit;
$tailBytes = piece($at, $cdAt, $sizeAt - 1);
$central = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Veattr/Voffset', $tailBytes);
check('central entry: sentinels, extra with both sizes, the offset as it is',
    $central['sig'] === 0x02014B50 && $central['usize'] === $limit && $central['xlen'] === 20 && $central['offset'] === 0, json_encode($central));
check('the central directory stands behind the 4 GB mark → ZIP64 end record and locator', str_contains($tailBytes, "PK\x06\x06") && str_contains($tailBytes, "PK\x06\x07"));
$end = unpack('Vsig/vdisk/vcddisk/vcount/vtotal/Vcdsize/Vcdoffset/vclen', substr($tailBytes, -22));
check('plain end record: the offset is the sentinel, the count real', $end['cdoffset'] === $limit && $end['total'] === 1, json_encode($end));
$end64 = unpack('Vsig/Psize/vmade/vneed/Vdisk/Vcddisk/Pcount/Ptotal/Pcdsize/Pcdoffset', substr($tailBytes, -22 - 20 - 56, 56));
check('ZIP64 end record: 56 bytes, offset and size true', $end64['sig'] === 0x06064B50 && $end64['size'] === 44 && $end64['cdoffset'] === $cdAt && $end64['total'] === 1, json_encode($end64));
$loc = unpack('Vsig/Vdisk/Poffset/Vdisks', substr($tailBytes, -22 - 20, 20));
check('locator names the ZIP64 end record', $loc['sig'] === 0x07064B50 && $loc['offset'] === $sizeAt - 22 - 20 - 56 && $loc['disks'] === 1, json_encode($loc));

$behind = $fake([$limit + 100, 5]);
$h2At   = 30 + 2 + 20 + $limit + 100;
$h2     = unpack('vversion', substr(piece($behind, $h2At, $h2At + 29), 4, 2)) + unpack('vxlen', substr(piece($behind, $h2At, $h2At + 29), 28, 2));
check('an entry behind the mark: version 4.5, no extra in its local header', $h2['version'] === 45 && $h2['xlen'] === 0, json_encode($h2));
$cdBehind = $h2At + 30 + 2 + 5;
$tailB    = piece($behind, $cdBehind, $behind->size() - 1);
$c0len    = 46 + 2 + 20; // first central entry: name f0, extra with both sizes
$c1       = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Veattr/Voffset', substr($tailB, $c0len, 46));
$c1ex     = unpack('vtag/vlen/Poffset', substr($tailB, $c0len + 46 + 2, 12));
check('central entry behind the mark: sizes plain, offset sentinel, extra with the offset alone',
    $c1['usize'] === 5 && $c1['offset'] === $limit && $c1['xlen'] === 12 && $c1ex['tag'] === 1 && $c1ex['len'] === 8 && $c1ex['offset'] === $h2At, json_encode($c1 + $c1ex));
check('nothing about an entry below the limits changes', !str_contains(whole($fake([0])), "PK\x06\x06"));

$count = static fn (int $n): ZipLayout => new ZipLayout(array_map(
    static fn (int $i): array => ['name' => 'f' . $i, 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => $mtime],
    range(0, $n - 1)
));
check('65534 entries stay plain', !str_contains(piece($count(65534), $count(65534)->size() - 100, $count(65534)->size() - 1), "PK\x06\x06"));
$c65535 = $count(65535);
$endC   = unpack('Vsig/vdisk/vcddisk/vcount/vtotal', substr(piece($c65535, $c65535->size() - 22, $c65535->size() - 1), 0, 12));
check('65535 entries: the sentinel in the plain end record, ZIP64 end record in front', $endC['total'] === 0xFFFF
    && str_contains(piece($c65535, $c65535->size() - 22 - 20 - 56, $c65535->size() - 1), "PK\x06\x06"), json_encode($endC));

echo "(H) ZIP64 for real: a sparse file above 4 GB\n";
$sparse = $root . '/in/sparse.bin';
$sparseZip = $root . '/sparse.zip';
if (!makeSparse($sparse) || !makeSparse($sparseZip)) {
    note('cannot make a sparse file here (fsutil refused) — (H) skipped');
} else {
    $marker     = 'Ende der grossen Datei, Max Muster';
    $sparseSize = 4 * 1024 * 1024 * 1024 + 1_000_000 + strlen($marker);
    $h = fopen($sparse, 'r+b');
    $ok = $h !== false && fseek($h, $sparseSize - strlen($marker)) === 0 && fwrite($h, $marker) === strlen($marker);
    if ($h !== false) {
        fclose($h);
    }
    clearstatcache(true, $sparse);
    if (!$ok || filesize($sparse) !== $sparseSize) {
        note('the sparse file did not reach its size — (H) skipped');
    } else {
        $t0 = microtime(true);
        $sparseCrc = crcOf($sparse);
        echo sprintf("  ...  CRC of %.2f GB in %.1f s (a hole, this says nothing about a real disk)\n", $sparseSize / 1e9, microtime(true) - $t0);
        $bigEntries = [
            entryOf('vorher.txt', $root . '/in/kurz.txt', $mtime),
            ['name' => 'gross über 4 GB.bin', 'path' => $sparse, 'size' => $sparseSize, 'crc' => $sparseCrc, 'mtime' => $mtime],
            entryOf('dahinter.txt', $root . '/in/letzt.txt', $mtime),
            entryOf('leer dahinter.bin', $root . '/in/leer.bin', $mtime),
        ];
        $big = new ZipLayout($bigEntries);
        $total = $big->size();
        check('the archive is above 4 GB', $total > 4 * 1024 * 1024 * 1024, (string) $total);

        // Offsets by hand: entry 1's data starts behind two headers.
        $firstName = 'vorher.txt';
        $bigName   = 'gross über 4 GB.bin';
        $dataAt    = 30 + strlen($firstName) + 22 + 30 + strlen($bigName) + 20;
        $tailFrom  = $dataAt + $sparseSize - 1000; // the marker and everything behind it

        // Write everything but the hole: the sparse output has zeros there, as has the source.
        $w = fopen($sparseZip, 'r+b');
        $big->emit(0, $dataAt - 1, $w);
        fseek($w, $tailFrom);
        $big->emit($tailFrom, $total - 1, $w);
        fclose($w);
        clearstatcache(true, $sparseZip);
        check('assembled by ranges around the hole, the size is the total', filesize($sparseZip) === $total, (string) filesize($sparseZip));

        $inHole = 0;
        for ($i = 0; $i < 20; $i++) {
            $a = mt_rand($dataAt, $tailFrom - 1000);
            if (piece($big, $a, $a + 999) !== str_repeat("\0", 1000)) {
                $inHole++;
            }
        }
        check('20 ranges inside the hole are its zeros', $inHole === 0, (string) $inHole);
        $across = piece($big, $dataAt - 10, $dataAt + 10);
        check('a range across the header into the data', substr($across, 10) === str_repeat("\0", 11) && strlen($across) === 21);
        check('a range across the 4 GB mark', strlen(piece($big, 0xFFFFFFFF - 5, 0xFFFFFFFF + 5)) === 11);

        $zip = new ZipArchive();
        $opened = $zip->open($sparseZip, ZipArchive::CHECKCONS);
        check('libzip opens it with CHECKCONS', $opened === true, var_export($opened, true));
        if ($opened === true) {
            check('four entries', $zip->numFiles === 4, (string) $zip->numFiles);
            $s = $zip->statName($bigName);
            check('the large entry: true size, CRC', $s !== false && $s['size'] === $sparseSize && ($s['crc'] & 0xFFFFFFFF) === $sparseCrc, json_encode($s));
            $s = $zip->statName('dahinter.txt');
            check('the entry behind the 4 GB mark: size, CRC', $s !== false && $s['size'] === 10 && ($s['crc'] & 0xFFFFFFFF) === crcOf($root . '/in/letzt.txt'), json_encode($s));
            check('its content, read through libzip', $zip->getFromName('dahinter.txt') === 'am Schluss');
            check('the empty entry behind it', $zip->getFromName('leer dahinter.bin') === '');
            $t0 = microtime(true);
            $in = $zip->getStream($bigName);
            $ctx = hash_init('crc32b');
            hash_update_stream($ctx, $in);
            fclose($in);
            check('the large entry re-read through libzip has the right CRC', (int) hexdec(hash_final($ctx)) === $sparseCrc);
            echo sprintf("  ...  read back in %.1f s\n", microtime(true) - $t0);
            $zip->close();
        }
        if ($tar !== null) {
            [$code, $list] = run([$tar, '-tf', $sparseZip]);
            check('tar lists it', $code === 0 && str_contains($list, 'dahinter.txt') && substr_count($list, "\n") === 4, "{$code}: {$list}");
            mkdir($root . '/tar', 0777, true);
            [$code] = run([$tar, '-xf', $sparseZip, '-C', $root . '/tar', 'dahinter.txt']);
            check('tar extracts the entry behind the 4 GB mark', $code === 0 && @file_get_contents($root . '/tar/dahinter.txt') === 'am Schluss', (string) $code);
            rrmdir($root . '/tar');
        }
    }
    @unlink($sparse);
    @unlink($sparseZip);
}

echo "(I) more than 65535 entries (layout only)\n";
$many = [];
for ($i = 0; $i < 70_000; $i++) {
    $many[] = ['name' => sprintf('f%05d.txt', $i), 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => $mtime];
}
$many[] = entryOf('mit inhalt.txt', $root . '/in/kurz.txt', $mtime);
$manyLayout = new ZipLayout($many);
$manyZip = $root . '/many.zip';
$w = fopen($manyZip, 'wb');
$manyLayout->emit(0, $manyLayout->size() - 1, $w);
fclose($w);
check('assembled size is the arithmetic', filesize($manyZip) === $manyLayout->size());
$zip = new ZipArchive();
$opened = $zip->open($manyZip, ZipArchive::CHECKCONS);
check('libzip opens it with CHECKCONS', $opened === true, var_export($opened, true));
if ($opened === true) {
    check('70001 entries', $zip->numFiles === 70_001, (string) $zip->numFiles);
    check('entry 69999 has its name', $zip->getNameIndex(69_999) === 'f69999.txt');
    check('the last entry reads', $zip->getFromName('mit inhalt.txt') === 'Max Muster, example.ch');
    $zip->close();
}
$tailMany = piece($manyLayout, $manyLayout->size() - 98, $manyLayout->size() - 1);
check('the ZIP64 end record and locator are there', str_starts_with($tailMany, "PK\x06\x06") && substr($tailMany, 56, 4) === "PK\x06\x07");
@unlink($manyZip);

echo "(J) names\n";
$clean = static fn (string $n): string => ZipLayout::cleanName($n);
foreach ([
    ['../../x', 'x'],
    ['a/b', 'b'],
    ['C:\\x', 'x'],
    ['..\\..\\Windows\\win.ini', 'win.ini'],
    ['/etc/passwd', 'passwd'],
    ['a/b/', 'b'],
    ["ctl\x07\x1F\x7F.txt", 'ctl.txt'],
    ["zeile\r\nzwei.txt", 'zeilezwei.txt'],
    ["a\u{202E}gpj.exe", 'agpj.exe'],
    ['a:b.txt', 'a_b.txt'],
    ['wer?*"<>|.txt', 'wer______.txt'],
    ['..', ''],
    ['.', ''],
    ['...', ''],
    ['a/..', ''],
    ['  ', ''],
    ['', ''],
    ['Bericht..pdf', 'Bericht.pdf'],
    ['..versteckt', '.versteckt'],
    ['.htaccess', '.htaccess'],
    ['ende.', 'ende'],
    ['ende. . ', 'ende'],
    ['CON', '_CON'],
    ['nul.txt', '_nul.txt'],
    ['Com1.log', '_Com1.log'],
    ['console.txt', 'console.txt'],
    ['CONIN$', '_CONIN$'],
    ['conout$.txt', '_conout$.txt'],
    ['COM0', '_COM0'],
    ['lpt0.log', '_lpt0.log'],
    ['COM¹', '_COM¹'],
    ['LPT².txt', '_LPT².txt'],
    ['lpt³', '_lpt³'],
    ['NUL .txt', '_NUL .txt'],
    ['con  .log', '_con  .log'],
    ['COM10', 'COM10'],
    ['com', 'com'],
    ['CONINX', 'CONINX'],
    ['Übersicht März.pdf', 'Übersicht März.pdf'],
] as [$in, $want]) {
    check('clean ' . json_encode($in, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($want, JSON_UNESCAPED_UNICODE), $clean($in) === $want, json_encode($clean($in), JSON_UNESCAPED_UNICODE));
}
$scrubbed = $clean("\xFF\xFEbad.txt");
check('bad UTF-8 is repaired, the result is valid', preg_match('//u', $scrubbed) === 1 && str_ends_with($scrubbed, 'bad.txt'), $scrubbed);
$long = $clean(str_repeat('ä', 200) . '.pdf');
check('a long name is cut on a character boundary, the extension kept', strlen($long) <= 240 && str_ends_with($long, '.pdf') && preg_match('//u', $long) === 1, (string) strlen($long));

$hostile = [
    '../../x', 'a/b', 'C:\\x', '..\\..\\evil.txt', "ctl\x07.txt", '/abs/olut.txt', 'x/../../y.txt', '..', '', ' ', 'CON', 'a:b.txt',
    'doppelt.pdf', 'doppelt.pdf', 'DOPPELT.PDF', 'doppelt (2).pdf', 'doppelt.pdf',
    'Übersicht März.pdf', 'übersicht märz.PDF', '.htaccess', '.htaccess', 'ohne', 'ohne',
    str_repeat('n', 300) . '.txt', str_repeat('n', 300) . '.txt',
];
$hostileEntries = array_map(static fn (string $n): array => ['name' => $n, 'path' => $root . '/in/kurz.txt', 'size' => 22, 'crc' => crcOf($root . '/in/kurz.txt'), 'mtime' => $mtime], $hostile);
$h = new ZipLayout($hostileEntries);
$names = $h->names();
check('as many names as entries', count($names) === count($hostile));
check('no separator, no `..`, no colon, no control character survives',
    array_filter($names, static fn (string $n): bool => preg_match('~[/\\\\:\x00-\x1F]|\.\.~', $n) === 1) === [], json_encode($names, JSON_UNESCAPED_UNICODE));
check('case-insensitively unique', count(array_unique(array_map('mb_strtolower', $names))) === count($names));
check('at most 240 bytes each, the suffix included', max(array_map('strlen', $names)) <= 240, (string) max(array_map('strlen', $names)));
check('duplicates: name (2).ext, the case kept, a taken suffix skipped',
    $names[12] === 'doppelt.pdf' && $names[13] === 'doppelt (2).pdf' && $names[14] === 'DOPPELT (3).PDF'
    && $names[15] === 'doppelt (2) (2).pdf' && $names[16] === 'doppelt (4).pdf', json_encode(array_slice($names, 12, 5), JSON_UNESCAPED_UNICODE));
check('an umlaut name stays, its case variant counts as a duplicate', $names[17] === 'Übersicht März.pdf' && $names[18] === 'übersicht märz (2).PDF', json_encode(array_slice($names, 17, 2), JSON_UNESCAPED_UNICODE));
check('a dot-file and a name without extension get a suffix too', $names[20] === '.htaccess (2)' && $names[22] === 'ohne (2)', json_encode([$names[20], $names[22]]));
check('nothing left → a fallback name, unique', $names[7] === 'datei' && $names[8] === 'datei (2)' && $names[9] === 'datei (3)', json_encode(array_slice($names, 7, 3)));
$again = new ZipLayout(array_map(static fn (array $e, string $n): array => ['name' => $n] + $e, $hostileEntries, $names));
check('idempotent: rebuilt from its own names, the same names and the same bytes', $again->names() === $names && whole($again) === whole($h));

$outer = $root . '/slip/a/b/c';
mkdir($outer, 0777, true);
$slipZip = $root . '/slip.zip';
file_put_contents($slipZip, whole($h));
$zip = new ZipArchive();
$opened = $zip->open($slipZip, ZipArchive::CHECKCONS);
check('libzip opens the hostile archive with CHECKCONS', $opened === true, var_export($opened, true));
if ($opened === true) {
    check('libzip extracts it', $zip->extractTo($outer));
    $zip->close();
    $inside = array_values(array_diff(scandir($outer) ?: [], ['.', '..']));
    check('every file landed in the target directory, flat', count($inside) === count($names) && array_filter($inside, static fn (string $n): bool => !is_file($outer . '/' . $n)) === [], json_encode($inside, JSON_UNESCAPED_UNICODE));
    check('nothing left the target directory (libzip)', array_values(array_diff(scandir($root . '/slip/a/b') ?: [], ['.', '..'])) === ['c'] && !file_exists($root . '/slip/x') && !file_exists($root . '/x'));
}
if ($tar !== null) {
    rrmdir($outer);
    mkdir($outer, 0777, true);
    [$code] = run([$tar, '-xf', $slipZip, '-C', $outer]);
    $inside = array_values(array_diff(scandir($outer) ?: [], ['.', '..']));
    check('tar extracts the same set', $code === 0 && count($inside) === count($names), "{$code}: " . count($inside));
    check('nothing left the target directory (tar)', array_values(array_diff(scandir($root . '/slip/a/b') ?: [], ['.', '..'])) === ['c'] && !file_exists($root . '/slip/x') && !file_exists($root . '/x'));
}

if (class_exists('Normalizer')) {
    $nfc = "\u{00FC}ber.txt";
    $nfd = "u\u{0308}ber.txt";
    check('NFC: a name arrives composed', $clean($nfd) === $nfc, bin2hex($clean($nfd)));
    $both = (new ZipLayout([
        ['name' => $nfc, 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => $mtime],
        ['name' => $nfd, 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => $mtime],
    ]))->names();
    check('NFC: ü composed and decomposed are one name to a file system that normalizes', $both === [$nfc, "\u{00FC}ber (2).txt"], json_encode($both, JSON_UNESCAPED_UNICODE));
} else {
    note('no Normalizer in this PHP: names stay as they arrive');
}

$longExt = ['a.' . str_repeat('x', 235), 'a.' . str_repeat('x', 235), 'b.' . str_repeat('y', 400), 'b.' . str_repeat('y', 400), 'kurz.' . str_repeat('z', 16), 'kurz.' . str_repeat('z', 16), 'kurz.' . str_repeat('z', 17), 'kurz.' . str_repeat('z', 17)];
$longLayout = new ZipLayout(array_map(static fn (string $n): array => ['name' => $n, 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => $mtime], $longExt));
$longNames = $longLayout->names();
check('a very long extension: unique, at most 240 bytes', count(array_unique(array_map('mb_strtolower', $longNames))) === count($longNames) && max(array_map('strlen', $longNames)) <= 240, json_encode(array_map('strlen', $longNames)));
check('… the stem is not lost', str_starts_with($longNames[1], 'a.') && str_starts_with($longNames[3], 'b.'), json_encode(array_map(static fn ($n) => substr($n, 0, 8), $longNames)));
check('… a normal extension stays behind the suffix, up to 16 bytes', str_ends_with($longNames[5], ' (2).' . str_repeat('z', 16)), $longNames[5]);
$longAgain = new ZipLayout(array_map(static fn (string $n): array => ['name' => $n, 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => $mtime], $longNames));
check('… idempotent: rebuilt from its own names, the same names', $longAgain->names() === $longNames, json_encode(array_map('strlen', $longAgain->names())));

$rawHeader = piece(new ZipLayout([['name' => 'Übersicht.txt', 'path' => '', 'size' => 0, 'crc' => 0, 'mtime' => $mtime]]), 30, 30 + strlen('Übersicht.txt') - 1);
check('the umlaut name stands as UTF-8 in the header', $rawHeader === 'Übersicht.txt');

echo "(K) edge archives\n";
$none = new ZipLayout([]);
check('no entries: the 22 bytes of an end record', $none->size() === 22 && whole($none) === "PK\x05\x06" . str_repeat("\0", 18));
check('… touches nothing', $none->touched(0, 21) === []);
file_put_contents($root . '/none.zip', whole($none));
$zip = new ZipArchive();
$opened = $zip->open($root . '/none.zip');
check('… libzip opens it, no entries', $opened === true && $zip->numFiles === 0, var_export($opened, true));
if ($opened === true) {
    $zip->close();
}
$onlyEmpty = new ZipLayout([entryOf('leer.txt', $root . '/in/leer.bin', $mtime)]);
file_put_contents($root . '/empty.zip', whole($onlyEmpty));
$zip = new ZipArchive();
$opened = $zip->open($root . '/empty.zip', ZipArchive::CHECKCONS);
check('only an empty file: libzip reads it, size 0, CRC 0', $opened === true && $zip->statIndex(0)['size'] === 0 && ($zip->statIndex(0)['crc'] & 0xFFFFFFFF) === 0, var_export($opened, true));
if ($opened === true) {
    $zip->close();
}

echo "\n{$pass} ok, {$fail} failed" . ($notes !== [] ? ', ' . count($notes) . ' skipped with a note' : '') . "\n";
foreach ($notes as $text) {
    echo "NOTE: {$text}\n";
}
exit($fail === 0 ? 0 : 1);
